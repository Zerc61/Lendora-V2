<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpdateOrganizationRequest;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use App\Services\Audit\AuditRecorder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * Organisasi hanya terlihat oleh anggotanya. Organisasi asing menghasilkan 404,
 * bukan 403, supaya keberadaannya tidak terkonfirmasi. ADR-004.
 */
class OrganizationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $organizations = Organization::query()
            ->visibleTo($request->user())
            ->orderBy('name')
            ->get();

        return OrganizationResource::collection($organizations);
    }

    public function show(Request $request, int $organization): OrganizationResource
    {
        $model = Organization::query()
            ->visibleTo($request->user())
            ->findOrFail($organization);

        $this->authorize('view', $model);

        return new OrganizationResource($model);
    }

    /**
     * Profil provider diubah admin organisasi sendiri. Verifikasi provider bukan
     * bagian dari endpoint ini.
     */
    public function update(UpdateOrganizationRequest $request, int $organization, AuditRecorder $audit): OrganizationResource
    {
        $model = Organization::query()
            ->visibleTo($request->user())
            ->findOrFail($organization);

        $this->authorize('update', $model);

        $data = $request->validated();

        DB::transaction(function () use ($model, $data, $audit): void {
            $before = $model->only(array_keys($data));

            $model->fill($data)->save();

            $audit->record(
                action: 'organization.profile_updated',
                subject: $model,
                before: $before,
                after: $data,
                organization: $model,
            );
        });

        return new OrganizationResource($model);
    }
}
