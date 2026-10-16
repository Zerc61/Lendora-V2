<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Services\Payments\MidtransClient;
use App\Services\Payments\PaymentService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Rekonsiliasi pembayaran booking yang masih pending.
 *
 * Notifikasi Midtrans bisa tidak sampai (mis. webhook tidak bisa dijangkau).
 * Tanpa jalur ini order yang sudah dibayar tetap tampak menunggu dan gerbang
 * serah terima ikut tertahan. Penerapan status diserahkan ke
 * {@see PaymentService::applyPayload()} supaya idempotensi dan pemeriksaan
 * nominalnya sama persis dengan webhook.
 */
class ReconcilePayments extends Command
{
    protected $signature = 'payments:reconcile';

    protected $description = 'Cek dan perbarui pembayaran booking pending pada Midtrans';

    public function __construct(
        private MidtransClient $client,
        private PaymentService $payments,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $pending = Payment::query()
            ->withoutOrganizationScope()
            ->where('status', Payment::STATUS_PENDING)
            ->where('created_at', '>=', now()->subDays(3))
            ->get();

        if ($pending->isEmpty()) {
            $this->info('Tidak ada pembayaran pending.');

            return self::SUCCESS;
        }

        if (! $this->client->enabled()) {
            $this->error('Midtrans belum dikonfigurasi.');

            return self::FAILURE;
        }

        foreach ($pending as $payment) {
            try {
                $status = $this->client->findStatus((string) $payment->order_id);
            } catch (\Exception $e) {
                $this->error("Error cek status {$payment->order_id}: ".$e->getMessage());

                continue;
            }

            // Midtrans tidak mengenal order ini. Yang masa berlakunya sudah lewat
            // ditutup supaya tidak menggantung selamanya.
            if ($status === null) {
                if ($payment->expires_at !== null && $payment->expires_at->isPast()) {
                    $this->payments->applyPayload([
                        'order_id' => $payment->order_id,
                        'transaction_status' => 'expire',
                    ]);

                    $this->info("Kedaluwarsa, ditutup: {$payment->order_id}");

                    continue;
                }

                $this->warn("Tidak ditemukan: {$payment->order_id}");

                continue;
            }

            try {
                $updated = $this->payments->applyPayload([
                    'order_id' => $payment->order_id,
                    'transaction_status' => $status['transaction_status'] ?? '',
                    'fraud_status' => $status['fraud_status'] ?? null,
                    'gross_amount' => $status['gross_amount'] ?? null,
                    'status_code' => $status['status_code'] ?? '200',
                    'transaction_id' => $status['transaction_id'] ?? null,
                ]);
            } catch (ValidationException $e) {
                $this->warn("Nominal tidak cocok, dilewati: {$payment->order_id}");

                continue;
            }

            $this->info(sprintf('%s: %s', $payment->order_id, $updated?->status ?? 'tidak dikenal'));
        }

        $this->info("Rekonsiliasi selesai: {$pending->count()} pembayaran diproses.");

        return self::SUCCESS;
    }
}
