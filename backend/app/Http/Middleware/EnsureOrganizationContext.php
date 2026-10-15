<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\OrganizationContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route tenant hanya bisa diakses jika user punya organisasi aktif.
 */
class EnsureOrganizationContext
{
    public function __construct(private OrganizationContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->context->check()) {
            abort(403, 'Anda belum punya keanggotaan aktif di organisasi mana pun.');
        }

        return $next($request);
    }
}
