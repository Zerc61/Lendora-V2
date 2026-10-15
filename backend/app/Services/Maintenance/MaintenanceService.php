<?php

namespace App\Services\Maintenance;

use App\Models\Asset;
use App\Models\AssetConditionRecord;
use App\Models\Issue;
use App\Models\MaintenanceLog;
use App\Models\MaintenanceTicket;
use App\Models\User;
use App\Services\Attachments\AttachmentStore;
use App\Services\Audit\AuditRecorder;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Orkestrasi MaintenanceTicket (Issue & Maintenance C, G).
 *
 * Setiap perubahan state berjalan dalam satu transaksi, menambah satu
 * {@see MaintenanceLog} append-only (M-7), dan menyinkronkan lifecycle aset.
 * Verifikasi adalah gerbang terakhir: hanya pemeriksaan yang lulus yang boleh
 * mengembalikan aset ke `active` (CS-06).
 */
class MaintenanceService
{
    public function __construct(
        private OrganizationContext $context,
        private AttachmentStore $attachments,
        private IssueService $issues,
    ) {}

    /**
     * @param  array{issue_id?: ?int, priority?: ?string, scheduled_start?: ?string, scheduled_end?: ?string, estimated_cost?: float|string|null, description: string}  $data
     */
    public function create(User $actor, Asset $asset, array $data, AuditRecorder $audit): MaintenanceTicket
    {
        if ($asset->isRetired()) {
            throw ValidationException::withMessages([
                'asset_id' => 'Aset ini sudah tidak dipakai lagi.',
            ]);
        }

        return DB::transaction(function () use ($actor, $asset, $data, $audit): MaintenanceTicket {
            $locked = Asset::query()->lockForUpdate()->findOrFail($asset->getKey());

            // M-2: satu aset hanya boleh punya satu ticket yang mengunci.
            $blocking = MaintenanceTicket::query()
                ->where('asset_id', $locked->getKey())
                ->whereIn('status', MaintenanceTicket::BLOCKING_STATUSES)
                ->exists();

            if ($blocking) {
                throw ValidationException::withMessages([
                    'asset_id' => 'Aset ini masih punya tiket perbaikan yang belum selesai.',
                ]);
            }

            $issue = null;

            if (! empty($data['issue_id'])) {
                $issue = Issue::query()->findOrFail((int) $data['issue_id']);

                if ((int) $issue->asset_id !== $locked->getKey()) {
                    throw ValidationException::withMessages([
                        'issue_id' => 'Issue tidak terkait dengan aset ini.',
                    ]);
                }
            }

            $ticket = MaintenanceTicket::query()->create([
                'organization_id' => $locked->organization_id,
                'asset_id' => $locked->getKey(),
                'issue_id' => $issue?->getKey(),
                'status' => MaintenanceTicket::STATUS_OPEN,
                'priority' => $data['priority'] ?? $issue?->severity ?? Issue::SEVERITY_MEDIUM,
                'scheduled_start' => $data['scheduled_start'] ?? null,
                'scheduled_end' => $data['scheduled_end'] ?? null,
                'description' => $data['description'],
                'estimated_cost' => $data['estimated_cost'] ?? null,
            ]);

            $locked->lifecycle_status = Asset::LIFECYCLE_UNDER_MAINTENANCE;
            $locked->save();

            $this->log($ticket, $actor, MaintenanceLog::ACTION_CREATED, $ticket->description);

            $audit->record(
                action: 'maintenance.created',
                subject: $ticket,
                after: $this->auditable($ticket) + [
                    'asset_lifecycle_status' => $locked->lifecycle_status,
                ],
                organization: $locked->organization,
            );

            return $ticket->load(['asset', 'issue', 'assignee', 'logs.actor']);
        });
    }

    /**
     * Tugaskan teknisi (open -> assigned).
     */
    public function assign(MaintenanceTicket $ticket, User $actor, ?int $assigneeId, AuditRecorder $audit): MaintenanceTicket
    {
        return DB::transaction(function () use ($ticket, $actor, $assigneeId, $audit): MaintenanceTicket {
            $locked = MaintenanceTicket::query()->lockForUpdate()->findOrFail($ticket->getKey());
            $this->assertTransition($locked, MaintenanceTicket::STATUS_ASSIGNED);

            $before = $this->auditable($locked);

            if ($assigneeId !== null) {
                $locked->assigned_to = $assigneeId;
            }

            $locked->status = MaintenanceTicket::STATUS_ASSIGNED;
            $locked->save();

            $this->log($locked, $actor, MaintenanceLog::ACTION_ASSIGNED);

            $audit->record(
                action: 'maintenance.assigned',
                subject: $locked,
                before: $before,
                after: $this->auditable($locked),
                organization: $locked->organization,
            );

            return $locked->load(['asset', 'issue', 'assignee', 'logs.actor']);
        });
    }

