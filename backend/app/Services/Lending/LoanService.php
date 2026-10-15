<?php

namespace App\Services\Lending;

use App\Models\Asset;
use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\LoanReservation;
use App\Models\OrganizationPolicy;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Notifications\NotificationDispatcher;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Orkestrasi aggregate Loan. Semua transisi berjalan di dalam satu transaksi
 * (Database Plan G). Validasi availability diulang di dalam transaksi dengan row
 * lock; exclusion constraint database menjadi jaring terakhir (CS-01).
 */
class LoanService
{
    /** PostgreSQL SQLSTATE untuk exclusion_violation. */
    private const SQLSTATE_EXCLUSION_VIOLATION = '23P01';

    public function __construct(
        private AvailabilityService $availability,
        private OrganizationContext $context,
        private NotificationDispatcher $notifications,
    ) {}

    /**
     * @param  array{starts_at: Carbon, ends_at: Carbon, purpose?: ?string}  $attributes
     * @param  list<int>  $assetIds
     */
    public function create(User $borrower, array $attributes, array $assetIds, AuditRecorder $audit): Loan
    {
        $result = $this->availability->evaluate(
            $assetIds,
            $attributes['starts_at'],
            $attributes['ends_at'],
            $borrower,
        );

        if (! $result['requestable']) {
            throw ValidationException::withMessages($this->errorsFrom($result));
        }

        $loan = DB::transaction(function () use ($borrower, $attributes, $assetIds, $audit): Loan {
            $loan = new Loan([
                'organization_id' => $this->context->id(),
                'borrower_id' => $borrower->getKey(),
                'status' => Loan::STATUS_REQUESTED,
                'starts_at' => $attributes['starts_at'],
                'ends_at' => $attributes['ends_at'],
                'purpose' => $attributes['purpose'] ?? null,
            ]);
            $loan->save();

            foreach ($assetIds as $assetId) {
                LoanItem::query()->create([
                    'loan_id' => $loan->getKey(),
                    'asset_id' => $assetId,
                ]);
            }

            $audit->record(
                action: 'loan.requested',
                subject: $loan,
                after: $this->auditable($loan),
                organization: $loan->organization,
            );

            return $loan->load(['items.asset', 'borrower']);
        });

        // Kirim setelah commit: kegagalan notifikasi tidak membatalkan pengajuan.
        $this->notifications->loanRequested($loan, $borrower);

        return $loan;
    }

    public function approve(Loan $loan, User $actor, AuditRecorder $audit): Loan
    {
        $approved = $this->guardConstraint(function () use ($loan, $actor, $audit): Loan {
            return DB::transaction(function () use ($loan, $actor, $audit): Loan {
                $locked = Loan::query()->lockForUpdate()->findOrFail($loan->getKey());
                $this->assertTransition($locked, Loan::STATUS_APPROVED);

                $assetIds = $locked->items()->pluck('asset_id')->all();
                $policy = OrganizationPolicy::resolve($locked->organization_id);
                $cutoff = now()->subHours($policy->pickup_window_hours);

                // Lepaskan kunci loan yang sudah kedaluwarsa (turunan) supaya periode
                // ini bebas. Status loan tetap `approved`; kedaluwarsa dihitung saat baca.
                $this->releaseExpiredReservations($assetIds, $cutoff);

                // Kunci baris aset, lalu hitung ulang konflik di dalam transaksi.
                Asset::query()->whereIn('id', $assetIds)->lockForUpdate()->get();

                $result = $this->availability->evaluate(
                    $assetIds,
                    $locked->starts_at,
                    $locked->ends_at,
                    null,
                );

                $conflict = collect($result['assets'])->contains(fn (array $asset): bool => ! $asset['available']);

                if ($conflict) {
                    throw ValidationException::withMessages([
                        'assets' => 'Aset ini sudah dipesan untuk periode itu.',
                    ]);
                }

                $before = ['status' => $locked->status];

                $locked->status = Loan::STATUS_APPROVED;
                $locked->approved_by = $actor->getKey();
                $locked->approved_at = now();
                $locked->due_at = $locked->ends_at;
                $locked->save();

                foreach ($assetIds as $assetId) {
                    LoanReservation::query()->create([
                        'organization_id' => $locked->organization_id,
                        'loan_id' => $locked->getKey(),
                        'asset_id' => $assetId,
                        'status' => Loan::STATUS_APPROVED,
                        'starts_at' => $locked->starts_at,
                        'ends_at' => $locked->ends_at,
                    ]);
                }

                $audit->record(
                    action: 'loan.approved',
                    subject: $locked,
                    before: $before,
                    after: $this->auditable($locked),
                    organization: $locked->organization,
                );

                return $locked->load(['items.asset', 'borrower']);
            });
        });

        // Kirim setelah commit: pengajuan tetap disetujui walau notifikasi gagal.
        $this->notifications->loanApproved($approved);

        return $approved;
    }

