<?php

namespace App\Policies;

use App\Models\Asset;
use App\Models\Membership;
use App\Models\User;
use App\Support\Tenancy\OrganizationContext;

/**
 * Otorisasi resource Asset. Isolasi lintas organisasi sudah ditangani global
 * scope (404); policy ini menangani 403 untuk role yang tidak cukup. ADR-004.
 */
class AssetPolicy
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

    public function view(User $user, Asset $asset): bool
    {
        if (! $this->sameOrganization($asset)) {
            return false;
        }

        // Aset retired tidak ada di katalog peminjam. Asset Management D.
        if ($asset->isRetired()) {
            return $this->context->hasRole(Membership::ROLE_OPERATOR, Membership::ROLE_ADMIN);
        }

        return true;
    }

    public function create(User $user): bool
    {
        return $this->context->hasRole(Membership::ROLE_ADMIN);
    }

    public function update(User $user, Asset $asset): bool
    {
        return $this->sameOrganization($asset)
            && $this->context->hasRole(Membership::ROLE_OPERATOR, Membership::ROLE_ADMIN);
    }

    public function retire(User $user, Asset $asset): bool
    {
        return $this->sameOrganization($asset)
            && $this->context->hasRole(Membership::ROLE_ADMIN);
    }

    public function delete(User $user, Asset $asset): bool
    {
        return $this->sameOrganization($asset)
            && $this->context->hasRole(Membership::ROLE_ADMIN);
    }

    /**
     * Inspeksi mengubah lifecycle aset setelah pengembalian (ADR-007). Hanya
     * operator dan admin.
     */
    public function inspect(User $user, Asset $asset): bool
    {
        return $this->sameOrganization($asset)
            && $this->context->hasRole(Membership::ROLE_OPERATOR, Membership::ROLE_ADMIN);
    }

    /**
     * Riwayat kondisi adalah data operator; borrower tidak melihatnya (D-33).
     */
    public function viewConditionRecords(User $user, Asset $asset): bool
    {
        return $this->sameOrganization($asset)
            && $this->context->hasRole(Membership::ROLE_OPERATOR, Membership::ROLE_ADMIN);
    }

    private function sameOrganization(Asset $asset): bool
    {
        return $this->context->check()
            && $this->context->id() === $asset->organization_id;
    }
}
