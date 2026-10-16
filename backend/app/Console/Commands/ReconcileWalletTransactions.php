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
 * Penerapan status diserahkan ke {@see WalletTransactionService::syncWithMidtrans()},
 * jadi jalur ini memakai penguncian dan pemeriksaan nominal yang sama dengan
 * webhook dan sinkronisasi dari klien: notifikasi yang terlambat tidak bisa
 * menambah saldo dua kali.
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
                $updated = $this->wallet->syncWithMidtrans($transaction);
            } catch (ValidationException) {
                $this->warn("Nominal tidak cocok, dilewati: {$transaction->reference_id}");

                continue;
            } catch (\Exception $e) {
                $this->error("Error cek status {$transaction->reference_id}: ".$e->getMessage());

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
