<?php

namespace App\Services\Lending;

use App\Models\Asset;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Harga sewa dihitung server, tidak pernah dari klien (rencana Bagian 6.3).
 *
 * Hasilnya dipakai sekali saat loan dibuat, lalu disimpan sebagai snapshot pada
 * loan. Tarif provider yang berubah sesudahnya tidak mengubah tagihan yang sudah
 * tercatat.
 *
 * Aritmetika uang memakai bcmath, bukan float, supaya pembulatan tidak
 * menyimpang pada jumlah besar.
 */
class PricingService
{
    public const CURRENCY = 'IDR';

    private const SCALE = 2;

    /**
     * @param  Collection<int, Asset>  $assets
     * @return array{days: int, rate_total: string, subtotal: string, deposit_total: string, currency: string, lines: list<array<string, mixed>>}
     */
    public function quote(Collection $assets, CarbonInterface $start, CarbonInterface $end): array
    {
        $days = $this->billedDays($assets, $start, $end);

        $rateTotal = '0.00';
        $subtotal = '0.00';
        $depositTotal = '0.00';
        $lines = [];

        foreach ($assets as $asset) {
            $rate = $asset->daily_rate;

            if ($rate === null) {
                throw ValidationException::withMessages([
                    'assets' => $asset->name.' belum punya tarif harian, jadi belum bisa dipesan.',
                ]);
            }

            $amount = bcmul((string) $rate, (string) $days, self::SCALE);

            $rateTotal = bcadd($rateTotal, (string) $rate, self::SCALE);
            $subtotal = bcadd($subtotal, $amount, self::SCALE);
            $depositTotal = bcadd($depositTotal, (string) ($asset->deposit_amount ?? '0'), self::SCALE);

            $lines[] = [
                'asset_id' => $asset->getKey(),
                'code' => $asset->code,
                'name' => $asset->name,
                'daily_rate' => (string) $rate,
                'days' => $days,
                'amount' => $amount,
            ];
        }

        return [
            'days' => $days,
            'rate_total' => $rateTotal,
            'subtotal' => $subtotal,
            'deposit_total' => $depositTotal,
            'currency' => self::CURRENCY,
            'lines' => $lines,
        ];
    }

    /**
     * Hari yang ditagih: selisih periode dibulatkan ke atas, tidak pernah kurang
     * dari `min_days` unit mana pun dalam satu pemesanan.
     *
     * @param  Collection<int, Asset>  $assets
     */
    private function billedDays(Collection $assets, CarbonInterface $start, CarbonInterface $end): int
    {
        $hours = $start->diffInMinutes($end) / 60;
        $days = max(1, (int) ceil($hours / 24));
        $minDays = (int) ($assets->pluck('min_days')->filter()->max() ?? 1);

        return max($days, $minDays);
    }
}
