<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreCategoryRequest;
use App\Http\Requests\Api\UpdateCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Services\Audit\AuditRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Kategori aset. Semua anggota dapat melihat (dipakai untuk filter), hanya admin
 * yang mengubah. Isolasi lintas organisasi lewat global scope (404). ADR-004.
 */
class CategoryController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Category::class);

        return CategoryResource::collection(
            Category::query()->orderBy('name')->paginate(50)
        );
    }

    public function store(StoreCategoryRequest $request, AuditRecorder $audit): JsonResponse
    {
        $this->authorize('create', Category::class);

        $category = DB::transaction(function () use ($request, $audit): Category {
            $category = Category::create($request->validated());

            $audit->record(
                action: 'category.created',
                subject: $category,
                after: $this->auditable($category),
                organization: $category->organization,
            );

            return $category;
        });

        return (new CategoryResource($category))->response()->setStatusCode(201);
    }

    public function show(int $category): CategoryResource
    {
        $model = Category::query()->findOrFail($category);

        $this->authorize('view', $model);

        return new CategoryResource($model);
    }

    public function update(
        UpdateCategoryRequest $request,
        int $category,
        AuditRecorder $audit,
    ): CategoryResource {
        $model = Category::query()->findOrFail($category);

        $this->authorize('update', $model);

        $data = $request->validated();
        $before = $this->auditable($model);

        DB::transaction(function () use ($model, $data, $audit, $before): void {
            $model->fill($data)->save();

            $audit->record(
                action: 'category.updated',
                subject: $model,
                before: $before,
                after: $this->auditable($model),
                organization: $model->organization,
            );
        });

        return new CategoryResource($model);
    }

    public function destroy(int $category, AuditRecorder $audit): JsonResponse
    {
        $model = Category::query()->findOrFail($category);

        $this->authorize('delete', $model);

        if ($model->assets()->exists()) {
            throw ValidationException::withMessages([
                'category' => 'Kategori ini sudah dipakai dan tidak bisa dihapus.',
            ]);
        }

        DB::transaction(function () use ($model, $audit): void {
            $audit->record(
                action: 'category.deleted',
                subject: $model,
                before: $this->auditable($model),
                organization: $model->organization,
            );

            $model->delete();
        });

        return response()->json(['message' => 'Kategori dihapus.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function auditable(Category $category): array
    {
        return $category->only(['name', 'description']);
    }
}
