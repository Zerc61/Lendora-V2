<?php

namespace App\Services\Payments;

use App\Models\Loan;
use App\Models\OrganizationPolicy;
use App\Models\Payment;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Pembayaran booking (Tahap 4, rencana 3.1 sampai 3.3).
 *
 * Pembayaran hidup di sumbu terpisah: loan tetap `approved` saat lunas, dan
 * pengaruhnya ke operasional hanya satu, yaitu gerbang serah terima bila
 * kebijakan provider mewajibkan bayar di muka.
 */
class PaymentService
{
    /** Status notifikasi Midtrans yang berarti dana dikembalikan ke pembeli. */
    private const REFUND_STATUSES = ['refund', 'partial_refund', 'chargeback'];

    public function __construct(private MidtransClient $client) {}

    /**
     * Buat charge untuk booking yang sudah disetujui.
     *
     * Nominal selalu `subtotal` yang dibekukan saat customer memesan. Deposit
     * belum ditagih (keputusan 10).
     */
    public function charge(Loan $loan, User $actor, AuditRecorder $audit): Payment
    {
        if ($loan->status !== Loan::STATUS_APPROVED) {
            throw ValidationException::withMessages([
                'payment' => 'Pembayaran hanya bisa dimulai setelah pengajuan disetujui.',
            ]);
        }

        if (! $this->client->enabled()) {
            throw ValidationException::withMessages([
                'payment' => 'Pembayaran belum dikonfigurasi. Hubungi penyedia.',
            ]);
        }

        if ($this->paidFor($loan) !== null) {
            throw ValidationException::withMessages([
                'payment' => 'Pembayaran booking ini sudah lunas.',
            ]);
        }

        $expiresAt = $loan->pickupDeadline(
            OrganizationPolicy::resolve($loan->organization_id)->pickup_window_hours,
        );

        if ($expiresAt === null || $expiresAt->isPast()) {
            throw ValidationException::withMessages([
                'payment' => 'Jendela pengambilan sudah lewat. Hubungi penyedia.',
            ]);
        }

        $amount = (string) ($loan->subtotal ?? '0.00');
        $orderId = sprintf('LN-%d-%s', $loan->getKey(), Str::lower((string) Str::ulid()));

        $response = $this->client->charge([
            'transaction_details' => [
                'order_id' => $orderId,
                'gross_amount' => (int) round((float) $amount),
            ],
            'customer_details' => [
                'first_name' => $actor->name,
                'email' => $actor->email,
            ],
            // Batas bayar mengikuti jendela pengambilan, supaya Midtrans tidak
            // menahan transaksi lebih lama dari jatah waktu pemesanan.
            'expiry' => [
                'unit' => 'minutes',
                'duration' => max(1, (int) now()->diffInMinutes($expiresAt)),
            ],
            'callbacks' => [
                'finish' => (string) config('services.midtrans.finish_url'),
            ],
        ]);

        return DB::transaction(function () use ($loan, $orderId, $amount, $expiresAt, $response, $audit): Payment {
            $payment = Payment::query()->create([
                'organization_id' => $loan->organization_id,
                'loan_id' => $loan->getKey(),
                'order_id' => $orderId,
                'amount' => $amount,
                'status' => Payment::STATUS_PENDING,
                'expires_at' => $expiresAt,
                'payload' => $response,
            ]);

            $audit->record(
                action: 'payment.created',
                subject: $loan,
                after: [
                    'order_id' => $orderId,
                    'amount' => $amount,
                    'status' => Payment::STATUS_PENDING,
                ],
                organization: $loan->organization,
            );

            return $payment;
        });
    }

