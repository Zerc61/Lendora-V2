<?php

namespace App\Services\Maintenance;

use App\Models\Asset;
use App\Models\Issue;
use App\Models\MaintenanceTicket;
use App\Models\User;
use App\Services\Attachments\AttachmentStore;
use App\Services\Audit\AuditRecorder;
use App\Services\Notifications\NotificationDispatcher;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Orkestrasi Issue (Issue & Maintenance B).
 *
 * Issue adalah pengamatan, bukan pekerjaan. Membuat issue tidak otomatis membuat
 * ticket; efeknya ke aset hanya berupa perpindahan lifecycle sesuai severity.
 * Setiap transisi berjalan dalam satu transaksi dan dicatat ke audit.
 */
class IssueService
{
    /**
     * Peringkat ketat lifecycle aset. Laporan issue hanya boleh menaikkan status
     * (mis. aset yang sudah `under_maintenance` tidak turun ke `awaiting_inspection`).
     *
     * @var array<string, int>
     */
    private const LIFECYCLE_RANK = [
        Asset::LIFECYCLE_ACTIVE => 0,
        Asset::LIFECYCLE_AWAITING_INSPECTION => 1,
        Asset::LIFECYCLE_UNDER_MAINTENANCE => 2,
        Asset::LIFECYCLE_RETIRED => 3,
    ];

    public function __construct(
        private OrganizationContext $context,
        private AttachmentStore $attachments,
        private NotificationDispatcher $notifications,
    ) {}

    /**
     * @param  array{type: string, severity: string, description: string, loan_id?: ?int, photos?: list<UploadedFile>}  $data
     */
    public function create(User $reporter, Asset $asset, array $data, AuditRecorder $audit): Issue
    {
        if ($asset->isRetired()) {
            throw ValidationException::withMessages([
                'asset_id' => 'Aset ini sudah tidak dipakai lagi.',
            ]);
        }

        $photos = $data['photos'] ?? [];

        // M-4: severity kritis wajib disertai bukti.
        if ($data['severity'] === Issue::SEVERITY_CRITICAL && $photos === []) {
            throw ValidationException::withMessages([
                'photos' => 'Severity kritis wajib disertai minimal satu foto.',
            ]);
        }

        $issue = DB::transaction(function () use ($reporter, $asset, $data, $photos, $audit): Issue {
            $locked = Asset::query()->lockForUpdate()->findOrFail($asset->getKey());

            $issue = Issue::query()->create([
                'organization_id' => $locked->organization_id,
                'asset_id' => $locked->getKey(),
                'loan_id' => $data['loan_id'] ?? null,
                'type' => $data['type'],
                'severity' => $data['severity'],
                'status' => Issue::STATUS_OPEN,
                'description' => $data['description'],
                'reported_by' => $reporter->getKey(),
            ]);

            $this->attachments->storePhotos($issue, $photos);

            $this->applySeverityToAsset($locked, $issue->severity);

            $audit->record(
                action: 'issue.created',
                subject: $issue,
                after: $this->auditable($issue) + [
                    'asset_lifecycle_status' => $locked->lifecycle_status,
                ],
                organization: $locked->organization,
            );

            return $issue->load(['asset', 'reporter', 'assignee', 'photos']);
        });

        // Kirim setelah commit; hanya severity tinggi/kritis yang mengabari pengelola.
        $this->notifications->issueReported($issue, $reporter);

        return $issue;
    }

    /**
     * Operator meninjau laporan (open -> reviewing) dan mengambil tanggung jawab.
     */
    public function review(Issue $issue, User $actor, AuditRecorder $audit): Issue
    {
        return DB::transaction(function () use ($issue, $actor, $audit): Issue {
            $locked = Issue::query()->lockForUpdate()->findOrFail($issue->getKey());
            $this->assertTransition($locked, Issue::STATUS_REVIEWING);

            $before = $this->auditable($locked);
            $locked->status = Issue::STATUS_REVIEWING;
            $locked->assigned_to ??= $actor->getKey();
            $locked->save();

            $audit->record(
                action: 'issue.reviewing',
                subject: $locked,
                before: $before,
                after: $this->auditable($locked),
                organization: $locked->organization,
            );

            return $locked->load(['asset', 'reporter', 'assignee', 'photos']);
        });
    }

