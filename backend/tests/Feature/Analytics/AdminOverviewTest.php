<?php

namespace Tests\Feature\Analytics;

use App\Models\Asset;
use App\Models\Issue;
use App\Models\Loan;
use App\Models\MaintenanceTicket;
use App\Models\Membership;
use App\Models\Organization;
use Tests\Support\TenantTestCase;

class AdminOverviewTest extends TenantTestCase
{
    public function test_admin_sees_overview_counts(): void
    {
        $admin = $this->member(Membership::ROLE_ADMIN);
        $borrower = $this->member(Membership::ROLE_BORROWER);
        $this->member(Membership::ROLE_OPERATOR);

        $this->asset(attributes: ['lifecycle_status' => Asset::LIFECYCLE_ACTIVE, 'condition' => Asset::CONDITION_GOOD]);
        $this->asset(attributes: ['lifecycle_status' => Asset::LIFECYCLE_ACTIVE, 'condition' => Asset::CONDITION_EXCELLENT]);
        $problem = $this->asset(attributes: [
            'lifecycle_status' => Asset::LIFECYCLE_UNDER_MAINTENANCE,
            'condition' => Asset::CONDITION_POOR,
        ]);

        Loan::factory()->approved()->create([
            'organization_id' => $this->organization->getKey(),
            'borrower_id' => $borrower->getKey(),
        ]);

        Loan::factory()->checkedOut()->create([
            'organization_id' => $this->organization->getKey(),
            'borrower_id' => $borrower->getKey(),
        ]);

        $overdue = Loan::factory()->checkedOut()->create([
            'organization_id' => $this->organization->getKey(),
            'borrower_id' => $borrower->getKey(),
        ]);
        $overdue->starts_at = now()->subDays(10);
        $overdue->ends_at = now()->subDays(3);
        $overdue->due_at = now()->subDays(1);
        $overdue->save();

        Issue::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'asset_id' => $problem->getKey(),
            'status' => Issue::STATUS_OPEN,
            'reported_by' => $borrower->getKey(),
        ]);

        MaintenanceTicket::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'asset_id' => $problem->getKey(),
            'status' => MaintenanceTicket::STATUS_IN_PROGRESS,
        ]);

        $this->actingAs($admin)
            ->getJson('/api/admin/overview')
            ->assertOk()
            ->assertJsonPath('assets.total', 3)
            ->assertJsonPath('assets.by_lifecycle.active', 2)
            ->assertJsonPath('assets.by_lifecycle.under_maintenance', 1)
            ->assertJsonPath('assets.by_condition.good', 1)
            ->assertJsonPath('assets.by_condition.excellent', 1)
            ->assertJsonPath('assets.by_condition.poor', 1)
            ->assertJsonPath('assets.problematic', 1)
            ->assertJsonPath('loans.approved', 1)
            ->assertJsonPath('loans.checked_out', 2)
            ->assertJsonPath('loans.overdue', 1)
            ->assertJsonPath('maintenance.open_issues', 1)
            ->assertJsonPath('maintenance.open_tickets', 1)
            ->assertJsonPath('members.admin', 1)
            ->assertJsonPath('members.borrower', 1)
            ->assertJsonPath('members.operator', 1)
            ->assertJsonPath('members.active_total', 3)
            ->assertJsonCount(1, 'problematic_assets')
            ->assertJsonPath('problematic_assets.0.asset_id', $problem->getKey())
            ->assertJsonPath('problematic_assets.0.open_tickets', 1);
    }

    public function test_operator_cannot_see_admin_overview(): void
    {
        $operator = $this->member(Membership::ROLE_OPERATOR);

        $this->actingAs($operator)
            ->getJson('/api/admin/overview')
            ->assertForbidden();
    }

    public function test_borrower_cannot_see_admin_overview(): void
    {
        $borrower = $this->member(Membership::ROLE_BORROWER);

        $this->actingAs($borrower)
            ->getJson('/api/admin/overview')
            ->assertForbidden();
    }

    public function test_overview_ignores_other_organizations(): void
    {
        $admin = $this->member(Membership::ROLE_ADMIN);
        $this->asset();

        $other = Organization::factory()->create();
        $this->asset($other, [
            'lifecycle_status' => Asset::LIFECYCLE_UNDER_MAINTENANCE,
            'condition' => Asset::CONDITION_POOR,
        ]);

        $this->actingAs($admin)
            ->getJson('/api/admin/overview')
            ->assertOk()
            ->assertJsonPath('assets.total', 1)
            ->assertJsonPath('assets.problematic', 0)
            ->assertJsonCount(0, 'problematic_assets');
    }
}
