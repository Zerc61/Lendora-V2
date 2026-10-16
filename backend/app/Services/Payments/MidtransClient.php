<?php

namespace App\Services\Payments;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Klien HTTP Midtrans Snap (Tahap 4).
 *
 * Yang dibutuhkan tahap ini hanya satu panggilan charge dan satu verifikasi
 * tanda tangan notifikasi, jadi tidak ada paket composer tambahan: HTTP client
 * Laravel sudah cukup.
 */
class MidtransClient
{
    private const SANDBOX_BASE_URL = 'https://app.sandbox.midtrans.com';

    private const PRODUCTION_BASE_URL = 'https://app.midtrans.com';

    /**
     * Kunci server belum diatur: panggilan charge tidak mungkin dilakukan.
     */
    public function enabled(): bool
    {
        return filled(config('services.midtrans.server_key'));
    }

    /**
     * Buat transaksi Snap. Hasilnya memuat `token` dan `redirect_url`.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function charge(array $payload): array
    {
        $response = $this->request()->post($this->baseUrl().'/snap/v1/transactions', $payload);

        if ($response->failed()) {
            throw new RuntimeException('Midtrans menolak permintaan charge: '.$response->body());
        }

        return $response->json() ?? [];
    }

    /**
     * Notifikasi Midtrans ditandatangani sha512(order_id + status_code +
     * gross_amount + server key). Tanpa kunci, tanda tangan tidak bisa dipercaya,
     * jadi notifikasi ditolak.
     *
     * @param  array<string, mixed>  $payload
     */
    public function verifySignature(array $payload): bool
    {
        $serverKey = (string) config('services.midtrans.server_key');

        if ($serverKey === '') {
            return false;
        }

        $expected = hash('sha512', sprintf(
            '%s%s%s%s',
            $payload['order_id'] ?? '',
            $payload['status_code'] ?? '',
            $payload['gross_amount'] ?? '',
            $serverKey,
        ));

        return hash_equals($expected, (string) ($payload['signature_key'] ?? ''));
    }

    /**
     * Cek status transaksi Midtrans berdasarkan order_id (untuk rekonsiliasi).
     *
     * `null` berarti Midtrans tidak mengenal order tersebut (404). Pembeda ini
     * perlu agar rekonsiliasi bisa menutup order lokal yang sudah kedaluwarsa
     * tanpa ikut menutup order yang masih hidup.
     *
     * @return array<string, mixed>|null
     */
    public function findStatus(string $orderId): ?array
    {
        $response = $this->request()->get($this->baseUrl().'/v2/'.$orderId.'/status');

        if ($response->status() === 404) {
            return null;
        }

        if ($response->failed()) {
            throw new RuntimeException('Midtrans gagal mengambil status: '.$response->body());
        }

        return $response->json() ?? [];
    }

    private function request(): PendingRequest
    {
        $serverKey = (string) config('services.midtrans.server_key');

        if ($serverKey === '') {
            throw new RuntimeException('MIDTRANS_SERVER_KEY belum diatur.');
        }

        // Midtrans memakai kunci server sebagai username dengan password kosong.
        return Http::withBasicAuth($serverKey, '')
            ->acceptJson()
            ->asJson()
            ->timeout(15);
    }

    /**
     * Basis URL Midtrans yang sedang dipakai (sandbox atau production).
     */
    public function baseUrl(): string
    {
        return config('services.midtrans.is_production')
            ? self::PRODUCTION_BASE_URL
            : self::SANDBOX_BASE_URL;
    }
}
