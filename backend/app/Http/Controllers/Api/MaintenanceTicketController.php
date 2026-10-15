<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AssignMaintenanceTicketRequest;
use App\Http\Requests\Api\CancelMaintenanceRequest;
use App\Http\Requests\Api\ProgressMaintenanceRequest;
use App\Http\Requests\Api\StoreMaintenanceTicketRequest;
use App\Http\Requests\Api\VerifyMaintenanceRequest;
use App\Http\Resources\MaintenanceTicketResource;
use App\Models\Asset;
use App\Models\MaintenanceTicket;
use App\Services\Audit\AuditRecorder;
use App\Services\Maintenance\MaintenanceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Aggregate MaintenanceTicket (Fase 8). Perbaikan adalah pekerjaan operator;
 * borrower tidak memiliki akses. Setiap transisi menulis satu MaintenanceLog
 * append-only dan menyinkronkan lifecycle aset.
 */
class MaintenanceTicketController extends Controller
{
    private const RELATIONS = [
        'asset',
        'issue',
        'assignee',
        'logs.actor',
    ];

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', MaintenanceTicket::class);

        $query = MaintenanceTicket::query()->with(self::RELATIONS);

        if ($assetId = $request->integer('asset_id')) {
            $query->where('asset_id', $assetId);
        }

        $this->applyFilters($query, $request);

        $query->orderByDesc('created_at')->orderByDesc('id');

        return MaintenanceTicketResource::collection($query->paginate(20)->withQueryString());
    }

    public function store(
        StoreMaintenanceTicketRequest $request,
        int $asset,
        MaintenanceService $service,
        AuditRecorder $audit,
    ): JsonResponse {
        $this->authorize('create', MaintenanceTicket::class);

        $model = Asset::query()->findOrFail($asset);

        $ticket = $service->create($request->user(), $model, [
            'issue_id' => $request->validated('issue_id'),
            'priority' => $request->validated('priority'),
            'description' => $request->validated('description'),
            'scheduled_start' => $request->validated('scheduled_start'),
            'scheduled_end' => $request->validated('scheduled_end'),
            'estimated_cost' => $request->validated('estimated_cost'),
        ], $audit);

        return (new MaintenanceTicketResource($ticket))->response()->setStatusCode(201);
    }

    public function show(int $ticket): MaintenanceTicketResource
    {
        $model = MaintenanceTicket::query()
            ->with(self::RELATIONS)
            ->with('conditionRecords.attachments')
            ->findOrFail($ticket);

        $this->authorize('view', $model);

        return new MaintenanceTicketResource($model);
    }

    public function assign(
        AssignMaintenanceTicketRequest $request,
        int $ticket,
        MaintenanceService $service,
        AuditRecorder $audit,
    ): MaintenanceTicketResource {
        $model = MaintenanceTicket::query()->findOrFail($ticket);

        $this->authorize('assign', $model);

        $assigneeId = $request->validated('assigned_to');

        return new MaintenanceTicketResource(
            $service->assign($model, $request->user(), $assigneeId !== null ? (int) $assigneeId : null, $audit)
        );
    }

    public function start(int $ticket, MaintenanceService $service, AuditRecorder $audit): MaintenanceTicketResource
    {
        $model = MaintenanceTicket::query()->findOrFail($ticket);

        $this->authorize('work', $model);

        return new MaintenanceTicketResource($service->start($model, request()->user(), $audit));
    }

    public function awaitParts(
        ProgressMaintenanceRequest $request,
        int $ticket,
        MaintenanceService $service,
        AuditRecorder $audit,
    ): MaintenanceTicketResource {
        $model = MaintenanceTicket::query()->findOrFail($ticket);

        $this->authorize('work', $model);

        return new MaintenanceTicketResource(
            $service->awaitParts($model, $request->user(), $request->validated('note'), $audit)
        );
    }

    public function complete(
        ProgressMaintenanceRequest $request,
        int $ticket,
        MaintenanceService $service,
        AuditRecorder $audit,
    ): MaintenanceTicketResource {
        $model = MaintenanceTicket::query()->findOrFail($ticket);

        $this->authorize('work', $model);

        return new MaintenanceTicketResource(
            $service->complete($model, $request->user(), $request->validated('note'), $audit)
        );
    }

    public function verify(
        VerifyMaintenanceRequest $request,
        int $ticket,
        MaintenanceService $service,
        AuditRecorder $audit,
    ): MaintenanceTicketResource {
        $model = MaintenanceTicket::query()->findOrFail($ticket);

        $this->authorize('verify', $model);

        return new MaintenanceTicketResource(
            $service->verify($model, $request->user(), [
                'condition' => $request->validated('condition'),
                'notes' => $request->validated('notes'),
                'photos' => $request->photos(),
            ], $audit)
        );
    }

    public function cancel(
        CancelMaintenanceRequest $request,
        int $ticket,
        MaintenanceService $service,
        AuditRecorder $audit,
    ): MaintenanceTicketResource {
        $model = MaintenanceTicket::query()->findOrFail($ticket);

        $this->authorize('cancel', $model);

        return new MaintenanceTicketResource(
            $service->cancel($model, $request->user(), $request->validated('reason'), $audit)
        );
    }

    private function applyFilters(Builder $query, Request $request): void
    {
        $status = $request->query('status');

        if (is_string($status) && in_array($status, MaintenanceTicket::STATUSES, true)) {
            $query->where('status', $status);
        }

        $priority = $request->query('priority');

        if (is_string($priority) && in_array($priority, MaintenanceTicket::PRIORITIES, true)) {
            $query->where('priority', $priority);
        }
    }
}
