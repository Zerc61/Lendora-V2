<?php

namespace Tests\Feature\Catalog;

use App\Models\Asset;
use App\Models\Membership;
use Tests\Support\TenantTestCase;

/**
 * Matriks otorisasi katalog. Setiap permission di Authorization Model D punya
 * minimal satu test yang menolaknya. Testing Strategy E.1.
 */
class AssetAuthorizationTest extends TenantTestCase
{
    public function test_only_admin_can_create_asset(): void
    {
        $category = $this->category();
        $payload = ['name' => 'Aset', 'code' => 'AST-AUTH', 'category_id' => $category->getKey()];

        $this->actingAs($this->member(Membership::ROLE_BORROWER))
            ->postJson('/api/assets', $payload)
            ->assertForbidden();

        $this->actingAs($this->member(Membership::ROLE_OPERATOR))
            ->postJson('/api/assets', $payload)
            ->assertForbidden();

        $this->actingAs($this->member(Membership::ROLE_ADMIN))
            ->postJson('/api/assets', $payload)
            ->assertCreated();
    }

    public function test_borrower_cannot_update_but_operator_and_admin_can(): void
    {
        $asset = Asset::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'category_id' => $this->category()->getKey(),
        ]);

        $this->actingAs($this->member(Membership::ROLE_BORROWER))
            ->patchJson('/api/assets/'.$asset->getKey(), ['name' => 'Ditolak'])
            ->assertForbidden();

        $this->actingAs($this->member(Membership::ROLE_OPERATOR))
            ->patchJson('/api/assets/'.$asset->getKey(), ['name' => 'Operator'])
            ->assertOk();

        $this->actingAs($this->member(Membership::ROLE_ADMIN))
            ->patchJson('/api/assets/'.$asset->getKey(), ['name' => 'Admin'])
            ->assertOk();
    }

    public function test_only_admin_can_retire_asset(): void
    {
        $asset = Asset::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'category_id' => $this->category()->getKey(),
        ]);

        $this->actingAs($this->member(Membership::ROLE_BORROWER))
            ->postJson('/api/assets/'.$asset->getKey().'/retire', ['reason' => 'Tes tolak'])
            ->assertForbidden();

        $this->actingAs($this->member(Membership::ROLE_OPERATOR))
            ->postJson('/api/assets/'.$asset->getKey().'/retire', ['reason' => 'Tes tolak'])
            ->assertForbidden();

        $this->actingAs($this->member(Membership::ROLE_ADMIN))
            ->postJson('/api/assets/'.$asset->getKey().'/retire', ['reason' => 'Tes terima'])
            ->assertOk();
    }

    public function test_only_admin_can_delete_asset(): void
    {
        $asset = Asset::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'category_id' => $this->category()->getKey(),
        ]);

        $this->actingAs($this->member(Membership::ROLE_BORROWER))
            ->deleteJson('/api/assets/'.$asset->getKey())
            ->assertForbidden();

        $this->actingAs($this->member(Membership::ROLE_OPERATOR))
            ->deleteJson('/api/assets/'.$asset->getKey())
            ->assertForbidden();

        $this->actingAs($this->member(Membership::ROLE_ADMIN))
            ->deleteJson('/api/assets/'.$asset->getKey())
            ->assertOk();
    }

    public function test_only_admin_can_manage_categories(): void
    {
        $category = $this->category();

        $this->actingAs($this->member(Membership::ROLE_BORROWER))
            ->postJson('/api/categories', ['name' => 'Baru'])
            ->assertForbidden();

        $this->actingAs($this->member(Membership::ROLE_OPERATOR))
            ->postJson('/api/categories', ['name' => 'Baru'])
            ->assertForbidden();

        $this->actingAs($this->member(Membership::ROLE_BORROWER))
            ->patchJson('/api/categories/'.$category->getKey(), ['name' => 'Ubah'])
            ->assertForbidden();

        $this->actingAs($this->member(Membership::ROLE_ADMIN))
            ->postJson('/api/categories', ['name' => 'Baru'])
            ->assertCreated();
    }

    public function test_only_admin_can_manage_locations(): void
    {
        $location = $this->location();

        $this->actingAs($this->member(Membership::ROLE_OPERATOR))
            ->postJson('/api/locations', ['name' => 'Gudang'])
            ->assertForbidden();

        $this->actingAs($this->member(Membership::ROLE_OPERATOR))
            ->deleteJson('/api/locations/'.$location->getKey())
            ->assertForbidden();

        $this->actingAs($this->member(Membership::ROLE_ADMIN))
            ->postJson('/api/locations', ['name' => 'Gudang'])
            ->assertCreated();
    }

    public function test_all_roles_can_view_catalog_and_master_data(): void
    {
        foreach ([Membership::ROLE_BORROWER, Membership::ROLE_OPERATOR, Membership::ROLE_ADMIN] as $role) {
            $user = $this->member($role);

            $this->actingAs($user)->getJson('/api/assets')->assertOk();
            $this->actingAs($user)->getJson('/api/categories')->assertOk();
            $this->actingAs($user)->getJson('/api/locations')->assertOk();
        }
    }

    public function test_borrower_condition_filter_does_not_leak_or_break(): void
    {
        Asset::factory()->condition(Asset::CONDITION_POOR)->create([
            'organization_id' => $this->organization->getKey(),
            'category_id' => $this->category()->getKey(),
        ]);

        // Filter kondisi diabaikan untuk borrower (fail-open ke daftar biasa, tanpa error).
        $this->actingAs($this->member(Membership::ROLE_BORROWER))
            ->getJson('/api/assets?condition='.Asset::CONDITION_POOR)
            ->assertOk();
    }

    public function test_borrower_cannot_open_retired_asset_detail(): void
    {
        $asset = Asset::factory()->retired()->create([
            'organization_id' => $this->organization->getKey(),
            'category_id' => $this->category()->getKey(),
        ]);

        $this->actingAs($this->member(Membership::ROLE_BORROWER))
            ->getJson('/api/assets/'.$asset->getKey())
            ->assertForbidden();

        $this->actingAs($this->member(Membership::ROLE_OPERATOR))
            ->getJson('/api/assets/'.$asset->getKey())
            ->assertOk();
    }
}
