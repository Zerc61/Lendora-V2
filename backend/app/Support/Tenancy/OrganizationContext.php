<?php

namespace App\Support\Tenancy;

use App\Models\Membership;
use App\Models\Organization;

/**
 * Konteks organisasi aktif untuk satu request.
 *
 * Global scope tenant dan policy membaca konteks ini. Diisi oleh middleware
 * ResolveCurrentOrganization setelah user terautentikasi. Jika konteks kosong,
 * model tenant bersikap fail-closed: query tidak mengembalikan baris apa pun.
 */
class OrganizationContext
{
    private ?Organization $organization = null;

    private ?Membership $membership = null;

    public function set(Organization $organization, ?Membership $membership = null): void
    {
        $this->organization = $organization;
        $this->membership = $membership;
    }

    public function organization(): ?Organization
    {
        return $this->organization;
    }

    public function membership(): ?Membership
    {
        return $this->membership;
    }

    public function id(): ?int
    {
        return $this->organization?->getKey();
    }

    public function check(): bool
    {
        return $this->organization !== null;
    }

    public function role(): ?string
    {
        return $this->membership?->role;
    }

    public function hasRole(string ...$roles): bool
    {
        return $this->membership !== null && in_array($this->membership->role, $roles, true);
    }

    public function clear(): void
    {
        $this->organization = null;
        $this->membership = null;
    }
}
