<?php

namespace App\Policies;

use App\Models\Attachment;
use App\Models\Membership;
use App\Models\User;
use App\Support\Tenancy\OrganizationContext;

/**
 * Otorisasi lampiran. Semua anggota organisasi boleh membuka lampiran milik
 * organisasinya; isolasi lintas organisasi lewat global scope (404). ADR-004.
 */
class AttachmentPolicy
{
    public function __construct(private OrganizationContext $context) {}

    public function view(User $user, Attachment $attachment): bool
    {
        return $this->context->check()
            && $this->context->id() === $attachment->organization_id
            && $this->context->hasRole(
                Membership::ROLE_BORROWER,
                Membership::ROLE_OPERATOR,
                Membership::ROLE_ADMIN,
            );
    }
}