    /**
     * Buat charge dengan dompet untuk booking yang sudah disetujui.
     *
     * Saldo dikurangi atomik di dalam transaksi database; satu-satunya sumber
     * kebenaran saldo adalah kolom users.wallet_balance. Jika saldo tidak cukup,
     * dibatalkan dengan exception.
     */
    public function chargeWithWallet(Loan $loan, User $actor, AuditRecorder $audit): Payment
    {
        if ($loan->status !== Loan::STATUS_APPROVED) {
            throw ValidationException::withMessages([
                'payment' => 'Pembayaran hanya bisa dimulai setelah pengajuan disetujui.',
            ]);
        }

        if ($this->paidFor($loan) !== null) {
            throw ValidationException::withMessages([
                'payment' => 'Pembayaran booking ini sudah lunas.',
            ]);
        }

        $amount = (int) round((float) ($loan->subtotal ?? '0.00'));
        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'payment' => 'Booking ini tidak memiliki biaya.',
            ]);
        }

        $orderId = sprintf('WLLET-%d-%s', $loan->getKey(), Str::lower((string) Str::ulid()));

        return DB::transaction(function () use ($loan, $actor, $orderId, $amount, $audit): Payment {
            $user = $actor->newQuery()->lockForUpdate()->findOrFail($actor->getKey());

            if (! $user->isWalletSufficient($amount)) {
                throw ValidationException::withMessages([
                    'payment' => 'Saldo dompet tidak cukup. Isi dompet terlebih dahulu.',
                ]);
            }

            $walletTransaction = $user->walletTransactions()->create([
                'amount' => $amount,
                'type' => WalletTransaction::TYPE_DEDUCTION,
                'reference_id' => $orderId,
                'status' => WalletTransaction::STATUS_SUCCESS,
                'metadata' => ['loan_id' => $loan->getKey()],
            ]);

            $user->decrement('wallet_balance', $amount);

            $payment = Payment::query()->create([
                'organization_id' => $loan->organization_id,
                'loan_id' => $loan->getKey(),
                'order_id' => $orderId,
                'amount' => $amount,
                'status' => Payment::STATUS_PAID,
                'paid_at' => now(),
                'payload' => null,
                'payment_method' => Payment::METHOD_WALLET,
                'wallet_transaction_id' => $walletTransaction->getKey(),
            ]);

            $audit->record(
                action: 'payment.created',
                subject: $loan,
                after: [
                    'order_id' => $orderId,
                    'amount' => $amount,
                    'status' => Payment::STATUS_PAID,
                    'payment_method' => Payment::METHOD_WALLET,
                ],
                organization: $loan->organization,
            );

            return $payment;
        });
    }

    /**
     * Terapkan notifikasi Midtrans pada baris pembayaran.
     *
     * Idempoten: notifikasi yang sama datang dua kali tetap menghasilkan satu
     * baris `paid` (keputusan 4 dan 7).
     *
     * @param  array<string, mixed>  $payload
     */
    public function handleNotification(array $payload): ?Payment
    {
        if (! $this->client->verifySignature($payload)) {
            throw ValidationException::withMessages([
                'signature_key' => 'Tanda tangan notifikasi tidak sah.',
            ]);
        }

        return $this->applyPayload($payload);
    }

    /**
     * Terapkan payload status tanpa memeriksa tanda tangan.
     *
     * Dipakai rekonsiliasi: balasan `GET /v2/{order_id}/status` tidak
     * ditandatangani — yang menjaganya adalah kunci server di sisi kami — dan
     * jalurnya sama supaya aturan idempotensi tidak terduplikasi.
     *
     * @param  array<string, mixed>  $payload
     */
    public function applyPayload(array $payload): ?Payment
    {
        $payment = Payment::query()
            ->withoutOrganizationScope()
            ->where('order_id', (string) ($payload['order_id'] ?? ''))
            ->first();

        // Order yang tidak dikenal diabaikan: bisa jadi milik lingkungan lain.
        if ($payment === null) {
            return null;
        }

        $midtransStatus = (string) ($payload['transaction_status'] ?? '');

        // Nominal notifikasi wajib sama dengan nominal yang kita tagih: selisih
        // berarti payload ini milik order lain (mis. dari lingkungan lain).
        $this->assertAmountMatches($payment, $payload['gross_amount'] ?? null);

        // Pembayaran yang sudah lunas tidak boleh mundur lagi — kecuali dana
        // dikembalikan Midtrans, yang memang harus terlihat di sumbu pembayaran.
        if ($payment->isPaid() && ! in_array($midtransStatus, self::REFUND_STATUSES, true)) {
            $payment->update(['payload' => $payload]);

            return $payment;
        }

        $status = $this->mapStatus($midtransStatus, $payload['fraud_status'] ?? null);

        if ($status === Payment::STATUS_PAID && $this->hasOtherPaidPayment($payment)) {
            // Percobaan lain untuk booking ini sudah lunas. Baris ini tidak boleh
            // ikut `paid` (dijaga indeks unik parsial); uang yang masuk dua kali
            // perlu ditangani manual, sama seperti refund (keputusan 12).
            $payment->update(['status' => Payment::STATUS_FAILED, 'payload' => $payload]);

            return $payment;
        }

        $payment->update([
            'status' => $status,
            'paid_at' => $status === Payment::STATUS_PAID ? now() : null,
            'payload' => $payload,
        ]);

        // ponytail: kedaluwarsa tidak membatalkan loan. Pembatalan butuh aktor
        // user untuk audit, dan konsep "aktor sistem" belum ada; pelepasan stok
        // tetap lewat jalur jendela pengambilan yang sudah berjalan
        // (AvailabilityService + releaseExpiredReservations).

        return $payment;
    }

    /**
     * Pembayaran lunas untuk satu loan, kalau ada. Satu-satunya sumber
     * kebenaran "sudah dibayar", dipakai gerbang serah terima.
     */
    public function paidFor(Loan $loan): ?Payment
    {
        return Payment::query()
            ->withoutOrganizationScope()
            ->where('loan_id', $loan->getKey())
            ->where('status', Payment::STATUS_PAID)
            ->first();
    }

    private function hasOtherPaidPayment(Payment $payment): bool
    {
        return Payment::query()
            ->withoutOrganizationScope()
            ->where('loan_id', $payment->loan_id)
            ->where('status', Payment::STATUS_PAID)
            ->whereKeyNot($payment->getKey())
            ->exists();
    }

    /**
     * Nominal notifikasi wajib sama dengan nominal transaksi. Tanpa nominal
     * (mis. balasan status tanpa `gross_amount`), pemeriksaan dilewati.
     */
    private function assertAmountMatches(Payment $payment, mixed $grossAmount): void
    {
        if ($grossAmount === null || $grossAmount === '') {
            return;
        }

        if ((int) round((float) $grossAmount) !== (int) round((float) $payment->amount)) {
            throw ValidationException::withMessages([
                'gross_amount' => 'Nominal pembayaran tidak cocok.',
            ]);
        }
    }

    private function mapStatus(string $transactionStatus, ?string $fraudStatus = null): string
    {
        // Kartu kredit: `capture` baru berarti lunas setelah pemeriksaan fraud
        // selesai. `challenge` masih ditinjau, `deny` ditolak.
        if ($transactionStatus === 'capture') {
            return match ($fraudStatus) {
                'challenge' => Payment::STATUS_PENDING,
                'deny' => Payment::STATUS_FAILED,
                default => Payment::STATUS_PAID,
            };
        }

        return match (true) {
            $transactionStatus === 'settlement' => Payment::STATUS_PAID,
            in_array($transactionStatus, self::REFUND_STATUSES, true) => Payment::STATUS_REFUNDED,
            $transactionStatus === 'expire' => Payment::STATUS_EXPIRED,
            $transactionStatus === 'pending' => Payment::STATUS_PENDING,
            default => Payment::STATUS_FAILED,
        };
    }
}
