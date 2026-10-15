<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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
}
