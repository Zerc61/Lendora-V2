<?php

namespace App\Policies;

use App\Models\Membership;
use App\Models\User;
use App\Support\Tenancy\OrganizationContext;

/**
 * Otorisasi untuk resource Membership.
 *
 * Isolasi tenant lintas organisasi sudah ditangani global scope (menghasilkan
 * 404). Policy ini menangani 403: resource ada di organisasi yang sama, tapi
 * role user tidak cukup. ADR-004.
 */
class MembershipPolicy
{
    public function __construct(private OrganizationContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->hasRole(Membership::ROLE_ADMIN, Membership::ROLE_OPERATOR);
    }

    public function view(User $user, Membership $membership): bool
    {
        if (! $this->sameOrganization($membership)) {
            return false;
        }

        return $this->context->hasRole(Membership::ROLE_ADMIN, Membership::ROLE_OPERATOR)
            || $membership->user_id === $user->getKey();
    }

    public function create(User $user): bool
    {
        return $this->context->hasRole(Membership::ROLE_ADMIN);
    }

    public function update(User $user, Membership $membership): bool
    {
        return $this->sameOrganization($membership)
            && $this->context->hasRole(Membership::ROLE_ADMIN);
    }

    public function delete(User $user, Membership $membership): bool
    {
        return $this->sameOrganization($membership)
            && $this->context->hasRole(Membership::ROLE_ADMIN);
    }

    private function sameOrganization(Membership $membership): bool
    {
        return $this->context->check()
            && $this->context->id() === $membership->organization_id;
    }
}
