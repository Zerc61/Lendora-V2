<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\WalletTransactionResource;
use App\Models\WalletTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * Pengawasan dompet dari konsol platform.
 *
 * Dompet melekat pada akun, bukan pada organisasi, jadi daftar transaksinya
 * lintas organisasi dan berada di rute `/platform/*` — sama seperti daftar
 * pengguna. Saldo per pengguna tetap kolom `users.wallet_balance`; di sini
 * angka-angka itu hanya dibaca, tidak pernah diubah.
 */
class PlatformWalletController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()?->isPlatformAdmin() === true, 403);

        $query = WalletTransaction::query()->with('user');

        $type = $request->query('type');

        if (is_string($type) && in_array($type, WalletTransaction::TYPES, true)) {
            $query->where('type', $type);
        }

        $status = $request->query('status');

        if (is_string($status) && in_array($status, WalletTransaction::STATUSES, true)) {
            $query->where('status', $status);
        }

        $term = trim((string) $request->query('q'));

        if ($term !== '') {
            $query->whereHas('user', function ($query) use ($term): void {
                $query->where('name', 'ilike', '%'.$term.'%')
                    ->orWhere('email', 'ilike', '%'.$term.'%');
            });
        }

        // Ringkasan dihitung di basis data dan tidak ikut filter: admin perlu
        // angka keseluruhan (saldo yang masih beredar) saat memeriksa satu
        // pengguna atau satu jenis transaksi.
        $summary = [
            'total_balance' => (float) DB::table('users')->sum('wallet_balance'),
            'total_topped_up' => (float) WalletTransaction::query()
                ->where('type', WalletTransaction::TYPE_TOPUP)
                ->where('status', WalletTransaction::STATUS_SUCCESS)
                ->sum('amount'),
            'total_spent' => (float) WalletTransaction::query()
                ->whereIn('type', WalletTransaction::TYPES_OUT)
                ->where('status', WalletTransaction::STATUS_SUCCESS)
                ->sum('amount'),
        ];

        return WalletTransactionResource::collection(
            $query->orderByDesc('id')->paginate(24)->withQueryString()
        )->additional(['summary' => $summary]);
    }
}
