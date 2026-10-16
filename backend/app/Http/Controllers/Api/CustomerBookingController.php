<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CancelLoanRequest;
use App\Http\Requests\Api\StoreCustomerBookingRequest;
use App\Http\Resources\CustomerBookingResource;
use App\Models\Loan;
use App\Models\OrganizationPolicy;
use App\Services\Audit\AuditRecorder;
use App\Services\Lending\LoanService;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Pemesanan oleh customer.
 *
 * Customer hanya butuh akun, bukan membership di organisasi provider, jadi rute
 * ini berada di luar konteks tenant dan konteks providernya diisi
 * ResolveProviderContext (Fase 1). Karena itu loan selalu terikat pada satu
 * provider aktif dan terverifikasi.
 *
 * Riwayat membaca lintas provider, jadi scope tenant sengaja dilewati di satu
 * tempat itu dan langsung dipersempit ke `borrower_id` pemilik akun.
 */
class CustomerBookingController extends Controller
{
    public function __construct(private OrganizationContext $context) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $loans = Loan::query()
            ->withoutOrganizationScope()
            ->where('borrower_id', $request->user()->getKey())
            ->with(['organization', 'latestPayment'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return CustomerBookingResource::collection($loans);
    }

    public function store(
        StoreCustomerBookingRequest $request,
        LoanService $service,
        AuditRecorder $audit,
    ): JsonResponse {
        $this->authorize('create', Loan::class);

        $data = $request->validated();
        $policy = OrganizationPolicy::resolve($this->context->id());

        $loan = $service->create(
            $request->user(),
            [
                'starts_at' => Carbon::parse($data['starts_at']),
                'ends_at' => Carbon::parse($data['ends_at']),
                'purpose' => $data['purpose'] ?? null,
            ],
            array_map('intval', $data['assets']),
            $audit,
            autoApprove: ! $policy->require_approval,
        );

        $loan->load('organization');

        return (new CustomerBookingResource($loan))->response()->setStatusCode(201);
    }

    /**
     * Pembatalan oleh customer (keputusan 12: tanpa biaya, sebelum persetujuan).
     *
     * Otorisasi memakai `LoanPolicy::cancel` yang sudah ada, dan penulisan
     * memakai `LoanService::cancel` apa adanya. Bedanya hanya satu: setelah
     * disetujui, jadwal sudah dikunci untuk customer, jadi pembatalan
     * selanjutnya lewat penyedia. Pembayaran baru ada di Fase 4, dan membatalkan
     * booking yang sudah dibayar akan butuh alur refund yang belum ada.
     *
     * ponytail: pemeriksaan status di luar transaksi; persetujuan yang berbarengan
     * bisa lolos. Pindahkan ke dalam `LoanService::cancel` bila perlu presisi
     * penuh.
     */
    public function cancel(
        CancelLoanRequest $request,
        LoanService $service,
        AuditRecorder $audit,
    ): CustomerBookingResource {
        // Param rute dibaca dari request: `{provider}` dan `{loan}` mengikat
        // posisional, jadi argumen skalar akan tertukar.
        $model = Loan::query()->findOrFail((int) $request->route('loan'));

        $this->authorize('cancel', $model);

        if ($model->status !== Loan::STATUS_REQUESTED) {
            throw ValidationException::withMessages([
                'status' => 'Pengajuan yang sudah disetujui tidak bisa dibatalkan sendiri. Hubungi penyedia.',
            ]);
        }

        $cancelled = $service->cancel($model, $request->user(), $request->validated('reason'), $audit);

        $cancelled->load('organization');

        return new CustomerBookingResource($cancelled);
    }
}
