<?php

namespace App\Policies;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;

/**
 * Otorisasi untuk resource Organization.
 *
 * Keberadaan organisasi tidak bisa disembunyikan lewat global scope seperti
 * tabel tenant lain, jadi keanggotaan diperiksa eksplisit di sini. Resource
 * organisasi asing tetap direspons 404 oleh controller (bukan 403).
 */
class OrganizationPolicy
{
    public function view(User $user, Organization $organization): bool
    {
        return $this->membership($user, $organization) !== null;
    }

    public function update(User $user, Organization $organization): bool
    {
        return $this->membership($user, $organization, Membership::ROLE_ADMIN) !== null;
    }

    public function viewPolicy(User $user, Organization $organization): bool
    {
        return $this->membership($user, $organization, Membership::ROLE_ADMIN) !== null;
    }

    public function updatePolicy(User $user, Organization $organization): bool
    {
        return $this->membership($user, $organization, Membership::ROLE_ADMIN) !== null;
    }

    private function membership(User $user, Organization $organization, ?string $role = null): ?Membership
    {
        return Membership::withoutOrganizationScope()
            ->where('user_id', $user->getKey())
            ->where('organization_id', $organization->getKey())
            ->where('status', Membership::STATUS_ACTIVE)
            ->when($role !== null, fn ($query) => $query->where('role', $role))
            ->first();
    }
}
