<?php

namespace App\Services\Payments;

use App\Models\WalletTransaction;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Buku besar dompet: satu-satunya jalur yang boleh mengubah saldo.
 *
 * Kredit dan penarikan kembali selalu lewat jalur di kelas ini, yang mengunci
 * baris transaksi di dalam transaksi basis data. Notifikasi ganda — webhook
 * diulang Midtrans, atau webhook dan rekonsiliasi berjalan bersamaan — karena itu
 * tetap menghasilkan satu perubahan saldo.
 */
class WalletTransactionService
{
    /** Status notifikasi Midtrans yang berarti dana dikembalikan ke pembeli. */
    private const REVERSAL_STATUSES = ['refund', 'partial_refund', 'chargeback'];

    public function __construct(
        private MidtransClient $client,
        private NotificationDispatcher $notifications,
    ) {}

    /**
     * Terapkan notifikasi Midtrans pada satu transaksi dompet.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handleNotification(array $payload): ?WalletTransaction
    {
        if (! $this->client->verifySignature($payload)) {
            throw ValidationException::withMessages([
                'signature_key' => 'Tanda tangan tidak sah.',
            ]);
        }

        $orderId = (string) ($payload['order_id'] ?? '');

        $transaction = WalletTransaction::query()
            ->where('reference_id', $orderId)
            ->where('type', WalletTransaction::TYPE_TOPUP)
            ->first();

        // Order yang tidak dikenal diabaikan: bisa jadi milik lingkungan lain.
        if ($transaction === null) {
            return null;
        }

        return $this->applyStatus($transaction, (string) ($payload['transaction_status'] ?? ''), $payload);
    }

    /**
     * Tanya status satu transaksi ke Midtrans lalu terapkan hasilnya.
     *
     * Dipakai rekonsiliasi terjadwal dan sinkronisasi saat pengguna kembali dari
     * halaman pembayaran. Tanpa webhook yang bisa menjangkau server (dev lokal,
     * atau notifikasi yang gagal terkirim), inilah jalur yang membuat saldo
     * bertambah tanpa menunggu jadwal rekonsiliasi.
     */
    public function syncWithMidtrans(WalletTransaction $transaction): WalletTransaction
    {
        $status = $this->client->findStatus((string) $transaction->reference_id);

        // Midtrans tidak mengenal order ini: bisa jadi order dari lingkungan
        // lain, jadi hanya yang masa berlakunya lewat yang ditutup.
        if ($status === null) {
            if ($transaction->expires_at !== null && $transaction->expires_at->isPast()) {
                return $this->applyStatus($transaction, 'expire');
            }

            return $transaction;
        }

        return $this->applyStatus($transaction, (string) ($status['transaction_status'] ?? ''), $status);
    }

    /**
     * Tulis status Midtrans ke transaksi, lalu ubah saldo bila perlu.
     *
     * Idempoten dan atomik: baris dikunci, status dibaca ulang di dalam kunci, dan
     * saldo hanya berubah pada peralihan yang benar-benar mengubah uang. Nominal
     * yang dilaporkan Midtrans wajib sama dengan nominal yang kita catat; kalau
     * tidak, tidak ada satu baris pun yang ditulis.
     *
     * @param  array<string, mixed>  $payload
     */
    public function applyStatus(
        WalletTransaction $transaction,
        string $midtransStatus,
        array $payload = [],
    ): WalletTransaction {
        $this->assertAmountMatches($transaction, $payload['gross_amount'] ?? null);

        if (in_array($midtransStatus, self::REVERSAL_STATUSES, true)) {
            return $this->reverseCredit($transaction, $midtransStatus, $payload);
        }

        $status = $this->mapStatus($midtransStatus, $payload['fraud_status'] ?? null);

        // Penanda diisi di dalam closure, dipakai setelah commit: notifikasi
        // tidak boleh ikut dibatalkan bila transaksinya digulung.
        $credited = false;

        $result = DB::transaction(function () use ($transaction, $status, $payload, &$credited): WalletTransaction {
            $locked = WalletTransaction::query()->lockForUpdate()->findOrFail($transaction->getKey());

            // Sukses dan dikembalikan bersifat final: notifikasi yang datang
            // terlambat tidak boleh mengubah saldo lagi (termasuk notifikasi
            // `settlement` basi setelah dana dikembalikan).
            if (in_array($locked->status, [WalletTransaction::STATUS_SUCCESS, WalletTransaction::STATUS_REVERSED], true)) {
                return $locked;
            }

            $locked->update([
                'status' => $status,
                'metadata' => array_merge($locked->metadata ?? [], $payload),
            ]);

            if ($status === WalletTransaction::STATUS_SUCCESS) {
                // Saldo adalah kolom turunan: nilainya dijaga ikut buku besar,
                // bukan dihitung ulang tiap request.
                $locked->user()->increment('wallet_balance', $locked->amount);
                $credited = true;
            }

            return $locked;
        });

        if ($credited) {
            $this->notifications->walletToppedUp($result);
        }

        return $result;
    }

