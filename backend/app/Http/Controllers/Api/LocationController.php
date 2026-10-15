<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreLocationRequest;
use App\Http\Requests\Api\UpdateLocationRequest;
use App\Http\Resources\LocationResource;
use App\Models\Location;
use App\Services\Audit\AuditRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lokasi berhierarki maksimal empat tingkat. Level dihitung dari parent, bukan
 * dari input. Semua anggota dapat melihat, hanya admin yang mengubah. ADR-004.
 */
class LocationController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Location::class);

        return LocationResource::collection(
            Location::query()->orderBy('level')->orderBy('name')->paginate(100)
        );
    }

    public function store(StoreLocationRequest $request, AuditRecorder $audit): JsonResponse
    {
        $this->authorize('create', Location::class);

        $data = $request->validated();
        $parent = $this->resolveParent($data['parent_id'] ?? null);
        $level = Location::levelFor($parent);

        if ($level > Location::MAX_LEVEL) {
            throw ValidationException::withMessages([
                'parent_id' => 'Hierarki lokasi maksimal empat tingkat.',
            ]);
        }

        $location = DB::transaction(function () use ($data, $parent, $level, $audit): Location {
            $location = Location::create([
                'name' => $data['name'],
                'parent_id' => $parent?->getKey(),
                'level' => $level,
            ]);

            $audit->record(
                action: 'location.created',
                subject: $location,
                after: $this->auditable($location),
                organization: $location->organization,
            );

            return $location;
        });

        return (new LocationResource($location))->response()->setStatusCode(201);
    }

    public function show(int $location): LocationResource
    {
        $model = Location::query()->findOrFail($location);

        $this->authorize('view', $model);

        return new LocationResource($model);
    }

    public function update(
        UpdateLocationRequest $request,
        int $location,
        AuditRecorder $audit,
    ): LocationResource {
        $model = Location::query()->findOrFail($location);

        $this->authorize('update', $model);

        $data = $request->validated();
        $parentChanged = $request->exists('parent_id');
        $newParent = $parentChanged ? $this->resolveParent($data['parent_id'] ?? null) : null;

        if ($newParent !== null && ($newParent->is($model) || $this->isDescendantOf($model, $newParent))) {
            throw ValidationException::withMessages([
                'parent_id' => 'Lokasi tidak bisa dipindahkan ke bawah dirinya sendiri.',
            ]);
        }

        $newLevel = $parentChanged ? Location::levelFor($newParent) : $model->level;

        if ($newLevel + $model->subtreeHeight() > Location::MAX_LEVEL) {
            throw ValidationException::withMessages([
                'parent_id' => 'Pemindahan ini melebihi kedalaman maksimal empat tingkat.',
            ]);
        }

        $before = $this->auditable($model);

        DB::transaction(function () use ($model, $data, $parentChanged, $newParent, $newLevel, $before, $audit): void {
            $model->fill($data);

            if ($parentChanged) {
                $model->parent_id = $newParent?->getKey();
                $model->level = $newLevel;
            }

            $originalLevel = $model->getOriginal('level');
            $model->save();

            if ($model->level !== $originalLevel) {
                $this->cascadeLevels($model, $model->level - $originalLevel);
            }

            $audit->record(
                action: 'location.updated',
                subject: $model,
                before: $before,
                after: $this->auditable($model),
                organization: $model->organization,
            );
        });

        return new LocationResource($model);
    }

    public function destroy(int $location, AuditRecorder $audit): JsonResponse
    {
        $model = Location::query()->findOrFail($location);

        $this->authorize('delete', $model);

        if ($model->children()->exists() || $model->assets()->exists()) {
            throw ValidationException::withMessages([
                'location' => 'Lokasi ini sudah dipakai dan tidak bisa dihapus.',
            ]);
        }

        DB::transaction(function () use ($model, $audit): void {
            $audit->record(
                action: 'location.deleted',
                subject: $model,
                before: $this->auditable($model),
                organization: $model->organization,
            );

            $model->delete();
        });

        return response()->json(['message' => 'Lokasi dihapus.']);
    }

    private function resolveParent(?int $parentId): ?Location
    {
        if ($parentId === null) {
            return null;
        }

        return Location::query()->findOrFail($parentId);
    }

    private function isDescendantOf(Location $ancestor, Location $candidate): bool
    {
        $node = $candidate->parent;

        while ($node !== null) {
            if ($node->is($ancestor)) {
                return true;
            }

            $node = $node->parent;
        }

        return false;
    }

    private function cascadeLevels(Location $location, int $delta): void
    {
        foreach ($location->children()->get() as $child) {
            $child->level = $child->level + $delta;
            $child->save();

            $this->cascadeLevels($child, $delta);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function auditable(Location $location): array
    {
        return $location->only(['name', 'parent_id', 'level']);
    }
}
