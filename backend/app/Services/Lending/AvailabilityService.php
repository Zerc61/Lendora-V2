<?php

namespace App\Services\Lending;

use App\Models\Asset;
use App\Models\Loan;
use App\Models\LoanReservation;
use App\Models\OrganizationPolicy;
use App\Models\User;
use App\Support\Tenancy\OrganizationContext;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Availability selalu dihitung, tidak pernah disimpan (D-1, ADR-002, ADR-003).
 *
 * Sumber kebenaran:
 * - `assets.lifecycle_status` dan soft delete,
 * - `loan_reservations` (lapis 2, OD-15/OD-25),
 * - `organization_policies`.
 *
 * Konflik maintenance belum dihitung: tabel `maintenance_tickets` baru ada di
 * Fase 8. Saat itu ditambahkan, kondisi 4 Availability Rules masuk ke sini.
 */
class AvailabilityService
{
    public function __construct(private OrganizationContext $context) {}

    /**
     * @return array<string, mixed>
     */
    public function evaluate(array $assetIds, CarbonInterface $start, CarbonInterface $end, ?User $borrower = null): array
    {
        $assetIds = array_values(array_unique(array_map('intval', $assetIds)));
        $policy = OrganizationPolicy::resolve($this->context->id());

        $assets = Asset::query()->whereIn('id', $assetIds)->get()->keyBy('id');

        $blocked = $this->blockedAssets($assetIds, $start, $end, $policy);

        $reasons = $this->policyViolations($borrower, $start, $end, $assetIds, $policy);

        $assetResults = [];

        foreach ($assetIds as $assetId) {
            $asset = $assets->get($assetId);
            $reason = null;

            if (! $asset instanceof Asset) {
                $reason = 'Aset tidak ditemukan.';
            } elseif ($asset->lifecycle_status !== Asset::LIFECYCLE_ACTIVE) {
                $reason = $this->lifecycleReason($asset);
            } elseif ($asset->daily_rate === null) {
                // PricingService menolak aset tanpa tarif, jadi ketersediaan harus
                // menyebutkannya di sini; kalau tidak, badge bilang "bisa dipinjam"
                // padahal pengajuan tidak akan pernah lolos.
                $reason = 'Penyedia belum menetapkan tarif harian untuk aset ini.';
            } elseif (isset($blocked[$assetId])) {
                $reason = 'Aset ini sudah dipesan untuk periode itu.';
            }

            $assetResults[] = [
                'id' => $assetId,
                'code' => $asset?->code,
                'name' => $asset?->name,
                'available' => $reason === null,
                'reason' => $reason,
            ];
        }

        return [
            'requestable' => $reasons === [] && collect($assetResults)->every(fn (array $a): bool => $a['available']),
            'reasons' => $reasons,
            'assets' => $assetResults,
        ];
    }

    /**
     * Periode yang memblokir satu aset, untuk kalender ketersediaan.
     *
     * @return Collection<int, LoanReservation>
     */
    public function blockingPeriods(int $assetId, CarbonInterface $from, CarbonInterface $to): Collection
    {
        $policy = OrganizationPolicy::resolve($this->context->id());
        $cutoff = $this->expiryCutoff($policy);

        return LoanReservation::query()
            ->with(['loan.borrower'])
            ->where('asset_id', $assetId)
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->where(function ($query) use ($cutoff): void {
                $query->where('status', Loan::STATUS_CHECKED_OUT)
                    ->orWhere(function ($query) use ($cutoff): void {
                        $query->where('status', Loan::STATUS_APPROVED)
                            ->whereHas('loan', fn ($loan) => $loan->where('approved_at', '>=', $cutoff));
                    });
            })
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * @param  list<int>  $assetIds
     * @return array<int, true>
     */
    private function blockedAssets(array $assetIds, CarbonInterface $start, CarbonInterface $end, OrganizationPolicy $policy): array
    {
        $cutoff = $this->expiryCutoff($policy);

        return LoanReservation::query()
            ->whereIn('asset_id', $assetIds)
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->where(function ($query) use ($cutoff): void {
                $query->where('status', Loan::STATUS_CHECKED_OUT)
                    ->orWhere(function ($query) use ($cutoff): void {
                        $query->where('status', Loan::STATUS_APPROVED)
                            ->whereHas('loan', fn ($loan) => $loan->where('approved_at', '>=', $cutoff));
                    });
            })
            ->pluck('asset_id')
            ->mapWithKeys(fn ($id): array => [(int) $id => true])
            ->all();
    }

    /**
     * Aturan kebijakan yang membuat periode ini tidak bisa diminta (Business Rules C,
     * Lending Lifecycle D.1).
     *
     * @param  list<int>  $assetIds
     * @return list<string>
     */
    private function policyViolations(?User $borrower, CarbonInterface $start, CarbonInterface $end, array $assetIds, OrganizationPolicy $policy): array
    {
        $reasons = [];
        $now = now();
        $durationHours = $start->diffInMinutes($end) / 60;

        if ($start->lessThanOrEqualTo($now)) {
            $reasons[] = 'Periode harus dimulai setelah waktu sekarang.';
        }

        if ($durationHours > $policy->max_loan_duration_days * 24) {
            $reasons[] = 'Durasi maksimum '.$policy->max_loan_duration_days.' hari.';
        }

        if ($durationHours < $policy->min_loan_duration_hours) {
            $reasons[] = 'Durasi minimum '.$policy->min_loan_duration_hours.' jam.';
        }

        if ($start->lessThan($now->copy()->addHours($policy->min_notice_hours))) {
            $reasons[] = 'Minimal '.$policy->min_notice_hours.' jam sebelum mulai.';
        }

        if ($start->greaterThan($now->copy()->addDays($policy->max_advance_days))) {
            $reasons[] = 'Maksimal '.$policy->max_advance_days.' hari ke depan.';
        }

        if ($borrower !== null) {
            $activeCount = Loan::query()
                ->where('borrower_id', $borrower->getKey())
                ->whereIn('status', Loan::BLOCKING_STATUSES)
                ->count();

            if ($activeCount >= $policy->max_active_loans) {
                $reasons[] = 'Kuota peminjaman aktif sudah penuh ('.$policy->max_active_loans.').';
            }

            $duplicate = Loan::query()
                ->where('borrower_id', $borrower->getKey())
                ->whereIn('status', [Loan::STATUS_REQUESTED, ...Loan::BLOCKING_STATUSES])
                ->whereHas('items', fn ($query) => $query->whereIn('asset_id', $assetIds))
                ->exists();

            if ($duplicate) {
                $reasons[] = 'Anda sudah punya peminjaman aktif atas salah satu aset ini.';
            }
        }

        return $reasons;
    }

    private function lifecycleReason(Asset $asset): string
    {
        return match ($asset->lifecycle_status) {
            Asset::LIFECYCLE_UNDER_MAINTENANCE => 'Aset sedang dalam perbaikan.',
            Asset::LIFECYCLE_AWAITING_INSPECTION => 'Aset menunggu pemeriksaan.',
            Asset::LIFECYCLE_RETIRED => 'Aset tidak dipakai lagi.',
            default => 'Aset sedang tidak bisa dipinjam.',
        };
    }

    private function expiryCutoff(OrganizationPolicy $policy): Carbon
    {
        return now()->subHours($policy->pickup_window_hours);
    }
}