    /**
     * Tarik kembali saldo top up yang sudah dikreditkan, karena Midtrans
     * mengembalikan dana pembeli.
     *
     * Penarikan memakai pembaruan bersyarat (`wallet_balance >= nominal`), jadi
     * saldo tidak pernah negatif walau saldo sudah terpakai. Bila saldo tidak
     * cukup, baris tetap ditutup dan sisa yang harus ditagih dicatat terbuka
     * (`reversal_deferred`) untuk ditindaklanjuti manual.
     *
     * @param  array<string, mixed>  $payload
     */
    private function reverseCredit(
        WalletTransaction $transaction,
        string $midtransStatus,
        array $payload,
    ): WalletTransaction {
        // Nilai yang benar-benar dikembalikan: sebagian (`refund_amount`) atau penuh.
        $amount = round((float) ($payload['refund_amount'] ?? $transaction->amount), 2);

        $reversed = false;

        $result = DB::transaction(function () use ($transaction, $midtransStatus, $payload, $amount, &$reversed): WalletTransaction {
            $locked = WalletTransaction::query()->lockForUpdate()->findOrFail($transaction->getKey());

            if ($locked->type !== WalletTransaction::TYPE_TOPUP || $locked->status !== WalletTransaction::STATUS_SUCCESS) {
                // Belum pernah dikreditkan: tidak ada yang perlu ditarik.
                return $locked;
            }

            $refunds = $locked->metadata['refunds'] ?? [];
            $key = (string) ($payload['refund_key'] ?? sprintf('%s:%.2f', $midtransStatus, $amount));

            foreach ($refunds as $applied) {
                if (($applied['key'] ?? null) === $key) {
                    // Pengembalian ini sudah ditarik sebelumnya.
                    return $locked;
                }
            }

            $before = (float) DB::table('users')->where('id', $locked->user_id)->value('wallet_balance');

            // Tarik penuh bila saldo cukup. Kalau tidak, sisa saldo dikosongkan
            // (satu UPDATE, dijaga `wallet_balance > 0`) dan kekurangannya
            // dicatat terbuka untuk ditindaklanjuti manual — saldo tidak pernah
            // negatif karena pembaruan pertama bersyarat.
            $debit = DB::table('users')
                ->where('id', $locked->user_id)
                ->where('wallet_balance', '>=', $amount)
                ->decrement('wallet_balance', $amount);

            if ($debit === 0) {
                DB::table('users')
                    ->where('id', $locked->user_id)
                    ->where('wallet_balance', '>', 0)
                    ->update(['wallet_balance' => 0]);
            }

            $after = (float) DB::table('users')->where('id', $locked->user_id)->value('wallet_balance');
            $reclaimed = round(max($before - $after, 0), 2);
            $shortfall = round(max($amount - $reclaimed, 0), 2);
            $full = abs($amount - (float) $locked->amount) < 0.01;

            $refunds[] = [
                'key' => $key,
                'amount' => $amount,
                'status' => $midtransStatus,
                'reclaimed' => $reclaimed,
                'shortfall' => $shortfall,
                'at' => now()->toIso8601String(),
            ];

            $locked->update([
                // Pengembalian sebagian tetap berstatus sukses; yang penuh berarti
                // baris ini seluruhnya sudah ditarik kembali.
                'status' => $full ? WalletTransaction::STATUS_REVERSED : WalletTransaction::STATUS_SUCCESS,
                'metadata' => array_merge($locked->metadata ?? [], [
                    'midtrans_refund' => $payload,
                    'refunds' => $refunds,
                    'reversal_deferred' => $shortfall > 0,
                    'reversal_shortfall' => $shortfall,
                ]),
            ]);

            $reversed = true;

            if ($shortfall > 0) {
                report(new \RuntimeException(sprintf(
                    'Pengembalian Midtrans %s kurang %s dari saldo pengguna %d.',
                    $locked->reference_id,
                    number_format($shortfall, 2, '.', ''),
                    $locked->user_id,
                )));
            }

            return $locked;
        });

        if ($reversed) {
            $this->notifications->walletTopUpReversed($result);
        }

        return $result;
    }

    /**
     * Nominal notifikasi wajib sama dengan nominal transaksi. Tanpa nominal
     * (mis. balasan status tanpa `gross_amount`), pemeriksaan dilewati.
     */
    private function assertAmountMatches(WalletTransaction $transaction, mixed $grossAmount): void
    {
        if ($grossAmount === null || $grossAmount === '') {
            return;
        }

        if ((int) round((float) $grossAmount) !== (int) round((float) $transaction->amount)) {
            throw ValidationException::withMessages([
                'gross_amount' => 'Nominal pembayaran tidak cocok.',
            ]);
        }
    }

    private function mapStatus(string $transactionStatus, ?string $fraudStatus = null): string
    {
        // Kartu kredit: `capture` belum berarti lunas sebelum pemeriksaan fraud
        // selesai, jadi saldo tidak boleh dikreditkan saat `challenge`.
        if ($transactionStatus === 'capture') {
            return match ($fraudStatus) {
                'challenge' => WalletTransaction::STATUS_PENDING,
                'deny' => WalletTransaction::STATUS_FAILED,
                default => WalletTransaction::STATUS_SUCCESS,
            };
        }

        return match (true) {
            $transactionStatus === 'settlement' => WalletTransaction::STATUS_SUCCESS,
            $transactionStatus === 'pending' => WalletTransaction::STATUS_PENDING,
            // expire, deny, cancel, failed, dan status asing: tidak ada uang masuk.
            default => WalletTransaction::STATUS_FAILED,
        };
    }
}
