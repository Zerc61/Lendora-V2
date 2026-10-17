<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Models\Loan;
use App\Models\Membership;
use App\Models\Payment;
use App\Services\Audit\AuditRecorder;
use App\Services\Payments\PaymentService;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Http\Request;

/**
 * Pembayaran sewa oleh penyewa (Tahap 4).
 *
 * Dipakai dari dua tempat: situs publik (`/providers/{provider}/bookings/{loan}`
 * dengan ResolveProviderContext, untuk pemesan tanpa membership) dan aplikasi
 * (`/loans/{loan}/payment` dengan konteks organisasi, dipakai halaman detail
 * peminjaman). Keduanya memakai policy `view` pada loan, jadi pemesan lain
 * tidak bisa membayar.
 *
 * Hanya saldo dompet: penyewa mengisi dompet dulu, baru membayar sewa. Midtrans
 * tetap dipakai untuk isi saldo, bukan untuk membayar sewa.
 */
class PaymentController extends Controller
{
    public function store(
        Request $request,
        PaymentService $service,
        AuditRecorder $audit,
    ): PaymentResource {
        $model = Loan::query()->findOrFail((int) $request->route('loan'));

        $this->authorize('view', $model);

        $request->validate([
            'payment_method' => ['sometimes', 'string', 'in:'.Payment::METHOD_WALLET],
        ]);

        return new PaymentResource($service->chargeWithWallet($model, $request->user(), $audit));
    }

    /**
     * Verifikasi dana dari sisi penyedia (tombol di halaman peminjaman).
     *
     * Pembayaran lunas belum boleh diserahkan sebelum staf memastikan uangnya
     * masuk. Pembayaran diambil lewat scope tenant, jadi organisasi lain tidak
     * terlihat.
     */
    public function verify(
        Request $request,
        PaymentService $service,
        AuditRecorder $audit,
        OrganizationContext $context,
    ): PaymentResource {
        abort_unless(
            $context->hasRole(Membership::ROLE_ADMIN, Membership::ROLE_OPERATOR),
            403,
        );

        $model = Payment::query()->findOrFail((int) $request->route('payment'));

        return new PaymentResource($service->verify($model, $request->user(), $audit));
    }
}
