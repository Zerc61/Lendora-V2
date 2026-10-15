<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Services\Analytics\AnalyticsService;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;

/**
 * Ringkasan admin (Fase 9): gambaran kondisi aset, utilisasi ringkas, antrean
 * maintenance, dan komposisi anggota. Hanya admin; operator punya dashboard
 * "hari ini" sendiri.
 */
class AdminOverviewController extends Controller
{
    public function index(AnalyticsService $analytics): JsonResponse
    {
        abort_unless(
            app(OrganizationContext::class)->hasRole(Membership::ROLE_ADMIN),
            403,
        );

        return response()->json($analytics->overview());
    }
}
