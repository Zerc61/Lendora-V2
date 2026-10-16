<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Support\Tenancy\OrganizationContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menetapkan konteks tenant dari provider yang eksplisit, tanpa membership.
 *
 * Dipakai hanya oleh permukaan publik dan customer. `OrganizationContext` sudah
 * menerima membership null, dan global scope tenant hanya membaca id konteks,
 * sehingga seluruh mesin (ketersediaan, loan, policy) bekerja tanpa perubahan.
 *
 * Provider yang tidak aktif atau belum terverifikasi menghasilkan 404, bukan
 * 403, supaya keberadaannya tidak terkonfirmasi.
 */
class ResolveProviderContext
{
    public function __construct(private OrganizationContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $slug = $request->route('provider');

        $organization = is_string($slug)
            ? Organization::query()->publiclyVisible()->where('slug', $slug)->first()
            : null;

        abort_if($organization === null, 404);

        $this->context->set($organization);

        return $next($request);
    }
}