    /**
     * Tutup issue sebagai selesai (reviewing -> resolved). Wajib ada catatan
     * penyelesaian (M-6).
     */
    public function resolve(Issue $issue, User $actor, string $resolution, AuditRecorder $audit): Issue
    {
        if (trim($resolution) === '') {
            throw ValidationException::withMessages([
                'resolution' => 'Catatan penyelesaian wajib diisi.',
            ]);
        }

        return DB::transaction(function () use ($issue, $actor, $resolution, $audit): Issue {
            $locked = Issue::query()->lockForUpdate()->findOrFail($issue->getKey());
            $this->assertTransition($locked, Issue::STATUS_RESOLVED);

            $before = $this->auditable($locked);
            $locked->status = Issue::STATUS_RESOLVED;
            $locked->resolution = $resolution;
            $locked->resolved_by = $actor->getKey();
            $locked->resolved_at = now();
            $locked->save();

            $audit->record(
                action: 'issue.resolved',
                subject: $locked,
                before: $before,
                after: $this->auditable($locked),
                organization: $locked->organization,
            );

            return $locked->load(['asset', 'reporter', 'assignee', 'photos']);
        });
    }

    /**
     * Tandai laporan sebagai bukan masalah (open/reviewing -> dismissed).
     * Alasan wajib (T-3).
     */
    public function dismiss(Issue $issue, User $actor, string $reason, AuditRecorder $audit): Issue
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'resolution' => 'Alasan wajib diisi saat menolak laporan.',
            ]);
        }

        return DB::transaction(function () use ($issue, $actor, $reason, $audit): Issue {
            $locked = Issue::query()->lockForUpdate()->findOrFail($issue->getKey());
            $this->assertTransition($locked, Issue::STATUS_DISMISSED);

            $before = $this->auditable($locked);
            $locked->status = Issue::STATUS_DISMISSED;
            $locked->resolution = $reason;
            $locked->resolved_by = $actor->getKey();
            $locked->resolved_at = now();
            $locked->save();

            $audit->record(
                action: 'issue.dismissed',
                subject: $locked,
                before: $before,
                after: $this->auditable($locked),
                organization: $locked->organization,
            );

            return $locked->load(['asset', 'reporter', 'assignee', 'photos']);
        });
    }

    /**
     * Penutupan otomatis oleh sistem saat ticket terkait selesai dan tidak ada
     * ticket blocking lain untuk aset yang sama (Issue & Maintenance F). Ini
     * transisi sistem, di luar graf transisi manual.
     */
    public function closeForResolvedTicket(Issue $issue, AuditRecorder $audit): void
    {
        DB::transaction(function () use ($issue, $audit): void {
            $locked = Issue::query()->lockForUpdate()->findOrFail($issue->getKey());

            if ($locked->isTerminal()) {
                return;
            }

            $stillBlocking = MaintenanceTicket::query()
                ->where('asset_id', $locked->asset_id)
                ->whereIn('status', MaintenanceTicket::BLOCKING_STATUSES)
                ->exists();

            if ($stillBlocking) {
                return;
            }

            $before = $this->auditable($locked);
            $locked->status = Issue::STATUS_RESOLVED;
            $locked->resolution = 'Ditutup otomatis setelah perbaikan diverifikasi.';
            $locked->resolved_at = now();
            $locked->save();

            $audit->record(
                action: 'issue.resolved',
                subject: $locked,
                before: $before,
                after: $this->auditable($locked),
                context: ['system' => true],
                organization: $locked->organization,
            );
        });
    }

    /**
     * Terapkan efek severity ke lifecycle aset (Issue & Maintenance D). Hanya
     * menaikkan status; `low` tidak mengubah aset.
     */
    private function applySeverityToAsset(Asset $asset, string $severity): void
    {
        $target = Issue::SEVERITY_ASSET_LIFECYCLE[$severity] ?? null;

        if ($target === null) {
            return;
        }

        $currentRank = self::LIFECYCLE_RANK[$asset->lifecycle_status] ?? 0;

        if (self::LIFECYCLE_RANK[$target] > $currentRank) {
            $asset->lifecycle_status = $target;
            $asset->save();
        }
    }

    private function assertTransition(Issue $issue, string $to): void
    {
        if (! $issue->canTransitionTo($to)) {
            throw ValidationException::withMessages([
                'status' => "Transisi issue dari {$issue->status} ke {$to} tidak diizinkan.",
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function auditable(Issue $issue): array
    {
        return $issue->only([
            'status',
            'severity',
            'assigned_to',
            'resolution',
            'resolved_at',
        ]);
    }
}
