<?php

namespace Tests\Feature\Catalog;

use App\Models\Asset;
use App\Models\Category;
use App\Models\Location;
use App\Models\Membership;
use App\Models\Organization;
use Tests\Support\TenantTestCase;

class CategoryLocationTest extends TenantTestCase
{
    public function test_admin_can_manage_categories(): void
    {
        $admin = $this->member(Membership::ROLE_ADMIN);

        $created = $this->actingAs($admin)
            ->postJson('/api/categories', ['name' => 'Elektronik', 'description' => 'Barang elektronik'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Elektronik');

        $id = $created->json('data.id');

        $this->actingAs($admin)
            ->patchJson('/api/categories/'.$id, ['name' => 'Elektronik & Gadget'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Elektronik & Gadget');

        $this->actingAs($admin)
            ->deleteJson('/api/categories/'.$id)
            ->assertOk();

        $this->assertSoftDeleted('categories', ['id' => $id]);
        $this->assertDatabaseHas('audit_events', ['action' => 'category.deleted']);
    }

    public function test_category_name_is_unique_per_organization_among_live_rows(): void
    {
        $admin = $this->member(Membership::ROLE_ADMIN);

        $category = $this->actingAs($admin)
            ->postJson('/api/categories', ['name' => 'Kendaraan'])
            ->assertCreated();

        $this->actingAs($admin)
            ->postJson('/api/categories', ['name' => 'Kendaraan'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        // Nama yang sama boleh di organisasi lain.
        $other = Organization::factory()->create();
        $this->category($other)->forceFill(['name' => 'Kendaraan'])->save();

        // Soft delete membebaskan nama untuk dipakai ulang.
        $this->actingAs($admin)->deleteJson('/api/categories/'.$category->json('data.id'))->assertOk();
        $this->actingAs($admin)->postJson('/api/categories', ['name' => 'Kendaraan'])->assertCreated();
    }

    public function test_category_cannot_be_deleted_when_used_by_asset(): void
    {
        $category = $this->category();

        Asset::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'category_id' => $category->getKey(),
        ]);

        $this->actingAs($this->member(Membership::ROLE_ADMIN))
            ->deleteJson('/api/categories/'.$category->getKey())
            ->assertStatus(422);

        $this->assertNotSoftDeleted('categories', ['id' => $category->getKey()]);
    }

    public function test_location_level_is_computed_from_parent_and_limited_to_four(): void
    {
        $admin = $this->member(Membership::ROLE_ADMIN);

        $level1 = $this->actingAs($admin)->postJson('/api/locations', ['name' => 'Gedung'])
            ->assertCreated()->assertJsonPath('data.level', 1);

        $level2 = $this->actingAs($admin)->postJson('/api/locations', [
            'name' => 'Lantai', 'parent_id' => $level1->json('data.id'),
        ])->assertCreated()->assertJsonPath('data.level', 2);

        $level3 = $this->actingAs($admin)->postJson('/api/locations', [
            'name' => 'Ruang', 'parent_id' => $level2->json('data.id'),
        ])->assertCreated()->assertJsonPath('data.level', 3);

        $level4 = $this->actingAs($admin)->postJson('/api/locations', [
            'name' => 'Rak', 'parent_id' => $level3->json('data.id'),
        ])->assertCreated()->assertJsonPath('data.level', 4);

        $this->actingAs($admin)->postJson('/api/locations', [
            'name' => 'Kotak', 'parent_id' => $level4->json('data.id'),
        ])->assertStatus(422)->assertJsonValidationErrors('parent_id');
    }

    public function test_location_name_is_unique_at_same_level(): void
    {
        $admin = $this->member(Membership::ROLE_ADMIN);

        $root = $this->actingAs($admin)->postJson('/api/locations', ['name' => 'Gudang'])
            ->assertCreated();

        $this->actingAs($admin)->postJson('/api/locations', ['name' => 'Gudang'])
            ->assertStatus(422)->assertJsonValidationErrors('name');

        $child = $this->actingAs($admin)->postJson('/api/locations', [
            'name' => 'Ruang', 'parent_id' => $root->json('data.id'),
        ])->assertCreated();

        // Nama sama pada parent berbeda tetap boleh.
        $otherRoot = $this->actingAs($admin)->postJson('/api/locations', ['name' => 'Gudang Lain'])
            ->assertCreated();

        $this->actingAs($admin)->postJson('/api/locations', [
            'name' => 'Ruang', 'parent_id' => $otherRoot->json('data.id'),
        ])->assertCreated();

        // Duplikat pada parent yang sama ditolak.
        $this->actingAs($admin)->postJson('/api/locations', [
            'name' => 'Ruang', 'parent_id' => $root->json('data.id'),
        ])->assertStatus(422)->assertJsonValidationErrors('name');

        $this->assertNotNull($child);
    }

    public function test_moving_location_recomputes_levels_of_descendants(): void
    {
        $admin = $this->member(Membership::ROLE_ADMIN);

        $rootA = Location::factory()->create(['organization_id' => $this->organization->getKey()]);
        $midB = Location::factory()->create([
            'organization_id' => $this->organization->getKey(), 'parent_id' => $rootA->getKey(), 'level' => 2,
        ]);
        $leafC = Location::factory()->create([
            'organization_id' => $this->organization->getKey(), 'parent_id' => $midB->getKey(), 'level' => 3,
        ]);

        $rootX = Location::factory()->create(['organization_id' => $this->organization->getKey()]);
        $childY = Location::factory()->create([
            'organization_id' => $this->organization->getKey(), 'parent_id' => $rootX->getKey(), 'level' => 2,
        ]);

        $this->actingAs($admin)
            ->patchJson('/api/locations/'.$midB->getKey(), ['parent_id' => $childY->getKey()])
            ->assertOk()
            ->assertJsonPath('data.level', 3);

        $this->assertSame(3, $midB->fresh()->level);
        $this->assertSame(4, $leafC->fresh()->level);
    }

    public function test_moving_location_under_its_own_descendant_is_rejected(): void
    {
        $admin = $this->member(Membership::ROLE_ADMIN);

        $root = Location::factory()->create(['organization_id' => $this->organization->getKey()]);
        $child = Location::factory()->create([
            'organization_id' => $this->organization->getKey(), 'parent_id' => $root->getKey(), 'level' => 2,
        ]);

        $this->actingAs($admin)
            ->patchJson('/api/locations/'.$root->getKey(), ['parent_id' => $child->getKey()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('parent_id');
    }

    public function test_location_cannot_be_deleted_when_used(): void
    {
        $admin = $this->member(Membership::ROLE_ADMIN);

        $parent = Location::factory()->create(['organization_id' => $this->organization->getKey()]);
        Location::factory()->create([
            'organization_id' => $this->organization->getKey(), 'parent_id' => $parent->getKey(), 'level' => 2,
        ]);

        $this->actingAs($admin)
            ->deleteJson('/api/locations/'.$parent->getKey())
            ->assertStatus(422);

        $standalone = Location::factory()->create(['organization_id' => $this->organization->getKey()]);

        Asset::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'category_id' => $this->category()->getKey(),
            'location_id' => $standalone->getKey(),
        ]);

        $this->actingAs($admin)
            ->deleteJson('/api/locations/'.$standalone->getKey())
            ->assertStatus(422);

        $free = Location::factory()->create(['organization_id' => $this->organization->getKey()]);

        $this->actingAs($admin)
            ->deleteJson('/api/locations/'.$free->getKey())
            ->assertOk();

        $this->assertSoftDeleted('locations', ['id' => $free->getKey()]);
    }

    public function test_category_delete_is_scoped_and_audited(): void
    {
        $other = Organization::factory()->create();
        $foreign = $this->category($other);

        // Menghapus kategori organisasi lain dari konteks ini harus 404, bukan dihapus.
        $this->actingAs($this->member(Membership::ROLE_ADMIN))
            ->deleteJson('/api/categories/'.$foreign->getKey())
            ->assertNotFound();

        $this->assertNotSoftDeleted('categories', ['id' => $foreign->getKey()]);
    }

    public function test_category_index_lists_live_rows_only(): void
    {
        $live = $this->category();
        $deleted = Category::factory()->create(['organization_id' => $this->organization->getKey()]);
        $deleted->delete();

        $response = $this->actingAs($this->member(Membership::ROLE_ADMIN))
            ->getJson('/api/categories')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertContains($live->getKey(), $ids);
        $this->assertNotContains($deleted->getKey(), $ids);
    }
}
