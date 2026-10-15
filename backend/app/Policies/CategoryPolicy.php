<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\Membership;
use App\Models\User;
use App\Support\Tenancy\OrganizationContext;

/**
 * Otorisasi resource Category. Semua anggota organisasi dapat melihat (dipakai
 * untuk filter), hanya admin yang mengubah. ADR-004.
 */
class CategoryPolicy
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

    public function view(User $user, Category $category): bool
    {
        return $this->sameOrganization($category);
    }

    public function create(User $user): bool
    {
        return $this->context->hasRole(Membership::ROLE_ADMIN);
    }

    public function update(User $user, Category $category): bool
    {
        return $this->sameOrganization($category)
            && $this->context->hasRole(Membership::ROLE_ADMIN);
    }

    public function delete(User $user, Category $category): bool
    {
        return $this->sameOrganization($category)
            && $this->context->hasRole(Membership::ROLE_ADMIN);
    }

    private function sameOrganization(Category $category): bool
    {
        return $this->context->check()
            && $this->context->id() === $category->organization_id;
    }
}