    public function reject(Loan $loan, User $actor, string $reason, AuditRecorder $audit): Loan
    {
        $rejected = DB::transaction(function () use ($loan, $reason, $audit): Loan {
            $locked = Loan::query()->lockForUpdate()->findOrFail($loan->getKey());
            $this->assertTransition($locked, Loan::STATUS_REJECTED);

            $before = ['status' => $locked->status];

            $locked->status = Loan::STATUS_REJECTED;
            $locked->rejection_reason = $reason;
            $locked->save();

            $audit->record(
                action: 'loan.rejected',
                subject: $locked,
                before: $before,
                after: $this->auditable($locked),
                context: ['reason' => $reason],
                organization: $locked->organization,
            );

            return $locked->load(['items.asset', 'borrower']);
        });

        // Kirim setelah commit.
        $this->notifications->loanRejected($rejected);

        return $rejected;
    }

    public function cancel(Loan $loan, User $actor, ?string $reason, AuditRecorder $audit): Loan
    {
        $cancelled = DB::transaction(function () use ($loan, $actor, $reason, $audit): Loan {
            $locked = Loan::query()->lockForUpdate()->findOrFail($loan->getKey());
            $this->assertTransition($locked, Loan::STATUS_CANCELLED);

            $before = ['status' => $locked->status];

            $locked->status = Loan::STATUS_CANCELLED;
            $locked->cancellation_reason = $reason;
            $locked->save();

            // Aset dilepas: baris reservasi dihapus, periode bisa diminta orang lain.
            LoanReservation::query()->where('loan_id', $locked->getKey())->delete();

            $audit->record(
                action: 'loan.cancelled',
                subject: $locked,
                before: $before,
                after: $this->auditable($locked),
                context: ['reason' => $reason, 'actor_id' => $actor->getKey()],
                organization: $locked->organization,
            );

            return $locked->load(['items.asset', 'borrower']);
        });

        // Kirim setelah commit: pihak yang tidak membatalkan yang diberi tahu.
        $this->notifications->loanCancelled($cancelled, $actor);

        return $cancelled;
    }

    /**
     * @param  list<int>  $assetIds
     */
    private function releaseExpiredReservations(array $assetIds, Carbon $cutoff): void
    {
        LoanReservation::query()
            ->whereIn('asset_id', $assetIds)
            ->where('status', Loan::STATUS_APPROVED)
            ->whereHas('loan', fn ($loan) => $loan->where('approved_at', '<', $cutoff))
            ->delete();
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
     * @param  array<string, mixed>  $result
     * @return array<string, string>
     */
    private function errorsFrom(array $result): array
    {
        $errors = [];

        foreach ($result['reasons'] as $index => $reason) {
            $errors['starts_at'.($index > 0 ? '.'.$index : '')] = $reason;
        }

        foreach ($result['assets'] as $asset) {
            if (! $asset['available']) {
                $key = $asset['code'] !== null ? 'assets.'.$asset['code'] : 'assets';
                $errors[$key] = ($asset['code'] !== null ? $asset['code'].': ' : '').$asset['reason'];
            }
        }

        return $errors === [] ? ['starts_at' => 'Periode tidak tersedia.'] : $errors;
    }

    /**
     * @return array<string, mixed>
     */
    private function auditable(Loan $loan): array
    {
        return $loan->only([
            'status',
            'starts_at',
            'ends_at',
            'due_at',
            'approved_at',
            'rejection_reason',
            'cancellation_reason',
        ]);
    }

    private function guardConstraint(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (QueryException $exception) {
            if ($this->isExclusionViolation($exception)) {
                throw ValidationException::withMessages([
                    'assets' => 'Aset ini sudah dipesan untuk periode itu.',
                ]);
            }

            throw $exception;
        }
    }

    private function isExclusionViolation(QueryException $exception): bool
    {
        return ($exception->errorInfo[0] ?? null) === self::SQLSTATE_EXCLUSION_VIOLATION;
    }
}
