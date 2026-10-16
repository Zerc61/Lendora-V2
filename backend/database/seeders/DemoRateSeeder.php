<?php

namespace Database\Seeders;

use App\Models\Asset;
use Illuminate\Database\Seeder;

/**
 * Tarif demo supaya katalog bisa dipesan untuk percobaan manual: 5% harga beli
 * per hari, dibulatkan ke atas per Rp1.000, minimum Rp25.000, minimum sewa 1 hari.
 *
 * Hanya mengisi aset yang `daily_rate`-nya masih kosong, jadi tarif yang sudah
 * disetel manual lewat panel penyedia tidak pernah ditimpa. Idempoten.
 *
 * Aset tanpa tarif memang ditolak saat dipesan (`PricingService`), jadi tanpa
 * seeder ini katalog demo tampil lengkap tapi tidak ada yang bisa diajukan.
 *
 * Jalankan: php artisan db:seed --class=DemoRateSeeder
 */
class DemoRateSeeder extends Seeder
{
    private const PERCENT = 0.05;

    private const STEP = 1_000;

    private const MINIMUM = 25_000;

    public function run(): void
    {
        $filled = 0;

        Asset::query()
            ->withoutOrganizationScope()
            ->whereNull('daily_rate')
            ->orderBy('id')
            ->each(function (Asset $asset) use (&$filled): void {
                $asset->forceFill([
                    'daily_rate' => $this->dailyRate($asset->purchase_price),
                    'min_days' => $asset->min_days ?? 1,
                ])->save();

                $filled++;
            });

        $this->command?->info("Tarif demo diisi untuk {$filled} aset.");
    }

    /**
     * Harga beli bisa berupa string desimal dari kolom `numeric`.
     */
    private function dailyRate(int|float|string|null $purchasePrice): string
    {
        $price = (float) ($purchasePrice ?? 0);
        $steps = ceil($price * self::PERCENT / self::STEP);
        $rate = max(self::MINIMUM, (int) ($steps * self::STEP));

        return number_format($rate, 2, '.', '');
    }
}
