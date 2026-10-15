<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\InspectAssetRequest;
use App\Http\Resources\AssetConditionRecordResource;
use App\Http\Resources\AssetResource;
use App\Models\Asset;
use App\Services\Audit\AuditRecorder;
use App\Services\Lending\OperationsService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Inspeksi aset setelah pengembalian dan riwayat kondisinya (Fase 7).
 * Riwayat sifatnya kronologis; tidak ada endpoint ubah atau hapus karena record
 * append-only ditegakkan database.
 */
class InspectionController extends Controller
{
    public function index(int $asset): AnonymousResourceCollection
    {
        $model = Asset::query()->findOrFail($asset);

        $this->authorize('viewConditionRecords', $model);

        $records = $model->conditionRecords()
            ->with(['inspector', 'attachments'])
            ->orderByDesc('inspected_at')
            ->orderByDesc('id')
            ->paginate(30);

        return AssetConditionRecordResource::collection($records);
    }

    public function store(
        InspectAssetRequest $request,
        int $asset,
        OperationsService $service,
        AuditRecorder $audit,
    ): AssetResource {
        $model = Asset::query()->findOrFail($asset);

        $this->authorize('inspect', $model);

        $updated = $service->inspect($model, $request->user(), $request->validated('notes'), $audit);

        return new AssetResource($updated->load(['category', 'location']));
    }
}