    /**
     * Mulai pengerjaan (assigned -> in_progress).
     */
    public function start(MaintenanceTicket $ticket, User $actor, AuditRecorder $audit): MaintenanceTicket
    {
        return DB::transaction(function () use ($ticket, $actor, $audit): MaintenanceTicket {
            $locked = MaintenanceTicket::query()->lockForUpdate()->findOrFail($ticket->getKey());
            $this->assertTransition($locked, MaintenanceTicket::STATUS_IN_PROGRESS);

            $before = $this->auditable($locked);
            $locked->status = MaintenanceTicket::STATUS_IN_PROGRESS;
            $locked->save();

            $this->log($locked, $actor, MaintenanceLog::ACTION_STARTED);

            $audit->record(
                action: 'maintenance.started',
                subject: $locked,
                before: $before,
                after: $this->auditable($locked),
                organization: $locked->organization,
            );

            return $locked->load(['asset', 'issue', 'assignee', 'logs.actor']);
        });
    }

    /**
     * Tahan pekerjaan menunggu komponen (in_progress -> awaiting_parts).
     */
    public function awaitParts(MaintenanceTicket $ticket, User $actor, ?string $note, AuditRecorder $audit): MaintenanceTicket
    {
        return DB::transaction(function () use ($ticket, $actor, $note, $audit): MaintenanceTicket {
            $locked = MaintenanceTicket::query()->lockForUpdate()->findOrFail($ticket->getKey());
            $this->assertTransition($locked, MaintenanceTicket::STATUS_AWAITING_PARTS);

            $before = $this->auditable($locked);
            $locked->status = MaintenanceTicket::STATUS_AWAITING_PARTS;
            $locked->save();

            $this->log($locked, $actor, MaintenanceLog::ACTION_AWAITING_PARTS, $note);

            $audit->record(
                action: 'maintenance.awaiting_parts',
                subject: $locked,
                before: $before,
                after: $this->auditable($locked),
                organization: $locked->organization,
            );

            return $locked->load(['asset', 'issue', 'assignee', 'logs.actor']);
        });
    }

    /**
     * Selesai dikerjakan, menunggu verifikasi (in_progress -> awaiting_verification).
     * Aset berpindah ke `awaiting_inspection` (Issue & Maintenance C).
     */
    public function complete(MaintenanceTicket $ticket, User $actor, ?string $note, AuditRecorder $audit): MaintenanceTicket
    {
        return DB::transaction(function () use ($ticket, $actor, $note, $audit): MaintenanceTicket {
            $locked = MaintenanceTicket::query()->lockForUpdate()->findOrFail($ticket->getKey());
            $this->assertTransition($locked, MaintenanceTicket::STATUS_AWAITING_VERIFICATION);

            $asset = Asset::query()->lockForUpdate()->findOrFail($locked->asset_id);

            $before = $this->auditable($locked);
            $locked->status = MaintenanceTicket::STATUS_AWAITING_VERIFICATION;
            $locked->save();

            $asset->lifecycle_status = Asset::LIFECYCLE_AWAITING_INSPECTION;
            $asset->save();

            $this->log($locked, $actor, MaintenanceLog::ACTION_COMPLETED, $note);

            $audit->record(
                action: 'maintenance.completed',
                subject: $locked,
                before: $before,
                after: $this->auditable($locked) + [
                    'asset_lifecycle_status' => $asset->lifecycle_status,
                ],
                organization: $asset->organization,
            );

            return $locked->load(['asset', 'issue', 'assignee', 'logs.actor']);
        });
    }

