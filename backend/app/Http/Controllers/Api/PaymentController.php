<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Models\Loan;
use App\Models\Payment;
use App\Services\Audit\AuditRecorder;
use App\Services\Payments\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Pembayaran booking oleh customer (Tahap 4).
 *
 * Berada di luar konteks tenant, sama seperti pemesanan: customer tidak punya
 * membership, konteks provider diisi ResolveProviderContext. Loan diambil lewat
 * scope tenant itu, jadi booking provider lain tidak terlihat.
 *
 * payment_method: 'midtrans' (default) atau 'wallet'.
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

        $validated = $request->validate([
            'payment_method' => [
                'sometimes',
                'string',
                Rule::in([Payment::METHOD_MIDTRANS, Payment::METHOD_WALLET]),
            ],
        ]);

        $method = $validated['payment_method'] ?? Payment::METHOD_MIDTRANS;

        if ($method === Payment::METHOD_WALLET) {
            return new PaymentResource($service->chargeWithWallet($model, $request->user(), $audit));
        }

        return new PaymentResource($service->charge($model, $request->user(), $audit));
    }
}
