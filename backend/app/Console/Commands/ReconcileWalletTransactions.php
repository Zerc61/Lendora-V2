<?php

namespace App\Console\Commands;

use App\Models\WalletTransaction;
use App\Services\Payments\MidtransClient;
use App\Services\Payments\WalletTransactionService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Rekonsiliasi transaksi dompet yang masih pending: tanya status ke Midtrans
 * dan perbarui saldo bila sudah settlement.
 *
 * Penerapan status diserahkan ke {@see WalletTransactionService::applyStatus()},
 * jadi jalur ini memakai penguncian dan pemeriksaan nominal yang sama dengan
 * webhook: notifikasi yang terlambat tidak bisa menambah saldo dua kali.
 */
class ReconcileWalletTransactions extends Command
{
    protected $signature = 'wallet:reconcile';

    protected $description = 'Cek dan perbarui transaksi dompet pending pada Midtrans';

    public function __construct(
        private MidtransClient $client,
        private WalletTransactionService $wallet,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $pending = WalletTransaction::query()
            ->where('status', WalletTransaction::STATUS_PENDING)
            ->where('type', WalletTransaction::TYPE_TOPUP)
            ->where('created_at', '>=', now()->subDays(3))
            ->get();

        if ($pending->isEmpty()) {
            $this->info('Tidak ada transaksi pending.');

            return self::SUCCESS;
        }

        if (! $this->client->enabled()) {
            $this->error('Midtrans belum dikonfigurasi.');

            return self::FAILURE;
        }

        foreach ($pending as $transaction) {
            try {
                $status = $this->client->findStatus((string) $transaction->reference_id);
            } catch (\Exception $e) {
                $this->error("Error cek status {$transaction->reference_id}: ".$e->getMessage());

                continue;
            }

            // Midtrans tidak mengenal order ini. Kalau masa berlakunya sudah
            // lewat, baris ditutup lewat jalur status yang terkunci supaya tidak
            // menggantung selamanya (404 juga dipakai Midtrans untuk order dari
            // lingkungan lain, jadi hanya yang kedaluwarsa yang ditutup).
            if ($status === null) {
                if ($transaction->expires_at !== null && $transaction->expires_at->isPast()) {
                    $this->wallet->applyStatus($transaction, 'expire');

                    $this->info("Kedaluwarsa, ditutup: {$transaction->reference_id}");

                    continue;
                }

                $this->warn("Tidak ditemukan: {$transaction->reference_id}");

                continue;
            }

            try {
                $updated = $this->wallet->applyStatus(
                    $transaction,
                    (string) ($status['transaction_status'] ?? ''),
                    $status,
                );
            } catch (ValidationException $e) {
                $this->warn("Nominal tidak cocok, dilewati: {$transaction->reference_id}");

                continue;
            }

            $this->info(match ($updated->status) {
                WalletTransaction::STATUS_SUCCESS => "Berhasil: {$transaction->reference_id}",
                WalletTransaction::STATUS_FAILED => "Gagal: {$transaction->reference_id}",
                default => "Masih pending: {$transaction->reference_id}",
            });
        }

        $this->info("Rekonsiliasi selesai: {$pending->count()} transaksi diproses.");

        return self::SUCCESS;
    }
}
