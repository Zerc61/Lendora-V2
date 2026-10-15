<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\RetireAssetRequest;
use App\Http\Requests\Api\StoreAssetRequest;
use App\Http\Requests\Api\UpdateAssetRequest;
use App\Http\Resources\AssetResource;
use App\Models\Asset;
use App\Models\Attachment;
use App\Models\Membership;
use App\Services\Audit\AuditRecorder;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Katalog aset. Isolasi lintas organisasi lewat global scope (404). Model binding
 * tidak dipakai karena SubstituteBindings berjalan sebelum konteks tenant; findOrFail
 * di dalam controller berjalan saat scope sudah fail-closed pada organisasi aktif.
 */
class AssetController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Asset::class);

        $management = $this->management();

        $query = Asset::query()->with(['category', 'location']);

        // Aset retired hanya muncul bila operator atau admin memintanya eksplisit.
        $status = $request->query('status');

        if (is_string($status) && in_array($status, Asset::LIFECYCLE_STATUSES, true)) {
            if ($status === Asset::LIFECYCLE_RETIRED && ! $management) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where('lifecycle_status', $status);
            }
        } else {
            $query->inCatalog();
        }

        if ($categoryId = $request->integer('category_id')) {
            $query->where('category_id', $categoryId);
        }

        if ($locationId = $request->integer('location_id')) {
            $query->where('location_id', $locationId);
        }

        // Kondisi hanya relevan bagi operator dan admin. Asset Management C.1.
        $condition = $request->query('condition');

        if ($management && is_string($condition) && in_array($condition, Asset::CONDITIONS, true)) {
            $query->where('condition', $condition);
        }

        $term = trim((string) $request->query('q'));

        if ($term !== '') {
            $query->search($term);
        }

        $direction = $request->query('direction') === 'desc' ? 'desc' : 'asc';

        match ($request->query('sort')) {
            'condition' => $query->orderBy('condition', $direction),
            'created_at' => $query->orderBy('created_at', $direction),
            default => $query->orderBy('name', $direction),
        };

        $query->orderBy('id');

        return AssetResource::collection($query->paginate(24)->withQueryString());
    }

    public function show(int $asset): AssetResource
    {
        $model = Asset::query()->with(['category', 'location', 'photos'])->findOrFail($asset);

        $this->authorize('view', $model);

        return new AssetResource($model);
    }

    public function store(StoreAssetRequest $request, AuditRecorder $audit): JsonResponse
    {
        $this->authorize('create', Asset::class);

        $data = $request->validated();
        $photos = $this->photosFrom($request);
        unset($data['photos']);

        $asset = DB::transaction(function () use ($data, $photos, $audit): Asset {
            $asset = new Asset($data);
            $asset->lifecycle_status = Asset::LIFECYCLE_ACTIVE;
            $asset->condition = Asset::CONDITION_GOOD;
            $asset->save();

            $this->storePhotos($asset, $photos);

            $audit->record(
                action: 'asset.created',
                subject: $asset,
                after: $this->auditable($asset),
                organization: $asset->organization,
            );

            return $asset;
        });

        return (new AssetResource($asset->load(['category', 'location', 'photos'])))
            ->response()
            ->setStatusCode(201);
    }

    public function update(
        UpdateAssetRequest $request,
        int $asset,
        AuditRecorder $audit,
    ): AssetResource {
        $model = Asset::query()->findOrFail($asset);

        $this->authorize('update', $model);
        $this->guardImmutableFields($request, $model);

        $data = $request->validated();
        $photos = $this->photosFrom($request);
        unset($data['photos']);

        if ($photos !== [] && $model->photos()->count() + count($photos) > Asset::MAX_PHOTOS) {
            throw ValidationException::withMessages([
                'photos' => 'Maksimal '.Asset::MAX_PHOTOS.' foto per aset.',
            ]);
        }

        $before = $this->auditable($model);

        DB::transaction(function () use ($model, $data, $photos, $before, $audit): void {
            $model->fill($data)->save();
            $this->storePhotos($model, $photos);

            $audit->record(
                action: 'asset.updated',
                subject: $model,
                before: $before,
                after: $this->auditable($model),
                organization: $model->organization,
            );
        });

        return new AssetResource($model->load(['category', 'location', 'photos']));
    }

    public function retire(RetireAssetRequest $request, int $asset, AuditRecorder $audit): AssetResource
    {
        $model = Asset::query()->findOrFail($asset);

        $this->authorize('retire', $model);

        if ($model->isRetired()) {
            throw ValidationException::withMessages([
                'asset' => 'Aset sudah dihentikan pemakaiannya dan tidak bisa diubah lagi.',
            ]);
        }

        // Catatan: syarat "tidak ada loan aktif" dan "tidak ada ticket blocking"
        // menunggu Fase 6 dan Fase 7. Asset Lifecycle D.4.
        $data = $request->validated();

        DB::transaction(function () use ($model, $data, $audit): void {
            $before = ['lifecycle_status' => $model->lifecycle_status];

            $model->lifecycle_status = Asset::LIFECYCLE_RETIRED;
            $model->save();

            $audit->record(
                action: 'asset.retired',
                subject: $model,
                before: $before,
                after: ['lifecycle_status' => $model->lifecycle_status],
                context: ['reason' => $data['reason']],
                organization: $model->organization,
            );
        });

        return new AssetResource($model->load(['category', 'location', 'photos']));
    }

    public function destroy(int $asset, AuditRecorder $audit): JsonResponse
    {
        $model = Asset::query()->findOrFail($asset);

        $this->authorize('delete', $model);

        DB::transaction(function () use ($model, $audit): void {
            $audit->record(
                action: 'asset.deleted',
                subject: $model,
                before: $this->auditable($model),
                organization: $model->organization,
            );

            $model->delete();
        });

        return response()->json(['message' => 'Aset dihapus.']);
    }

    /**
     * Kode, serial number, lifecycle status, dan kondisi tidak bisa diubah dari
     * form aset. Asset Management C.6 dan Business Rules AS-2.
     */
    private function guardImmutableFields(Request $request, Asset $asset): void
    {
        if ($request->has('code') && $request->input('code') !== $asset->code) {
            throw ValidationException::withMessages(['code' => 'Kode aset tidak bisa diubah.']);
        }

        if ($request->has('serial_number') && $request->input('serial_number') !== $asset->serial_number) {
            throw ValidationException::withMessages(['serial_number' => 'Serial number tidak bisa diubah.']);
        }

        if ($request->has('lifecycle_status') || $request->has('condition')) {
            throw ValidationException::withMessages([
                'lifecycle_status' => 'Lifecycle status dan kondisi hanya berubah lewat inspeksi atau maintenance.',
            ]);
        }
    }

    /**
     * @return list<UploadedFile>
     */
    private function photosFrom(Request $request): array
    {
        $files = $request->file('photos', []);

        if ($files instanceof UploadedFile) {
            return [$files];
        }

        return is_array($files) ? array_values($files) : [];
    }

    /**
     * @param  list<UploadedFile>  $photos
     */
    private function storePhotos(Asset $asset, array $photos): void
    {
        foreach ($photos as $file) {
            $path = $file->store('attachments/'.$asset->organization_id);

            Attachment::create([
                'attachable_type' => $asset->getMorphClass(),
                'attachable_id' => $asset->getKey(),
                'type' => Attachment::TYPE_PHOTO,
                'path' => $path,
                'mime_type' => $file->getMimeType() ?? $file->getClientMimeType(),
                'size' => $file->getSize(),
                'uploaded_by' => auth()->id(),
            ]);
        }
    }

    private function management(): bool
    {
        return app(OrganizationContext::class)->hasRole(
            Membership::ROLE_OPERATOR,
            Membership::ROLE_ADMIN,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function auditable(Asset $asset): array
    {
        return $asset->only([
            'code',
            'name',
            'description',
            'category_id',
            'location_id',
            'lifecycle_status',
            'condition',
            'serial_number',
            'purchase_date',
            'purchase_price',
            'warranty_until',
            'notes',
        ]);
    }
}
