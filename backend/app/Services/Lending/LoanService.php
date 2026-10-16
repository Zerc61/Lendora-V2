<?php

namespace App\Services\Lending;

use App\Models\Asset;
use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\LoanReservation;
use App\Models\OrganizationPolicy;
use App\Models\Payment;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Audit\AuditRecorder;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Payments\PaymentService;
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
        private PricingService $pricing,
        private OrganizationContext $context,
        private NotificationDispatcher $notifications,
        private PaymentService $payments,
    ) {}

    /**
     * @param  array{starts_at: Carbon, ends_at: Carbon, purpose?: ?string}  $attributes
     * @param  list<int>  $assetIds
     * @param  bool  $autoApprove  Langsung disetujui tanpa antrean saat kebijakan
     *                             provider tidak mewajibkan persetujuan.
     */
    public function create(User $borrower, array $attributes, array $assetIds, AuditRecorder $audit, bool $autoApprove = false): Loan
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

        // Harga dihitung dari tarif provider saat ini, lalu dibekukan pada loan.
        $quote = $this->pricing->quote(
            Asset::query()->whereIn('id', $assetIds)->orderBy('name')->get(),
            $attributes['starts_at'],
            $attributes['ends_at'],
        );

        $loan = $this->guardConstraint(fn (): Loan => DB::transaction(function () use ($borrower, $attributes, $assetIds, $audit, $quote, $autoApprove): Loan {
            $loan = new Loan([
                'organization_id' => $this->context->id(),
                'borrower_id' => $borrower->getKey(),
                'status' => Loan::STATUS_REQUESTED,
                'starts_at' => $attributes['starts_at'],
                'ends_at' => $attributes['ends_at'],
                'purpose' => $attributes['purpose'] ?? null,
                'days' => $quote['days'],
                'rate_snapshot' => $quote['rate_total'],
                'subtotal' => $quote['subtotal'],
                'deposit_snapshot' => $quote['deposit_total'],
                'currency' => $quote['currency'],
                'pricing_snapshot' => ['currency' => $quote['currency'], 'lines' => $quote['lines']],
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

            if ($autoApprove) {
                $this->applyApproval($loan, null, $audit, ['automatic' => true]);
            }

            return $loan->load(['items.asset', 'borrower']);
        }));

        // Kirim setelah commit: kegagalan notifikasi tidak membatalkan pengajuan.
        if ($autoApprove) {
            $this->notifications->loanApproved($loan);
        } else {
            $this->notifications->loanRequested($loan, $borrower);
        }

        return $loan;
    }

    public function approve(Loan $loan, ?User $actor, AuditRecorder $audit): Loan
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

                return $this->applyApproval($locked, $actor, $audit);
            });
        });

        // Kirim setelah commit: pengajuan tetap disetujui walau notifikasi gagal.
        $this->notifications->loanApproved($approved);

        return $approved;
    }

    /**
     * Menuliskan status disetujui plus baris reservasinya. Dipakai oleh
     * persetujuan manual dan oleh persetujuan otomatis saat kebijakan provider
     * tidak mewajibkan antrean.
     *
     * @param  array<string, mixed>  $context
     */
    private function applyApproval(Loan $loan, ?User $actor, AuditRecorder $audit, array $context = []): Loan
    {
        $before = ['status' => $loan->status];

        $loan->status = Loan::STATUS_APPROVED;
        $loan->approved_by = $actor?->getKey();
        $loan->approved_at = now();
        $loan->due_at = $loan->ends_at;
        $loan->save();

        foreach ($loan->items()->pluck('asset_id')->all() as $assetId) {
            LoanReservation::query()->create([
                'organization_id' => $loan->organization_id,
                'loan_id' => $loan->getKey(),
                'asset_id' => $assetId,
                'status' => Loan::STATUS_APPROVED,
                'starts_at' => $loan->starts_at,
                'ends_at' => $loan->ends_at,
            ]);
        }

        $audit->record(
            action: 'loan.approved',
            subject: $loan,
            before: $before,
            after: $this->auditable($loan),
            context: $context,
            organization: $loan->organization,
        );

        return $loan->load(['items.asset', 'borrower']);
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

            // Kembalikan saldo dompet bila booking dibatalkan setelah dibayar via dompet.
            $this->refundWalletPayment($locked, $actor, $audit);

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
     * Kembalikan saldo dompet bila loan dibatalkan setelah dibayar via dompet.
     * Membuat WalletTransaction tipe refund untuk audit trail.
     */
    private function refundWalletPayment(Loan $loan, User $actor, AuditRecorder $audit): void
    {
        $payment = $this->payments->paidFor($loan);

        if ($payment === null || $payment->payment_method !== Payment::METHOD_WALLET) {
            return;
        }

        $walletTx = $payment->walletTransaction;

        if ($walletTx === null || $walletTx->status !== WalletTransaction::STATUS_SUCCESS) {
            return;
        }

        $reference = 'REFUND-'.$payment->order_id;
        $user = $walletTx->user;

        DB::transaction(function () use ($user, $payment, $reference, $actor, $audit, $loan) {
            // Sekali per pembayaran: pengembalian ganda tidak boleh menambah
            // saldo dua kali (indeks unik `reference_id` adalah lapis keduanya).
            if (WalletTransaction::query()->where('reference_id', $reference)->exists()) {
                return;
            }

            $user->walletTransactions()->create([
                'amount' => $payment->amount,
                'type' => WalletTransaction::TYPE_REFUND,
                'reference_id' => $reference,
                'status' => WalletTransaction::STATUS_SUCCESS,
                'metadata' => ['payment_id' => $payment->getKey(), 'actor_id' => $actor->getKey()],
            ]);

            $user->increment('wallet_balance', $payment->amount);

            $audit->record(
                action: 'wallet.refunded',
                subject: $payment,
                after: [
                    'amount' => $payment->amount,
                    'reason' => 'loan_cancelled',
                ],
                organization: $loan->organization,
            );
        });
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
