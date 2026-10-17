<?php

namespace App\Console\Commands;

use App\Services\Payments\MidtransClient;
use Illuminate\Console\Command;

/**
 * Periksa kesiapan konfigurasi Midtrans.
 *
 * Nilai kunci tidak pernah dicetak — hanya ada atau tidaknya, lingkungan yang
 * ditunjuk, dan jawaban Midtrans atas kunci itu. Kecocokan kunci diuji dengan
 * sungguhan menanyakan status order contoh, bukan menebak dari awalan kunci.
 */
class CheckMidtransConfig extends Command
{
    protected $signature = 'midtrans:check';

    protected $description = 'Periksa kunci dan lingkungan Midtrans yang terpasang';

    public function __construct(private MidtransClient $client)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $serverKey = (string) config('services.midtrans.server_key');
        $clientKey = (string) config('services.midtrans.client_key');
        $production = (bool) config('services.midtrans.is_production');

        $this->line(sprintf('  Lingkungan  : %s', $production ? 'production' : 'sandbox'));
        $this->line(sprintf('  Snap        : %s', $this->client->snapBaseUrl()));
        $this->line(sprintf('  Core API    : %s', $this->client->apiBaseUrl()));
        $this->line(sprintf('  Kunci server: %s', $serverKey === '' ? 'belum diisi' : 'terisi'));
        $this->line(sprintf('  Kunci klien : %s', $clientKey === '' ? 'belum diisi' : 'terisi'));
        $this->line(sprintf(
            '  Webhook     : %s/api/payments/webhook',
            rtrim((string) config('app.url'), '/'),
        ));
        $this->newLine();

        if ($serverKey === '' || $clientKey === '') {
            $this->warn('Midtrans belum aktif: top up dan pembayaran Midtrans ditolak sampai kunci diisi.');

            return self::SUCCESS;
        }

        // Kunci diuji sungguhan ke Midtrans. Pemeriksaan awalan kunci dulu
        // menandai konfigurasi yang sebenarnya jalan sebagai salah, jadi yang
        // dipakai sekarang jawaban Midtrans itu sendiri.
        try {
            $probe = $this->client->probe();
        } catch (\Throwable $e) {
            $this->error('Midtrans tidak bisa dihubungi: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! $probe['ok']) {
            $this->error(sprintf(
                'Midtrans menolak kunci server ini (kode %s%s).',
                $probe['code'],
                $probe['message'] === '' ? '' : ': '.$probe['message'],
            ));

            return self::FAILURE;
        }

        $this->info(sprintf('Kunci server diterima Midtrans %s.', $production ? 'production' : 'sandbox'));
        $this->line('Order contoh yang tidak ada memang dijawab 404; itu bukan tanda kesalahan.');
        $this->line('Pembayaran tidak butuh webhook terdaftar: halaman dompet dan booking menanyakan.');
        $this->line('statusnya sendiri ke Midtrans.');

        return self::SUCCESS;
    }
}
