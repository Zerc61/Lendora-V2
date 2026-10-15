<?php

namespace App\Policies;

use App\Models\Issue;
use App\Models\Membership;
use App\Models\User;
use App\Support\Tenancy\OrganizationContext;

/**
 * Otorisasi Issue. Isolasi lintas organisasi lewat global scope (404); policy ini
 * menangani 403 untuk role yang tidak cukup. ADR-004.
 */
class IssuePolicy
{
    public function __construct(private OrganizationContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->check();
    }

    /**
     * Operator/admin melihat semua laporan; borrower hanya laporan yang ia buat.
     */
    public function view(User $user, Issue $issue): bool
    {
        if (! $this->sameOrganization($issue)) {
            return false;
        }

        if ($this->management()) {
            return true;
        }

        return $issue->reported_by === $user->getKey();
    }

    /**
     * OD-33 default: semua anggota boleh melaporkan masalah pada aset apa pun.
     */
    public function create(User $user): bool
    {
        return $this->context->check();
    }

    public function review(User $user, Issue $issue): bool
    {
        return $this->sameOrganization($issue) && $this->management();
    }

    public function resolve(User $user, Issue $issue): bool
    {
        return $this->sameOrganization($issue) && $this->management();
    }

    public function dismiss(User $user, Issue $issue): bool
    {
        return $this->sameOrganization($issue) && $this->management();
    }

    private function sameOrganization(Issue $issue): bool
    {
        return $this->context->check()
            && $this->context->id() === $issue->organization_id;
    }

    private function management(): bool
    {
        return $this->context->hasRole(Membership::ROLE_OPERATOR, Membership::ROLE_ADMIN);
    }
}
