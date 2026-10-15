<?php

namespace Tests\Feature\Lending;

use App\Models\Membership;
use Tests\Support\TenantTestCase;

/**
 * Endpoint kebijakan organisasi: admin membaca/memperbarui, role lain ditolak.
 * allow_renewal sengaja tidak diekspor (fitur perpanjangan belum ada end-to-end).
 */
class OrganizationPolicyTest extends TenantTestCase
{
    public function test_admin_can_update_policy_and_it_is_audited(): void
    {
        $admin = $this->member(Membership::ROLE_ADMIN);

        $response = $this->actingAs($admin)
            ->patchJson("/api/organizations/{$this->organization->getKey()}/policy", [
                'max_loan_duration_days' => 21,
            ]);

        $response->assertSuccessful()
            ->assertJsonPath('data.max_loan_duration_days', 21)
            ->assertJsonMissingPath('data.allow_renewal');

        $this->assertDatabaseHas('organization_policies', [
            'organization_id' => $this->organization->getKey(),
            'max_loan_duration_days' => 21,
        ]);
        $this->assertDatabaseHas('audit_events', ['action' => 'organization_policy.updated']);
    }

    public function test_policy_show_never_exposes_allow_renewal(): void
    {
        $admin = $this->member(Membership::ROLE_ADMIN);

        $this->actingAs($admin)
            ->getJson("/api/organizations/{$this->organization->getKey()}/policy")
            ->assertOk()
            ->assertJsonMissingPath('data.allow_renewal');
    }

    public function test_operator_cannot_update_policy(): void
    {
        $operator = $this->member(Membership::ROLE_OPERATOR);

        $this->actingAs($operator)
            ->patchJson("/api/organizations/{$this->organization->getKey()}/policy", [
                'max_active_loans' => 5,
            ])
            ->assertForbidden();
    }
}
