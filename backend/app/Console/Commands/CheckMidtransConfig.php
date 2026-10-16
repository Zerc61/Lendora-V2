<?php

namespace App\Console\Commands;

use App\Services\Payments\MidtransClient;
use Illuminate\Console\Command;

/**
 * Periksa kesiapan konfigurasi Midtrans.
 *
 * Nilai kunci tidak pernah dicetak — hanya ada atau tidaknya, lingkungan yang
 * ditunjuk, dan kecocokan awalan kunci dengan lingkungan itu. Awalan ini yang
 * paling sering salah: kunci `SB-` (sandbox) ditolak di basis URL production dan
 * sebaliknya, dan gejalanya hanya terlihat sebagai 401 saat charge.
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
        $this->line(sprintf('  Basis URL   : %s', $this->client->baseUrl()));
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

        $sandboxKey = str_starts_with($serverKey, 'SB-');

        // Kunci sandbox hanya cocok dengan lingkungan sandbox; yang lain tidak.
        if ($sandboxKey === $production) {
            $this->error(sprintf(
                'Kunci %s dipasang di lingkungan %s. Charge akan ditolak (401) sampai keduanya cocok.',
                $sandboxKey ? 'sandbox' : 'production',
                $production ? 'production' : 'sandbox',
            ));

            return self::FAILURE;
        }

        if (str_starts_with($clientKey, 'SB-') !== $sandboxKey) {
            $this->error('Kunci klien dan kunci server berasal dari lingkungan Midtrans yang berbeda.');

            return self::FAILURE;
        }

        $this->info('Konfigurasi Midtrans konsisten.');
        $this->line('Daftarkan URL webhook di atas pada dasbor Midtrans sebelum menguji pembayaran.');

        return self::SUCCESS;
    }
}
