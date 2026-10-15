<?php

namespace App\Policies;

use App\Models\Loan;
use App\Models\Membership;
use App\Models\OrganizationPolicy;
use App\Models\User;
use App\Support\Tenancy\OrganizationContext;

/**
 * Otorisasi resource Loan. Isolasi lintas organisasi lewat global scope (404);
 * policy ini menangani object level dan 403 untuk role yang tidak cukup (ADR-004).
 */
class LoanPolicy
{
    public function __construct(private OrganizationContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->check();
    }

    public function view(User $user, Loan $loan): bool
    {
        if (! $this->sameOrganization($loan)) {
            return false;
        }

        if ($this->management()) {
            return true;
        }

        return $loan->borrower_id === $user->getKey();
    }

    public function create(User $user): bool
    {
        // loan.request berlaku untuk semua role.
        return $this->context->check();
    }

    public function approve(User $user, Loan $loan): bool
    {
        return $this->sameOrganization($loan) && $this->management();
    }

    public function reject(User $user, Loan $loan): bool
    {
        return $this->sameOrganization($loan) && $this->management();
    }

    /**
     * Serah terima aset dilakukan operator atau admin. Pengembalian oleh borrower
     * ditunda sampai alur serah terima di meja ditetapkan.
     */
    public function checkout(User $user, Loan $loan): bool
    {
        return $this->sameOrganization($loan) && $this->management();
    }

    public function checkin(User $user, Loan $loan): bool
    {
        return $this->sameOrganization($loan) && $this->management();
    }

    /**
     * Borrower (atau operator atas loan sendiri) boleh membatalkan `requested`,
     * dan `approved` bila kebijakan mengizinkan. Admin boleh membatalkan keduanya.
     */
    public function cancel(User $user, Loan $loan): bool
    {
        if (! $this->sameOrganization($loan)) {
            return false;
        }

        if (! in_array($loan->status, [Loan::STATUS_REQUESTED, Loan::STATUS_APPROVED], true)) {
            return false;
        }

        if ($this->context->hasRole(Membership::ROLE_ADMIN)) {
            return true;
        }

        if ($loan->borrower_id !== $user->getKey()) {
            return false;
        }

        if ($loan->status === Loan::STATUS_REQUESTED) {
            return true;
        }

        return OrganizationPolicy::resolve($loan->organization_id)->allowsBorrowerCancel();
    }

    private function management(): bool
    {
        return $this->context->hasRole(Membership::ROLE_OPERATOR, Membership::ROLE_ADMIN);
    }

    private function sameOrganization(Loan $loan): bool
    {
        return $this->context->check()
            && $this->context->id() === $loan->organization_id;
    }
}
