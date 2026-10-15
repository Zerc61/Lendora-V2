<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CancelLoanRequest;
use App\Http\Requests\Api\CheckinLoanRequest;
use App\Http\Requests\Api\CheckoutLoanRequest;
use App\Http\Requests\Api\RejectLoanRequest;
use App\Http\Requests\Api\StoreLoanRequest;
use App\Http\Resources\LoanResource;
use App\Models\Loan;
use App\Models\Membership;
use App\Models\OrganizationPolicy;
use App\Services\Audit\AuditRecorder;
use App\Services\Lending\LoanService;
use App\Services\Lending\OperationsService;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Aggregate Loan. Model binding tidak dipakai; controller memakai int + findOrFail
 * setelah konteks tenant terbentuk (alasan sama dengan AssetController).
 */
class LoanController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Loan::class);

        $management = $this->management();
        $policy = OrganizationPolicy::resolve(app(OrganizationContext::class)->id());
        $cutoff = now()->subHours($policy->pickup_window_hours);

        $query = Loan::query()->with(['items.asset', 'borrower']);

        if (! $management) {
            $query->where('borrower_id', $request->user()->getKey());
        } elseif ($borrowerId = $request->integer('borrower_id')) {
            $query->where('borrower_id', $borrowerId);
        }

        $status = $request->query('status');

        if (is_string($status) && in_array($status, Loan::STATUSES, true)) {
            $query->where('status', $status);
        } else {
            match ($request->query('group')) {
                'pending' => $query->where('status', Loan::STATUS_REQUESTED),
                'active' => $query->where(function ($query) use ($cutoff): void {
                    $query->where('status', Loan::STATUS_CHECKED_OUT)
                        ->orWhere(fn ($query) => $query
                            ->where('status', Loan::STATUS_APPROVED)
                            ->where('approved_at', '>=', $cutoff));
                }),
                'history' => $query->where(function ($query) use ($cutoff): void {
                    $query->whereIn('status', [
                        Loan::STATUS_REJECTED,
                        Loan::STATUS_CANCELLED,
                        Loan::STATUS_RETURNED,
                    ])->orWhere(fn ($query) => $query
                        ->where('status', Loan::STATUS_APPROVED)
                        ->where('approved_at', '<', $cutoff));
                }),
                default => null,
            };
        }

        $query->orderByDesc('created_at')->orderByDesc('id');

        return LoanResource::collection($query->paginate(20)->withQueryString());
    }

    public function store(StoreLoanRequest $request, LoanService $service, AuditRecorder $audit): JsonResponse
    {
        $this->authorize('create', Loan::class);

        $data = $request->validated();

        $loan = $service->create(
            $request->user(),
            [
                'starts_at' => Carbon::parse($data['starts_at']),
                'ends_at' => Carbon::parse($data['ends_at']),
                'purpose' => $data['purpose'] ?? null,
            ],
            array_map('intval', $data['assets']),
            $audit,
        );

        return (new LoanResource($loan))->response()->setStatusCode(201);
    }

    public function show(int $loan): LoanResource
    {
        $with = ['items.asset', 'borrower'];

        // Riwayat kondisi hanya untuk operator dan admin (D-33).
        if ($this->management()) {
            $with[] = 'conditionRecords.inspector';
            $with[] = 'conditionRecords.attachments';
        }

        $model = Loan::query()->with($with)->findOrFail($loan);

        $this->authorize('view', $model);

        return new LoanResource($model);
    }

    public function approve(int $loan, LoanService $service, AuditRecorder $audit): LoanResource
    {
        $model = Loan::query()->findOrFail($loan);

        $this->authorize('approve', $model);

        return new LoanResource($service->approve($model, request()->user(), $audit));
    }

    public function reject(RejectLoanRequest $request, int $loan, LoanService $service, AuditRecorder $audit): LoanResource
    {
        $model = Loan::query()->findOrFail($loan);

        $this->authorize('reject', $model);

        return new LoanResource(
            $service->reject($model, $request->user(), $request->validated('reason'), $audit)
        );
    }

    public function cancel(CancelLoanRequest $request, int $loan, LoanService $service, AuditRecorder $audit): LoanResource
    {
        $model = Loan::query()->findOrFail($loan);

        $this->authorize('cancel', $model);

        $reason = $request->validated('reason');

        // Alasan wajib ketika admin membatalkan loan milik orang lain (Lending C.4).
        if ($this->context()->hasRole(Membership::ROLE_ADMIN)
            && $model->borrower_id !== $request->user()->getKey()
            && ($reason === null || trim($reason) === '')) {
            throw ValidationException::withMessages([
                'reason' => 'Alasan wajib diisi ketika membatalkan peminjaman orang lain.',
            ]);
        }

        return new LoanResource($service->cancel($model, $request->user(), $reason, $audit));
    }

    public function checkout(CheckoutLoanRequest $request, int $loan, OperationsService $service, AuditRecorder $audit): LoanResource
    {
        $model = Loan::query()->findOrFail($loan);

        $this->authorize('checkout', $model);

        return new LoanResource($service->checkout($model, $request->user(), $request->items(), $audit));
    }

    public function checkin(CheckinLoanRequest $request, int $loan, OperationsService $service, AuditRecorder $audit): LoanResource
    {
        $model = Loan::query()->findOrFail($loan);

        $this->authorize('checkin', $model);

        return new LoanResource($service->checkin($model, $request->user(), $request->items(), $audit));
    }

    private function management(): bool
    {
        return $this->context()->hasRole(Membership::ROLE_OPERATOR, Membership::ROLE_ADMIN);
    }

    private function context(): OrganizationContext
    {
        return app(OrganizationContext::class);
    }
}
