<?php

namespace App\Policies;

use App\Models\MaintenanceTicket;
use App\Models\Membership;
use App\Models\User;
use App\Support\Tenancy\OrganizationContext;

/**
 * Otorisasi MaintenanceTicket. Perbaikan adalah pekerjaan operator/technician;
 * borrower tidak melihatnya. Isolasi lintas organisasi lewat global scope (404).
 */
class MaintenanceTicketPolicy
{
    public function __construct(private OrganizationContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->management();
    }

    public function view(User $user, MaintenanceTicket $ticket): bool
    {
        return $this->sameOrganization($ticket) && $this->management();
    }

    public function create(User $user): bool
    {
        return $this->management();
    }

    public function assign(User $user, MaintenanceTicket $ticket): bool
    {
        return $this->sameOrganization($ticket) && $this->management();
    }

    public function work(User $user, MaintenanceTicket $ticket): bool
    {
        return $this->sameOrganization($ticket) && $this->management();
    }

    /**
     * Verifikasi hanya operator/admin, dan bukan teknisi yang mengerjakan
     * ticket tersebut (pemisahan tugas, Issue & Maintenance F).
     */
    public function verify(User $user, MaintenanceTicket $ticket): bool
    {
        return $this->sameOrganization($ticket)
            && $this->management()
            && $ticket->assigned_to !== $user->getKey();
    }

    public function cancel(User $user, MaintenanceTicket $ticket): bool
    {
        return $this->sameOrganization($ticket) && $this->management();
    }

    private function sameOrganization(MaintenanceTicket $ticket): bool
    {
        return $this->context->check()
            && $this->context->id() === $ticket->organization_id;
    }

    private function management(): bool
    {
        return $this->context->hasRole(Membership::ROLE_OPERATOR, Membership::ROLE_ADMIN);
    }
}
