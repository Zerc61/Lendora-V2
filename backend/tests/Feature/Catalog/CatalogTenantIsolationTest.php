<?php

namespace Tests\Feature\Catalog;

use App\Models\Asset;
use App\Models\Attachment;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Tests\Support\TenantTestCase;

/**
 * CS-04: akses lintas organisasi harus 404, tanpa kebocoran data, dan tercatat.
 * Berlaku untuk semua resource katalog. Testing Strategy E.2.
 */
class CatalogTenantIsolationTest extends TenantTestCase
{
    private Organization $organizationB;

    private User $adminA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organizationB = Organization::factory()->create();
        $this->adminA = $this->member(Membership::ROLE_ADMIN);
    }

    public function test_foreign_asset_actions_return_404(): void
    {
        $asset = Asset::factory()->create([
            'organization_id' => $this->organizationB->getKey(),
            'category_id' => $this->category($this->organizationB)->getKey(),
        ]);

        $this->actingAs($this->adminA)
            ->getJson('/api/assets/'.$asset->getKey())
            ->assertNotFound();

        $this->actingAs($this->adminA)
            ->patchJson('/api/assets/'.$asset->getKey(), ['name' => 'Bobol'])
            ->assertNotFound();

        $this->actingAs($this->adminA)
            ->postJson('/api/assets/'.$asset->getKey().'/retire', ['reason' => 'Bobol'])
            ->assertNotFound();

        $this->actingAs($this->adminA)
            ->deleteJson('/api/assets/'.$asset->getKey())
            ->assertNotFound();

        $this->assertDatabaseHas('audit_events', ['action' => 'access_denied']);
        $this->assertDatabaseMissing('assets', ['id' => $asset->getKey(), 'name' => 'Bobol']);
    }

    public function test_foreign_asset_response_does_not_leak_id(): void
    {
        $asset = Asset::factory()->create([
            'organization_id' => $this->organizationB->getKey(),
            'category_id' => $this->category($this->organizationB)->getKey(),
        ]);

        $response = $this->actingAs($this->adminA)
            ->getJson('/api/assets/'.$asset->getKey())
            ->assertNotFound();

        $this->assertStringNotContainsString((string) $asset->getKey(), $response->getContent());
    }

    public function test_foreign_category_actions_return_404(): void
    {
        $category = $this->category($this->organizationB);

        $this->actingAs($this->adminA)
            ->getJson('/api/categories/'.$category->getKey())
            ->assertNotFound();

        $this->actingAs($this->adminA)
            ->patchJson('/api/categories/'.$category->getKey(), ['name' => 'Bobol'])
            ->assertNotFound();

        $this->actingAs($this->adminA)
            ->deleteJson('/api/categories/'.$category->getKey())
            ->assertNotFound();

        $this->assertDatabaseHas('categories', ['id' => $category->getKey(), 'name' => $category->name]);
    }

    public function test_foreign_location_actions_return_404(): void
    {
        $location = $this->location($this->organizationB);

        $this->actingAs($this->adminA)
            ->getJson('/api/locations/'.$location->getKey())
            ->assertNotFound();

        $this->actingAs($this->adminA)
            ->patchJson('/api/locations/'.$location->getKey(), ['name' => 'Bobol'])
            ->assertNotFound();

        $this->actingAs($this->adminA)
            ->deleteJson('/api/locations/'.$location->getKey())
            ->assertNotFound();
    }

    public function test_foreign_attachment_returns_404(): void
    {
        $asset = Asset::factory()->create([
            'organization_id' => $this->organizationB->getKey(),
            'category_id' => $this->category($this->organizationB)->getKey(),
        ]);

        $attachment = Attachment::factory()->create([
            'organization_id' => $this->organizationB->getKey(),
            'attachable_type' => Asset::class,
            'attachable_id' => $asset->getKey(),
        ]);

        $this->actingAs($this->adminA)
            ->get('/api/attachments/'.$attachment->getKey())
            ->assertNotFound();
    }

    public function test_index_only_lists_own_organization_assets(): void
    {
        $own = Asset::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'category_id' => $this->category()->getKey(),
        ]);
        $foreign = Asset::factory()->create([
            'organization_id' => $this->organizationB->getKey(),
            'category_id' => $this->category($this->organizationB)->getKey(),
        ]);

        $response = $this->actingAs($this->adminA)->getJson('/api/assets')->assertOk();
        $ids = collect($response->json('data'))->pluck('id');

        $this->assertContains($own->getKey(), $ids);
        $this->assertNotContains($foreign->getKey(), $ids);
    }

    public function test_creating_asset_with_foreign_category_is_rejected(): void
    {
        $foreignCategory = $this->category($this->organizationB);

        $this->actingAs($this->adminA)
            ->postJson('/api/assets', [
                'name' => 'Aset',
                'code' => 'AST-ISO',
                'category_id' => $foreignCategory->getKey(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('category_id');
    }

    public function test_category_and_location_index_are_tenant_scoped(): void
    {
        $ownCategory = $this->category();
        $foreignCategory = $this->category($this->organizationB);
        $ownLocation = $this->location();
        $foreignLocation = $this->location($this->organizationB);

        $categories = collect(
            $this->actingAs($this->adminA)->getJson('/api/categories')->assertOk()->json('data')
        )->pluck('id');
        $locations = collect(
            $this->actingAs($this->adminA)->getJson('/api/locations')->assertOk()->json('data')
        )->pluck('id');

        $this->assertContains($ownCategory->getKey(), $categories);
        $this->assertNotContains($foreignCategory->getKey(), $categories);
        $this->assertContains($ownLocation->getKey(), $locations);
        $this->assertNotContains($foreignLocation->getKey(), $locations);
    }
}