    /**
     * Verifikasi hasil perbaikan (awaiting_verification -> resolved | in_progress).
     *
     * Kondisi buruk atau tidak bisa dipakai berarti perbaikan belum tuntas:
     * ticket kembali ke `in_progress` dan aset tetap terkunci (CS-06). Kondisi
     * layak menutup ticket dan mengembalikan aset ke `active` (hanya setelah
     * verifikasi, sesuai critical scenario).
     *
     * @param  array{condition: string, notes?: ?string, photos?: list<UploadedFile>}  $result
     */
    public function verify(MaintenanceTicket $ticket, User $actor, array $result, AuditRecorder $audit): MaintenanceTicket
    {
        return DB::transaction(function () use ($ticket, $actor, $result, $audit): MaintenanceTicket {
            $locked = MaintenanceTicket::query()->lockForUpdate()->findOrFail($ticket->getKey());
            $this->assertTransition($locked, MaintenanceTicket::STATUS_RESOLVED);

            // Pemisahan tugas: pekerja tidak memverifikasi hasil kerjanya sendiri (F).
            if ($locked->assigned_to !== null && (int) $locked->assigned_to === $actor->getKey()) {
                throw ValidationException::withMessages([
                    'assigned_to' => 'Teknisi yang mengerjakan tidak boleh memverifikasi hasilnya sendiri.',
                ]);
            }

            $asset = Asset::query()->lockForUpdate()->findOrFail($locked->asset_id);
            $condition = $result['condition'];
            $failed = in_array($condition, AssetConditionRecord::BLOCKING_CONDITIONS, true);

            // Jejak verifikasi append-only, tertaut ke ticket (Database Plan B.4).
            $record = AssetConditionRecord::query()->create([
                'organization_id' => $asset->organization_id,
                'asset_id' => $asset->getKey(),
                'condition' => $condition,
                'source' => AssetConditionRecord::SOURCE_MAINTENANCE,
                'notes' => $result['notes'] ?? null,
                'inspected_by' => $actor->getKey(),
                'inspected_at' => now(),
                'ticket_id' => $locked->getKey(),
            ]);

            $this->attachments->storePhotos($record, $result['photos'] ?? []);

            $before = $this->auditable($locked);
            $beforeLifecycle = $asset->lifecycle_status;

            $asset->condition = $condition;

            if ($failed) {
                $locked->status = MaintenanceTicket::STATUS_IN_PROGRESS;
                $asset->lifecycle_status = Asset::LIFECYCLE_UNDER_MAINTENANCE;
            } else {
                $locked->status = MaintenanceTicket::STATUS_RESOLVED;
                $locked->completed_at = now();
                $asset->lifecycle_status = Asset::LIFECYCLE_ACTIVE;
            }

            $asset->save();
            $locked->save();

            $this->log(
                $locked,
                $actor,
                $failed ? MaintenanceLog::ACTION_VERIFICATION_FAILED : MaintenanceLog::ACTION_VERIFIED,
                $result['notes'] ?? null,
            );

            $audit->record(
                action: $failed ? 'maintenance.verification_failed' : 'maintenance.verified',
                subject: $locked,
                before: $before,
                after: $this->auditable($locked) + [
                    'condition' => $condition,
                    'asset_lifecycle_before' => $beforeLifecycle,
                    'asset_lifecycle_after' => $asset->lifecycle_status,
                ],
                organization: $asset->organization,
            );

            if (! $failed && $locked->issue_id !== null) {
                $issue = Issue::query()->find($locked->issue_id);

                if ($issue !== null) {
                    $this->issues->closeForResolvedTicket($issue, $audit);
                }
            }

            return $locked->load([
                'asset',
                'issue',
                'assignee',
                'logs.actor',
                'conditionRecords.attachments',
            ]);
        });
    }

    /**
     * Batalkan ticket (state blocking -> cancelled). Alasan wajib (M-3). Aset
     * kembali tersedia, kecuali kondisinya masih buruk sehingga menunggu inspeksi.
     */
    public function cancel(MaintenanceTicket $ticket, User $actor, string $reason, AuditRecorder $audit): MaintenanceTicket
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => 'Alasan pembatalan wajib diisi.',
            ]);
        }

        return DB::transaction(function () use ($ticket, $actor, $reason, $audit): MaintenanceTicket {
            $locked = MaintenanceTicket::query()->lockForUpdate()->findOrFail($ticket->getKey());
            $this->assertTransition($locked, MaintenanceTicket::STATUS_CANCELLED);

            $asset = Asset::query()->lockForUpdate()->findOrFail($locked->asset_id);

            $before = $this->auditable($locked);
            $locked->status = MaintenanceTicket::STATUS_CANCELLED;
            $locked->save();

            $blocked = in_array($asset->condition, AssetConditionRecord::BLOCKING_CONDITIONS, true);
            $asset->lifecycle_status = $blocked
                ? Asset::LIFECYCLE_AWAITING_INSPECTION
                : Asset::LIFECYCLE_ACTIVE;
            $asset->save();

            $this->log($locked, $actor, MaintenanceLog::ACTION_CANCELLED, $reason);

            $audit->record(
                action: 'maintenance.cancelled',
                subject: $locked,
                before: $before,
                after: $this->auditable($locked) + [
                    'asset_lifecycle_status' => $asset->lifecycle_status,
                ],
                organization: $asset->organization,
            );

            return $locked->load(['asset', 'issue', 'assignee', 'logs.actor']);
        });
    }

    private function log(MaintenanceTicket $ticket, User $actor, string $action, ?string $note = null): MaintenanceLog
    {
        return MaintenanceLog::query()->create([
            'ticket_id' => $ticket->getKey(),
            'actor_id' => $actor->getKey(),
            'action' => $action,
            'note' => $note,
        ]);
    }

    private function assertTransition(MaintenanceTicket $ticket, string $to): void
    {
        if (! $ticket->canTransitionTo($to)) {
            throw ValidationException::withMessages([
                'status' => "Transisi ticket dari {$ticket->status} ke {$to} tidak diizinkan.",
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function auditable(MaintenanceTicket $ticket): array
    {
        return $ticket->only([
            'status',
            'priority',
            'assigned_to',
            'estimated_cost',
            'actual_cost',
            'completed_at',
        ]);
    }
}
