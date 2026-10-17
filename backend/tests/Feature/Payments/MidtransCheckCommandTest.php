<?php

namespace Tests\Feature\Payments;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pemeriksaan kesiapan konfigurasi Midtrans (`midtrans:check`).
 *
 * Penilaian tidak menebak dari awalan kunci; command sungguhan menanyakan
 * Midtrans dengan kunci server. Di sini HTTP Midtrans dipalsukan supaya hasil
 * probe bisa dipindah-pindah: diterima, ditolak, tidak dapat dihubungi.
 */
class MidtransCheckCommandTest extends TestCase
{
    public function test_it_warns_when_keys_are_missing(): void
    {
        config([
            'services.midtrans.server_key' => null,
            'services.midtrans.client_key' => null,
        ]);

        $this->artisan('midtrans:check')
            ->expectsOutputToContain('belum diisi')
            ->expectsOutputToContain('Midtrans belum aktif')
            ->assertExitCode(0);
    }

    public function test_it_reports_accepted_key(): void
    {
        $this->actingAs(User::factory()->create());
        config([
            'services.midtrans.server_key' => 'kunci-uji',
            'services.midtrans.client_key' => 'kunci-klien-uji',
            'services.midtrans.is_production' => false,
        ]);

        Http::fake([
            '*/v2/*' => Http::response(['status_code' => '404']),
        ]);

        $this->artisan('midtrans:check')
            ->expectsOutputToContain('Kunci server diterima Midtrans sandbox')
            ->assertExitCode(0);
    }

    public function test_it_flags_rejected_key(): void
    {
        config([
            'services.midtrans.server_key' => 'kunci-salah-uji',
            'services.midtrans.client_key' => 'kunci-klien-uji',
            'services.midtrans.is_production' => false,
        ]);

        Http::fake([
            '*/v2/*' => Http::response([
                'status_code' => '401',
                'status_message' => 'Unknown Merchant server_key/id',
            ], 401),
        ]);

        $this->artisan('midtrans:check')
            ->expectsOutputToContain('Midtrans menolak kunci server ini')
            ->assertExitCode(1);
    }
}
