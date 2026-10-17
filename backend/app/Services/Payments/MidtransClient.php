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
    /*
     * Dua host berbeda, dan tertukar adalah penyebab umum "pembayaran tidak
     * pernah masuk": halaman/token Snap dilayani `app.*`, sedangkan Core API
     * (`/v2/...`, tempat status transaksi ditanyakan) dilayani `api.*`.
     * Menembak `/v2/...` ke host Snap menjawab 404, yang mudah disalahartikan
     * sebagai "order tidak ada".
     */
    private const SANDBOX_SNAP_URL = 'https://app.sandbox.midtrans.com';

    private const PRODUCTION_SNAP_URL = 'https://app.midtrans.com';

    private const SANDBOX_API_URL = 'https://api.sandbox.midtrans.com';

    private const PRODUCTION_API_URL = 'https://api.midtrans.com';

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
        $response = $this->request()->post($this->snapBaseUrl().'/snap/v1/transactions', $payload);

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
        $response = $this->request()->get($this->apiBaseUrl().'/v2/'.$orderId.'/status');

        if ($response->status() === 404) {
            return null;
        }

        $body = $response->json() ?? [];

        // Sebagian jawaban "tidak ada" datang sebagai 200 dengan status_code
        // 404 di badan: order belum ada di Core API (mis. Snap yang belum
        // dibayar). Perlakukan sama seperti 404 supaya tidak tertukar dengan
        // status transaksi sungguhan.
        if ((string) ($body['status_code'] ?? '') === '404') {
            return null;
        }

        if ($response->failed()) {
            throw new RuntimeException('Midtrans gagal mengambil status: '.$response->body());
        }

        return $body;
    }

    /**
     * Uji kunci ke Midtrans tanpa mencetak nilainya.
     *
     * Order contoh yang tidak ada sudah cukup: kunci yang diterima menjawab 404
     * ("transaction doesn't exist"), kunci yang salah dijawab 401. Awalan kunci
     * tidak bisa dipercaya untuk ini.
     *
     * @return array{ok: bool, code: string, message: string}
     */
    public function probe(): array
    {
        $response = $this->request()->get($this->apiBaseUrl().'/v2/LENDORA-PROBE/status');
        $body = $response->json() ?? [];

        $code = (string) ($body['status_code'] ?? $response->status());

        return [
            'ok' => ! in_array($code, ['401', '403'], true),
            'code' => $code,
            'message' => (string) ($body['status_message'] ?? ''),
        ];
    }

    /**
     * Status transaksi Snap berdasarkan token, bukan order id.
     *
     * Terutama untuk top up: token disimpan saat charge, dan endpoint Snap
     * menjawab status pembayaran yang Core API kadang tidak kenal untuk order
     * yang dibuat lewat Snap sandbox.
     */
    public function snapStatus(string $token): ?array
    {
        $response = $this->request()->get($this->snapBaseUrl().'/snap/v1/transactions/'.urlencode($token).'/status');
        $body = $response->json() ?? [];

        // Belum dibayar: Midtrans menjawab { "error_messages": ["Payment has not been made"] }
        // dengan kode 201. Perlakukan sebagai "belum lunas", bukan galat.
        if (($body['error_messages'] ?? null) === ['Payment has not been made']) {
            return [
                'transaction_status' => 'pending',
                'gross_amount' => $body['transaction_details']['gross_amount'] ?? null,
            ];
        }

        if ($response->failed()) {
            throw new RuntimeException('Midtrans gagal mengambil status Snap: '.$response->body());
        }

        return $body;
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
     * Host halaman dan token Snap (charge).
     */
    public function snapBaseUrl(): string
    {
        return config('services.midtrans.is_production')
            ? self::PRODUCTION_SNAP_URL
            : self::SANDBOX_SNAP_URL;
    }

    /**
     * Host Core API tempat status transaksi ditanyakan.
     */
    public function apiBaseUrl(): string
    {
        return config('services.midtrans.is_production')
            ? self::PRODUCTION_API_URL
            : self::SANDBOX_API_URL;
    }
}
