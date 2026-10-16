<?php

namespace Tests\Feature\Catalog;

use App\Models\Membership;
use App\Models\Organization;
use Tests\Support\TenantTestCase;

/**
 * Profil provider diubah admin organisasi sendiri. Verifikasi dan status
 * organisasi bukan bagian dari endpoint ini.
 */
class OrganizationProfileTest extends TenantTestCase
{
    public function test_admin_can_update_provider_profile_and_it_is_audited(): void
    {
        $admin = $this->member(Membership::ROLE_ADMIN);

        $response = $this->actingAs($admin)->patchJson(
            '/api/organizations/'.$this->organization->getKey(),
            [
                'description' => 'Studio foto dan sewa kamera.',
                'contact_phone' => '08123456789',
                'city' => 'Bandung',
            ],
        );

        $response->assertOk()
            ->assertJsonPath('data.city', 'Bandung')
            ->assertJsonPath('data.verification_status', Organization::VERIFICATION_PENDING);

        $this->assertDatabaseHas('organizations', [
            'id' => $this->organization->getKey(),
            'contact_phone' => '08123456789',
            'city' => 'Bandung',
        ]);
        $this->assertDatabaseHas('audit_events', ['action' => 'organization.profile_updated']);
    }

    public function test_operator_cannot_update_provider_profile(): void
    {
        $operator = $this->member(Membership::ROLE_OPERATOR);

        $this->actingAs($operator)->patchJson(
            '/api/organizations/'.$this->organization->getKey(),
            ['city' => 'Bandung'],
        )->assertForbidden();

        $this->assertDatabaseMissing('organizations', ['city' => 'Bandung']);
    }

    public function test_verification_and_status_are_not_changeable_through_profile_endpoint(): void
    {
        $admin = $this->member(Membership::ROLE_ADMIN);

        $this->actingAs($admin)->patchJson(
            '/api/organizations/'.$this->organization->getKey(),
            [
                'verification_status' => Organization::VERIFICATION_VERIFIED,
                'status' => Organization::STATUS_INACTIVE,
            ],
        )->assertOk();

        $this->assertDatabaseHas('organizations', [
            'id' => $this->organization->getKey(),
            'verification_status' => Organization::VERIFICATION_PENDING,
            'status' => Organization::STATUS_ACTIVE,
        ]);
    }

    public function test_foreign_organization_update_returns_404(): void
    {
        $admin = $this->member(Membership::ROLE_ADMIN);
        $foreign = Organization::factory()->create();

        $this->actingAs($admin)->patchJson(
            '/api/organizations/'.$foreign->getKey(),
            ['city' => 'Bandung'],
        )->assertNotFound();

        $this->assertDatabaseMissing('organizations', ['city' => 'Bandung']);
    }

    public function test_invalid_contact_email_is_rejected(): void
    {
        $admin = $this->member(Membership::ROLE_ADMIN);

        $this->actingAs($admin)->patchJson(
            '/api/organizations/'.$this->organization->getKey(),
            ['contact_email' => 'bukan-email'],
        )->assertUnprocessable()->assertJsonValidationErrors(['contact_email']);
    }
}
