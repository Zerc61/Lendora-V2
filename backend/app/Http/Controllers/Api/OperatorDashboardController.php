<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\LoanResource;
use App\Models\Asset;
use App\Models\Loan;
use App\Models\Membership;
use App\Models\OrganizationPolicy;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * Dashboard operator "hari ini" (Fase 7). Satu tampilan untuk antrean yang
 * butuh tindakan: menunggu diambil, jatuh tempo hari ini, terlambat, dan
 * menunggu pemeriksaan.
 *
 * Payload dibentuk langsung, bukan lewat {@see LoanResource},
 * supaya tidak memicu pembacaan kebijakan per baris (N+1) dan tetap di bawah
 * anggaran query dashboard (Testing Strategy G: 15).
 */
class OperatorDashboardController extends Controller
{
    public function today(): JsonResponse
    {
        $context = app(OrganizationContext::class);

        abort_unless(
            $context->hasRole(Membership::ROLE_OPERATOR, Membership::ROLE_ADMIN),
            403,
        );

        $policy = OrganizationPolicy::resolve($context->id());
        $cutoff = now()->subHours($policy->pickup_window_hours);
        $startOfDay = now()->startOfDay();
        $endOfDay = now()->endOfDay();

        $awaitingApproval = Loan::query()
            ->where('status', Loan::STATUS_REQUESTED);

        $awaitingPickup = Loan::query()
            ->where('status', Loan::STATUS_APPROVED)
            ->where('approved_at', '>=', $cutoff)
            ->where('starts_at', '<=', $endOfDay);

        $dueToday = Loan::query()
            ->where('status', Loan::STATUS_CHECKED_OUT)
            ->whereBetween('due_at', [$startOfDay, $endOfDay]);

        $overdue = Loan::query()
            ->where('status', Loan::STATUS_CHECKED_OUT)
            ->where('due_at', '<', $startOfDay);

        $awaitingInspection = Asset::query()
            ->where('lifecycle_status', Asset::LIFECYCLE_AWAITING_INSPECTION);

        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'counts' => [
                'awaiting_approval' => (clone $awaitingApproval)->count(),
                'awaiting_pickup' => (clone $awaitingPickup)->count(),
                'due_today' => (clone $dueToday)->count(),
                'overdue' => (clone $overdue)->count(),
                'awaiting_inspection' => (clone $awaitingInspection)->count(),
            ],
            'awaiting_approval' => $this->loans($awaitingApproval, 'created_at'),
            'awaiting_pickup' => $this->loans($awaitingPickup, 'starts_at'),
            'due_today' => $this->loans($dueToday, 'due_at'),
            'overdue' => $this->loans($overdue, 'due_at'),
            'awaiting_inspection' => $awaitingInspection
                ->orderBy('updated_at')
                ->limit(20)
                ->get(['id', 'code', 'name', 'condition', 'lifecycle_status'])
                ->map(fn (Asset $asset): array => [
                    'id' => $asset->id,
                    'code' => $asset->code,
                    'name' => $asset->name,
                    'condition' => $asset->condition,
                    'condition_label' => $asset->conditionLabel(),
                ])
                ->all(),
        ]);
    }

    /**
     * @param  Builder<Loan>  $query
     * @return list<array<string, mixed>>
     */
    private function loans(Builder $query, string $orderBy): array
    {
        return $query
            ->with('borrower:id,name')
            ->orderBy($orderBy)
            ->limit(20)
            ->get()
            ->map(fn (Loan $loan): array => [
                'id' => $loan->id,
                'status' => $loan->status,
                'status_label' => $loan->label(),
                'borrower_name' => $loan->borrower?->name,
                'starts_at' => $loan->starts_at?->toIso8601String(),
                'due_at' => $loan->due_at?->toIso8601String(),
                'is_overdue' => $loan->isOverdue(),
            ])
            ->all();
    }
}
