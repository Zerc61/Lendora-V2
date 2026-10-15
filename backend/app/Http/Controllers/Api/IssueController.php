<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\DismissIssueRequest;
use App\Http\Requests\Api\ResolveIssueRequest;
use App\Http\Requests\Api\StoreIssueRequest;
use App\Http\Resources\IssueResource;
use App\Models\Asset;
use App\Models\Issue;
use App\Models\Membership;
use App\Services\Audit\AuditRecorder;
use App\Services\Maintenance\IssueService;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Aggregate Issue (Fase 8). Model binding tidak dipakai; controller memakai int
 * + findOrFail setelah konteks tenant terbentuk (alasan sama dengan AssetController).
 */
class IssueController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Issue::class);

        $query = Issue::query()->with(['asset', 'reporter', 'assignee', 'photos']);

        // Borrower hanya melihat laporan yang ia buat.
        if (! $this->management()) {
            $query->where('reported_by', $request->user()->getKey());
        } elseif ($reporterId = $request->integer('reported_by')) {
            $query->where('reported_by', $reporterId);
        }

        if ($assetId = $request->integer('asset_id')) {
            $query->where('asset_id', $assetId);
        }

        $this->applyFilters($query, $request);

        $query->orderByDesc('created_at')->orderByDesc('id');

        return IssueResource::collection($query->paginate(20)->withQueryString());
    }

    public function store(StoreIssueRequest $request, IssueService $service, AuditRecorder $audit): JsonResponse
    {
        $this->authorize('create', Issue::class);

        $asset = Asset::query()->findOrFail($request->integer('asset_id'));

        $issue = $service->create($request->user(), $asset, [
            'type' => $request->validated('type'),
            'severity' => $request->validated('severity'),
            'description' => $request->validated('description'),
            'loan_id' => $request->validated('loan_id'),
            'photos' => $request->photos(),
        ], $audit);

        return (new IssueResource($issue))->response()->setStatusCode(201);
    }

    public function show(int $issue): IssueResource
    {
        $model = Issue::query()
            ->with(['asset', 'reporter', 'assignee', 'photos'])
            ->findOrFail($issue);

        $this->authorize('view', $model);

        return new IssueResource($model);
    }

    public function review(int $issue, IssueService $service, AuditRecorder $audit): IssueResource
    {
        $model = Issue::query()->findOrFail($issue);

        $this->authorize('review', $model);

        return new IssueResource($service->review($model, request()->user(), $audit));
    }

    public function resolve(ResolveIssueRequest $request, int $issue, IssueService $service, AuditRecorder $audit): IssueResource
    {
        $model = Issue::query()->findOrFail($issue);

        $this->authorize('resolve', $model);

        return new IssueResource(
            $service->resolve($model, $request->user(), $request->validated('resolution'), $audit)
        );
    }

    public function dismiss(DismissIssueRequest $request, int $issue, IssueService $service, AuditRecorder $audit): IssueResource
    {
        $model = Issue::query()->findOrFail($issue);

        $this->authorize('dismiss', $model);

        return new IssueResource(
            $service->dismiss($model, $request->user(), $request->validated('reason'), $audit)
        );
    }

    private function applyFilters(Builder $query, Request $request): void
    {
        $status = $request->query('status');

        if (is_string($status) && in_array($status, Issue::STATUSES, true)) {
            $query->where('status', $status);
        }

        $severity = $request->query('severity');

        if (is_string($severity) && in_array($severity, Issue::SEVERITIES, true)) {
            $query->where('severity', $severity);
        }
    }

    private function management(): bool
    {
        return app(OrganizationContext::class)->hasRole(
            Membership::ROLE_OPERATOR,
            Membership::ROLE_ADMIN,
        );
    }
}
