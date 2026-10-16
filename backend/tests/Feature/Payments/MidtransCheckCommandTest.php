<?php

namespace Tests\Feature\Payments;

use Tests\TestCase;

/**
 * Pemeriksaan kesiapan konfigurasi Midtrans (`midtrans:check`).
 *
 * Yang diuji adalah pembacaannya, bukan nilai kuncinya: kunci sandbox di
 * lingkungan production (dan sebaliknya) adalah kesalahan yang paling sering dan
 * hanya muncul sebagai 401 saat charge.
 */
class MidtransCheckCommandTest extends TestCase
{
    private function configure(?string $serverKey, ?string $clientKey, bool $production): void
    {
        config([
            'services.midtrans.server_key' => $serverKey,
            'services.midtrans.client_key' => $clientKey,
            'services.midtrans.is_production' => $production,
        ]);
    }

    public function test_it_warns_when_keys_are_missing(): void
    {
        $this->configure(null, null, false);

        $this->artisan('midtrans:check')
            ->expectsOutputToContain('belum diisi')
            ->expectsOutputToContain('Midtrans belum aktif')
            ->assertExitCode(0);
    }

    public function test_it_flags_a_sandbox_key_in_production_mode(): void
    {
        $this->configure('SB-Mid-server-uji', 'SB-Mid-client-uji', true);

        $this->artisan('midtrans:check')
            ->expectsOutputToContain('Kunci sandbox dipasang di lingkungan production')
            ->assertExitCode(1);
    }

    public function test_it_flags_a_production_key_in_sandbox_mode(): void
    {
        $this->configure('Mid-server-uji', 'Mid-client-uji', false);

        $this->artisan('midtrans:check')
            ->expectsOutputToContain('Kunci production dipasang di lingkungan sandbox')
            ->assertExitCode(1);
    }

    public function test_it_flags_mixed_client_and_server_keys(): void
    {
        $this->configure('Mid-server-uji', 'SB-Mid-client-uji', true);

        $this->artisan('midtrans:check')
            ->expectsOutputToContain('berasal dari lingkungan Midtrans yang berbeda')
            ->assertExitCode(1);
    }

    public function test_it_accepts_a_matching_pair(): void
    {
        $this->configure('SB-Mid-server-uji', 'SB-Mid-client-uji', false);

        $this->artisan('midtrans:check')
            ->expectsOutputToContain('Konfigurasi Midtrans konsisten')
            ->assertExitCode(0);
    }
}
