<?php

namespace App\Http\Middleware;

use App\Models\Membership;
use App\Models\User;
use App\Support\Tenancy\OrganizationContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menentukan organisasi aktif dari membership user, lalu mengisi konteks.
 *
 * Sumber organisasi: session `active_organization_id` bila valid, jika tidak
 * membership aktif pertama. Membership yang tidak lagi aktif tidak dipakai,
 * sehingga akses hilang begitu keanggotaan berubah. ADR-004 aturan 6.
 */
class ResolveCurrentOrganization
{
    public function __construct(private OrganizationContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            $membership = $this->resolveMembership($request, $user);

            if ($membership?->organization !== null) {
                $this->context->set($membership->organization, $membership);
            }
        }

        try {
            return $next($request);
        } finally {
            $this->context->clear();
        }
    }

    private function resolveMembership(Request $request, User $user): ?Membership
    {
        $query = Membership::withoutOrganizationScope()
            ->where('user_id', $user->getKey())
            ->where('status', Membership::STATUS_ACTIVE)
            ->with('organization');

        if ($request->hasSession()) {
            $sessionOrganizationId = $request->session()->get('active_organization_id');

            if ($sessionOrganizationId !== null) {
                $membership = (clone $query)->where('organization_id', $sessionOrganizationId)->first();

                if ($membership !== null) {
                    return $membership;
                }

                $request->session()->forget('active_organization_id');
            }
        }

        return $query->orderBy('id')->first();
    }
}
