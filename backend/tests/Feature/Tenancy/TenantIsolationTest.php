<?php

namespace Tests\Feature\Tenancy;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CS-04: akses lintas organisasi harus 404, tanpa kebocoran data, dan tercatat.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organizationA;

    private Organization $organizationB;

    private User $adminA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organizationA = Organization::factory()->create();
        $this->organizationB = Organization::factory()->create();

        $this->adminA = User::factory()->create();
        Membership::factory()->admin()->create([
            'organization_id' => $this->organizationA->getKey(),
            'user_id' => $this->adminA->getKey(),
        ]);
    }

    public function test_membership_of_another_organization_returns_404(): void
    {
        $foreignMembership = Membership::factory()->create([
            'organization_id' => $this->organizationB->getKey(),
        ]);

        $response = $this->actingAs($this->adminA)
            ->getJson('/api/memberships/'.$foreignMembership->getKey());

        $response->assertNotFound();

        // Tidak ada kebocoran: respons tidak mengonfirmasi keberadaan resource.
        $this->assertStringNotContainsString(
            (string) $foreignMembership->getKey(),
            $response->getContent(),
        );

        $this->assertDatabaseHas('audit_events', ['action' => 'access_denied']);
    }

    public function test_organization_of_another_tenant_returns_404(): void
    {
        $this->actingAs($this->adminA)
            ->getJson('/api/organizations/'.$this->organizationB->getKey())
            ->assertNotFound();

        $this->assertDatabaseHas('audit_events', ['action' => 'access_denied']);
    }

    public function test_index_only_lists_memberships_of_active_organization(): void
    {
        Membership::factory()->create(['organization_id' => $this->organizationA->getKey()]);
        $foreign = Membership::factory()->create(['organization_id' => $this->organizationB->getKey()]);

        $response = $this->actingAs($this->adminA)
            ->getJson('/api/memberships')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id');

        $this->assertNotContains($foreign->getKey(), $ids);
        $this->assertTrue($ids->every(fn (int $id) => Membership::withoutOrganizationScope()
            ->whereKey($id)->value('organization_id') === $this->organizationA->getKey()));
    }

    public function test_organization_index_only_lists_own_organizations(): void
    {
        $response = $this->actingAs($this->adminA)
            ->getJson('/api/organizations')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id');

        $this->assertContains($this->organizationA->getKey(), $ids);
        $this->assertNotContains($this->organizationB->getKey(), $ids);
    }

    public function test_switching_to_foreign_organization_returns_404(): void
    {
        $this->actingAs($this->adminA)
            ->postJson('/api/organization/switch', [
                'organization_id' => $this->organizationB->getKey(),
            ])
            ->assertNotFound();
    }

    public function test_switching_to_own_organization_succeeds(): void
    {
        $this->actingAs($this->adminA)
            ->postJson('/api/organization/switch', [
                'organization_id' => $this->organizationA->getKey(),
            ])
            ->assertOk()
            ->assertJsonPath('active_organization_id', $this->organizationA->getKey());
    }
}
