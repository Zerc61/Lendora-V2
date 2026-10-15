<?php

namespace App\Services\Lending;

use App\Models\Asset;
use App\Models\AssetConditionRecord;
use App\Models\Loan;
use App\Models\OrganizationPolicy;
use App\Models\User;
use App\Services\Attachments\AttachmentStore;
use App\Services\Audit\AuditRecorder;
use App\Services\Notifications\NotificationDispatcher;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Loop operasional loan: serah terima (checkout), pengembalian (checkin), dan
 * inspeksi pasca-kembali.
 *
 * Checkout dan checkin berjalan dalam satu transaksi (Database Plan G). Setiap
 * pemeriksaan menulis satu {@see AssetConditionRecord} append-only dan
 * menyinkronkan cache `assets.condition` (C-1). Aset tidak langsung kembali ke
 * `active` setelah dikembalikan; ia menunggu inspeksi (ADR-007).
 */
class OperationsService
{
    public function __construct(
        private OrganizationContext $context,
        private AttachmentStore $attachments,
        private NotificationDispatcher $notifications,
    ) {}

    /**
     * Serah terima aset. Kondisi buruk menahan checkout; kondisi tidak bisa
     * dipakai membatalkan checkout dan mengirim aset ke perbaikan (D.1).
     *
     * @param  list<array{asset_id: int, condition: string, notes?: ?string, photos?: list<UploadedFile>}>  $items
     */
    public function checkout(Loan $loan, User $actor, array $items, AuditRecorder $audit): Loan
    {
        $this->assertItemsMatchLoan($loan, $items);

        $blocked = [];

        foreach ($items as $item) {
            if ($item['condition'] === Asset::CONDITION_UNSERVICEABLE) {
                $blocked[] = $item;
            } elseif ($item['condition'] === Asset::CONDITION_POOR) {
                throw ValidationException::withMessages([
                    'items' => 'Ada aset berkondisi buruk; serah terima ditahan untuk pemeriksaan.',
                ]);
            }
        }

        if ($blocked !== []) {
            foreach ($blocked as $item) {
                $this->sendToMaintenance($loan, $actor, $item, $audit, AssetConditionRecord::SOURCE_CHECKOUT);
            }

            throw ValidationException::withMessages([
                'items' => 'Ada aset yang tidak bisa dipakai; serah terima dibatalkan dan aset masuk perbaikan.',
            ]);
        }

        $checkedOut = DB::transaction(function () use ($loan, $actor, $items, $audit): Loan {
            $locked = Loan::query()->lockForUpdate()->findOrFail($loan->getKey());
            $this->assertTransition($locked, Loan::STATUS_CHECKED_OUT);

            $this->assertPickupOpen($locked);

            $assetIds = $locked->items()->pluck('asset_id')->all();
            $assets = Asset::query()->whereIn('id', $assetIds)->lockForUpdate()->get()->keyBy('id');

            $before = ['status' => $locked->status];

            foreach ($items as $item) {
                $asset = $assets->get((int) $item['asset_id']);

                if ($asset === null || ! $asset->isBorrowable()) {
                    throw ValidationException::withMessages([
                        'items' => 'Aset tidak siap diserahkan karena tidak berstatus tersedia.',
                    ]);
                }

                $this->recordCondition($asset, $item, AssetConditionRecord::SOURCE_CHECKOUT, $actor, $locked->getKey());
            }

            $locked->status = Loan::STATUS_CHECKED_OUT;
            $locked->checked_out_at = now();
            $locked->checked_out_by = $actor->getKey();
            $locked->due_at ??= $locked->ends_at;
            $locked->save();

            $audit->record(
                action: 'loan.checked_out',
                subject: $locked,
                before: $before,
                after: $this->auditable($locked),
                organization: $locked->organization,
            );

            return $locked->load(['items.asset', 'borrower', 'conditionRecords.attachments']);
        });

        // Kirim setelah commit: peminjam tahu aset sudah diserahkan.
        $this->notifications->loanCheckedOut($checkedOut);

        return $checkedOut;
    }

