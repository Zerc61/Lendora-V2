<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Payments\PaymentService;
use App\Services\Payments\WalletTransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Notifikasi pembayaran dari Midtrans (Tahap 4).
 *
 * Tanpa autentikasi: Midtrans tidak punya session. Penjaganya tanda tangan
 * sha512 dengan kunci server, diperiksa sebelum satu baris pun ditulis. Balasan
 * sengaja tipis; Midtrans hanya butuh status 2xx.
 *
 * Top up dompet memakai kunci dan endpoint Midtrans yang sama, jadi pesanannya
 * dibedakan dari kecocokan order: order yang bukan pembayaran booking diteruskan
 * ke {@see WalletTransactionService} apa adanya. Buku besar keduanya tetap
 * terpisah.
 */
class MidtransWebhookController extends Controller
{
    public function handle(
        Request $request,
        PaymentService $payments,
        WalletTransactionService $wallet,
    ): JsonResponse {
        $payment = $payments->handleNotification($request->all());

        if ($payment !== null) {
            return response()->json([
                'data' => [
                    'order_id' => $payment->order_id,
                    'status' => $payment->status,
                ],
            ]);
        }

        $transaction = $wallet->handleNotification($request->all());

        return response()->json([
            'data' => [
                'order_id' => $transaction?->reference_id,
                'status' => $transaction?->status ?? 'ignored',
            ],
        ]);
    }
}
