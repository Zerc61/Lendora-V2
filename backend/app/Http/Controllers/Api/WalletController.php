<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WalletTransaction;
use App\Services\Payments\MidtransClient;
use App\Services\Payments\WalletTransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class WalletController extends Controller
{
    /**
     * Batas transaksi yang dicek ke Midtrans per panggilan sinkronisasi. Cukup
     * untuk top up yang baru saja dibuat, dan menjaga request tetap singkat.
     */
    private const SYNC_LIMIT = 5;

    public function __construct(protected MidtransClient $midtrans) {}

    public function balance(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'wallet_balance' => (float) $request->user()->wallet_balance,
                // Batas dari server, bukan angka di klien, supaya validasi
                // klien dan validasi server tidak bisa berbeda.
                'topup_min' => (int) config('wallet.topup_min'),
                'topup_max' => (int) config('wallet.topup_max'),
                'expiry_hours' => (int) config('wallet.expiry_hours'),
            ],
        ]);
    }

    /**
     * Riwayat transaksi dompet milik pengguna yang meminta. Label dikirim
     * server (pola `*_label` yang sama dengan status pinjaman), termasuk arah
     * saldo agar klien tidak menebak dari tanda nominal.
     */
    public function transactions(Request $request): JsonResponse
    {
        $transactions = $request->user()->walletTransactions()
            ->orderByDesc('created_at')
            ->paginate(20)
            ->through(fn (WalletTransaction $transaction): array => [
                'id' => $transaction->getKey(),
                'amount' => (float) $transaction->amount,
                'type' => $transaction->type,
                'type_label' => WalletTransaction::TYPE_LABELS[$transaction->type] ?? $transaction->type,
                'status' => $transaction->status,
                'status_label' => WalletTransaction::STATUS_LABELS[$transaction->status] ?? $transaction->status,
                'direction' => in_array($transaction->type, WalletTransaction::TYPES_IN, true) ? 'in' : 'out',
                'reference_id' => $transaction->reference_id,
                'expires_at' => $transaction->expires_at?->toIso8601String(),
                'created_at' => $transaction->created_at?->toIso8601String(),
            ]);

        return response()->json($transactions);
    }

    /**
     * Buat permintaan top up: satu baris buku besar `pending` plus tautan
     * pembayaran Midtrans. Saldo baru bertambah setelah notifikasi Midtrans
     * terverifikasi (`WalletTransactionService`), bukan setelah respons ini.
     */
    public function topup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount' => [
                'required',
                'integer',
                'min:'.(int) config('wallet.topup_min'),
                'max:'.(int) config('wallet.topup_max'),
            ],
        ]);

        if (! $this->midtrans->enabled()) {
            throw ValidationException::withMessages([
                'amount' => 'Top up belum tersedia. Hubungi penyedia.',
            ]);
        }

        $user = $request->user();
        $amount = (int) $validated['amount'];
        $expiryHours = (int) config('wallet.expiry_hours');
        $orderId = sprintf('WLLET-%s', Str::lower((string) Str::ulid()));

        $transaction = $user->walletTransactions()->create([
            'amount' => $amount,
            'type' => WalletTransaction::TYPE_TOPUP,
            'reference_id' => $orderId,
            'status' => WalletTransaction::STATUS_PENDING,
            'expires_at' => now()->addHours($expiryHours),
            'metadata' => ['order_id' => $orderId],
        ]);

        try {
            $charge = $this->midtrans->charge([
                'transaction_details' => [
                    'order_id' => $orderId,
                    'gross_amount' => $amount,
                ],
                'expiry' => [
                    'unit' => 'hours',
                    'duration' => $expiryHours,
                ],
                'customer_details' => [
                    'first_name' => $user->name,
                    'email' => $user->email,
                    'phone' => (string) ($user->phone ?? ''),
                ],
                'callbacks' => [
                    // Origin SPA, bukan config('app.url') (origin API): di dev
                    // keduanya beda dan tautan balik jadi 404.
                    'finish' => rtrim((string) config('services.midtrans.frontend_url'), '/').'/app/wallet',
                ],
            ]);
        } catch (Throwable $e) {
            // Tanpa tautan bayar, baris pending tidak ada gunanya: tutup sebagai
            // gagal supaya rekonsiliasi tidak menanyakan status yang tidak ada.
            $transaction->update([
                'status' => WalletTransaction::STATUS_FAILED,
                'metadata' => ['order_id' => $orderId, 'error' => $e->getMessage()],
            ]);

            report($e);

            throw ValidationException::withMessages([
                'amount' => 'Gagal memulai pembayaran. Coba lagi sebentar lagi.',
            ]);
        }

        return response()->json([
            'data' => [
                'order_id' => $orderId,
                'transaction' => $transaction->only(['id', 'amount', 'type', 'status', 'expires_at']),
                'snap_token' => $charge['token'] ?? null,
                'redirect_url' => $charge['redirect_url'] ?? null,
                // Kunci klien memang publik (dipakai skrip Snap di browser);
                // kunci server tidak pernah ikut dikirim.
                'snap_client_key' => config('services.midtrans.client_key'),
                'snap_is_production' => (bool) config('services.midtrans.is_production'),
            ],
        ]);
    }

    /**
     * Sinkronkan top up yang masih pending milik pengguna ini dengan Midtrans.
     *
     * Dipanggil klien saat halaman dompet dibuka dan setelah halaman pembayaran
     * ditutup. Tanpa webhook yang bisa menjangkau server (dev lokal, atau
     * notifikasi yang gagal), inilah yang membuat saldo langsung bertambah tanpa
     * menunggu `wallet:reconcile` terjadwal — dan tanpa mengubah konfigurasi
     * notifikasi di dashboard Midtrans.
     *
     * Jalur tulis saldo tetap satu: {@see WalletTransactionService::applyStatus()}
     * dengan penguncian dan pemeriksaan nominal yang sama dengan webhook.
     */
    public function syncTopups(Request $request, WalletTransactionService $wallet): JsonResponse
    {
        $user = $request->user();

        $pending = $user->walletTransactions()
            ->where('status', WalletTransaction::STATUS_PENDING)
            ->where('type', WalletTransaction::TYPE_TOPUP)
            ->where('created_at', '>=', now()->subDays(3))
            ->orderByDesc('created_at')
            ->limit(self::SYNC_LIMIT)
            ->get();

        $settled = [];
        $settledAmount = 0.0;

        foreach ($pending as $transaction) {
            try {
                $updated = $wallet->syncWithMidtrans($transaction);
            } catch (ValidationException) {
                // Nominal Midtrans tidak sama dengan catatan: saldo tidak berubah.
                continue;
            } catch (Throwable $e) {
                // Midtrans tidak bisa dihubungi. Barisnya tetap pending; halaman
                // dompet tidak boleh ikut gagal hanya karena ini.
                report($e);

                continue;
            }

            if ($updated->status === WalletTransaction::STATUS_SUCCESS) {
                $settled[] = $updated->reference_id;
                $settledAmount += (float) $updated->amount;
            }
        }

        return response()->json([
            'data' => [
                'checked' => $pending->count(),
                'settled' => $settled,
                // Dihitung server supaya klien tidak menebak nominal yang lunas.
                'settled_amount' => $settledAmount,
                'wallet_balance' => (float) $user->fresh()->wallet_balance,
            ],
        ]);
    }
}