    /**
     * Pengembalian aset. Loan menjadi `returned`; setiap aset masuk
     * `awaiting_inspection` (ADR-007, CS-03). Tidak ada pengembalian sebagian (OD-10).
     *
     * @param  list<array{asset_id: int, condition: string, notes?: ?string, photos?: list<UploadedFile>}>  $items
     */
    public function checkin(Loan $loan, User $actor, array $items, AuditRecorder $audit): Loan
    {
        $this->assertItemsMatchLoan($loan, $items);

        $returned = DB::transaction(function () use ($loan, $actor, $items, $audit): Loan {
            $locked = Loan::query()->lockForUpdate()->findOrFail($loan->getKey());
            $this->assertTransition($locked, Loan::STATUS_RETURNED);

            $assetIds = $locked->items()->pluck('asset_id')->all();
            $assets = Asset::query()->whereIn('id', $assetIds)->lockForUpdate()->get()->keyBy('id');

            $before = ['status' => $locked->status];

            foreach ($items as $item) {
                $asset = $assets->get((int) $item['asset_id']);

                if ($asset === null) {
                    throw ValidationException::withMessages([
                        'items' => 'Aset tidak termasuk dalam loan ini.',
                    ]);
                }

                $asset->lifecycle_status = Asset::LIFECYCLE_AWAITING_INSPECTION;
                $this->recordCondition($asset, $item, AssetConditionRecord::SOURCE_CHECKIN, $actor, $locked->getKey());
            }

            $locked->status = Loan::STATUS_RETURNED;
            $locked->returned_at = now();
            $locked->returned_by = $actor->getKey();
            $locked->is_late = $locked->due_at !== null && now()->greaterThan($locked->due_at);
            $locked->save();

            $audit->record(
                action: 'loan.returned',
                subject: $locked,
                before: $before,
                after: $this->auditable($locked),
                organization: $locked->organization,
            );

            return $locked->load(['items.asset', 'borrower', 'conditionRecords.attachments']);
        });

        // Kirim setelah commit: operator/admin diminta memeriksa aset, dan
        // peminjam tahu pengembalian atas namanya sudah dikonfirmasi petugas.
        $this->notifications->loanReturned($returned, $actor);
        $this->notifications->loanReturnConfirmed($returned, $actor);

        return $returned;
    }

    /**
     * Selesaikan pemeriksaan aset yang menunggu. Kondisi buruk atau tidak bisa
     * dipakai menahan aset di perbaikan (OD-17, default konservatif).
     */
    public function inspect(Asset $asset, User $actor, ?string $notes, AuditRecorder $audit): Asset
    {
        return DB::transaction(function () use ($asset, $actor, $notes, $audit): Asset {
            $locked = Asset::query()->lockForUpdate()->findOrFail($asset->getKey());

            if ($locked->lifecycle_status !== Asset::LIFECYCLE_AWAITING_INSPECTION) {
                throw ValidationException::withMessages([
                    'status' => 'Aset tidak sedang menunggu pemeriksaan.',
                ]);
            }

            $latest = $locked->conditionRecords()
                ->orderByDesc('inspected_at')
                ->orderByDesc('id')
                ->first();

            $condition = $latest?->condition ?? $locked->condition;
            $blocked = in_array($condition, AssetConditionRecord::BLOCKING_CONDITIONS, true);

            $before = ['lifecycle_status' => $locked->lifecycle_status];
            $locked->lifecycle_status = $blocked
                ? Asset::LIFECYCLE_UNDER_MAINTENANCE
                : Asset::LIFECYCLE_ACTIVE;
            $locked->save();

            $audit->record(
                action: 'asset.inspected',
                subject: $locked,
                before: $before,
                after: [
                    'lifecycle_status' => $locked->lifecycle_status,
                    'condition' => $condition,
                    'notes' => $notes,
                ],
                context: ['actor_id' => $actor->getKey()],
                organization: $locked->organization,
            );

            return $locked->load(['conditionRecords.attachments']);
        });
    }

