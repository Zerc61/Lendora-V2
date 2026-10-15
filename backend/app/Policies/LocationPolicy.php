<?php

namespace App\Policies;

use App\Models\Location;
use App\Models\Membership;
use App\Models\User;
use App\Support\Tenancy\OrganizationContext;

/**
 * Otorisasi resource Location. Semua anggota organisasi dapat melihat (dipakai
 * untuk filter), hanya admin yang mengubah. ADR-004.
 */
class LocationPolicy
{
    public function __construct(private OrganizationContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->hasRole(
            Membership::ROLE_BORROWER,
            Membership::ROLE_OPERATOR,
            Membership::ROLE_ADMIN,
        );
    }

    public function view(User $user, Location $location): bool
    {
        return $this->sameOrganization($location);
    }

    public function create(User $user): bool
    {
        return $this->context->hasRole(Membership::ROLE_ADMIN);
    }

    public function update(User $user, Location $location): bool
    {
        return $this->sameOrganization($location)
            && $this->context->hasRole(Membership::ROLE_ADMIN);
    }

    public function delete(User $user, Location $location): bool
    {
        return $this->sameOrganization($location)
            && $this->context->hasRole(Membership::ROLE_ADMIN);
    }

    private function sameOrganization(Location $location): bool
    {
        return $this->context->check()
            && $this->context->id() === $location->organization_id;
    }
}