    /**
     * Pindahkan aset ke `under_maintenance` dan catat kondisinya, di luar
     * transaksi checkout yang dibatalkan supaya perubahan tetap tersimpan.
     *
     * @param  array{asset_id: int, condition: string, notes?: ?string, photos?: list<UploadedFile>}  $item
     */
    private function sendToMaintenance(Loan $loan, User $actor, array $item, AuditRecorder $audit, string $source): void
    {
        DB::transaction(function () use ($loan, $actor, $item, $audit, $source): void {
            $asset = Asset::query()->lockForUpdate()->findOrFail((int) $item['asset_id']);
            $asset->lifecycle_status = Asset::LIFECYCLE_UNDER_MAINTENANCE;

            $this->recordCondition($asset, $item, $source, $actor, $loan->getKey());

            $audit->record(
                action: 'asset.maintenance_required',
                subject: $asset,
                after: [
                    'lifecycle_status' => $asset->lifecycle_status,
                    'condition' => $asset->condition,
                    'loan_id' => $loan->getKey(),
                ],
                context: ['actor_id' => $actor->getKey()],
                organization: $asset->organization,
            );
        });
    }

    /**
     * Tulis satu condition record dan sinkronkan cache `assets.condition` (C-1).
     *
     * @param  array{condition: string, notes?: ?string, checklist_result?: mixed, photos?: list<UploadedFile>}  $item
     */
    private function recordCondition(Asset $asset, array $item, string $source, User $actor, ?int $loanId): AssetConditionRecord
    {
        $record = AssetConditionRecord::query()->create([
            'asset_id' => $asset->getKey(),
            'condition' => $item['condition'],
            'source' => $source,
            'notes' => $item['notes'] ?? null,
            'checklist_result' => $item['checklist_result'] ?? null,
            'inspected_by' => $actor->getKey(),
            'inspected_at' => now(),
            'loan_id' => $loanId,
        ]);

        $asset->condition = $item['condition'];
        $asset->save();

        $this->attachments->storePhotos($record, $item['photos'] ?? []);

        return $record;
    }

    /**
     * @param  list<array{asset_id: int, condition: string}>  $items
     */
    private function assertItemsMatchLoan(Loan $loan, array $items): void
    {
        $loanAssetIds = $loan->items()
            ->pluck('asset_id')
            ->map(fn ($id): int => (int) $id)
            ->sort()
            ->values()
            ->all();

        $given = collect($items)
            ->pluck('asset_id')
            ->map(fn ($id): int => (int) $id)
            ->sort()
            ->values()
            ->all();

        if ($loanAssetIds !== $given) {
            throw ValidationException::withMessages([
                'items' => 'Daftar aset harus sama persis dengan isi loan.',
            ]);
        }
    }

    private function assertPickupOpen(Loan $loan): void
    {
        $policy = OrganizationPolicy::resolve($loan->organization_id);

        if ($loan->isExpired($policy->pickup_window_hours)) {
            throw ValidationException::withMessages([
                'status' => 'Jendela pengambilan sudah lewat; loan kedaluwarsa.',
            ]);
        }

        if ($loan->ends_at !== null && now()->greaterThanOrEqualTo($loan->ends_at)) {
            throw ValidationException::withMessages([
                'starts_at' => 'Periode pinjam sudah berakhir; aset tidak bisa diserahkan.',
            ]);
        }
    }

    private function assertTransition(Loan $loan, string $to): void
    {
        if (! $loan->canTransitionTo($to)) {
            throw ValidationException::withMessages([
                'status' => "Transisi dari {$loan->status} ke {$to} tidak diizinkan.",
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function auditable(Loan $loan): array
    {
        return $loan->only([
            'status',
            'checked_out_at',
            'returned_at',
            'due_at',
            'is_late',
        ]);
    }
}
