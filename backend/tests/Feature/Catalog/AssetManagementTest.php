<?php

namespace Tests\Feature\Catalog;

use App\Models\Asset;
use App\Models\Attachment;
use App\Models\Membership;
use App\Models\Organization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TenantTestCase;

class AssetManagementTest extends TenantTestCase
{
    public function test_admin_can_create_asset(): void
    {
        $category = $this->category();

        $response = $this->actingAs($this->member(Membership::ROLE_ADMIN))
            ->postJson('/api/assets', [
                'name' => 'Kamera Canon EOS',
                'code' => 'AST-001',
                'category_id' => $category->getKey(),
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.code', 'AST-001')
            ->assertJsonPath('data.lifecycle_status', Asset::LIFECYCLE_ACTIVE)
            ->assertJsonPath('data.condition', Asset::CONDITION_GOOD)
            ->assertJsonPath('data.is_borrowable', true);

        $this->assertDatabaseHas('assets', [
            'code' => 'AST-001',
            'organization_id' => $this->organization->getKey(),
            'lifecycle_status' => Asset::LIFECYCLE_ACTIVE,
        ]);
        $this->assertDatabaseHas('audit_events', ['action' => 'asset.created']);
    }

    public function test_borrower_does_not_see_management_fields(): void
    {
        $asset = Asset::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'category_id' => $this->category()->getKey(),
        ]);

        $this->actingAs($this->member(Membership::ROLE_BORROWER))
            ->getJson('/api/assets/'.$asset->getKey())
            ->assertOk()
            ->assertJsonPath('data.is_borrowable', true)
            ->assertJsonMissingPath('data.lifecycle_status')
            ->assertJsonMissingPath('data.condition')
            ->assertJsonMissingPath('data.purchase_price');
    }

    public function test_operator_sees_management_fields(): void
    {
        $asset = Asset::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'category_id' => $this->category()->getKey(),
        ]);

        $this->actingAs($this->member(Membership::ROLE_OPERATOR))
            ->getJson('/api/assets/'.$asset->getKey())
            ->assertOk()
            ->assertJsonPath('data.lifecycle_status', Asset::LIFECYCLE_ACTIVE)
            ->assertJsonPath('data.condition', Asset::CONDITION_GOOD);
    }

    public function test_asset_code_is_unique_per_organization(): void
    {
        $category = $this->category();

        Asset::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'category_id' => $category->getKey(),
            'code' => 'AST-100',
        ]);

        $this->actingAs($this->member(Membership::ROLE_ADMIN))
            ->postJson('/api/assets', [
                'name' => 'Duplikat',
                'code' => 'AST-100',
                'category_id' => $category->getKey(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    public function test_same_code_is_allowed_in_another_organization(): void
    {
        $otherOrganization = Organization::factory()->create();

        Asset::factory()->create([
            'organization_id' => $otherOrganization->getKey(),
            'category_id' => $this->category($otherOrganization)->getKey(),
            'code' => 'AST-100',
        ]);

        $this->actingAs($this->member(Membership::ROLE_ADMIN))
            ->postJson('/api/assets', [
                'name' => 'Aset baru',
                'code' => 'AST-100',
                'category_id' => $this->category()->getKey(),
            ])
            ->assertCreated();
    }

    public function test_asset_code_is_immutable(): void
    {
        $asset = Asset::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'category_id' => $this->category()->getKey(),
            'code' => 'AST-200',
        ]);

        $this->actingAs($this->member(Membership::ROLE_ADMIN))
            ->patchJson('/api/assets/'.$asset->getKey(), ['code' => 'AST-999'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');

        $this->assertSame('AST-200', $asset->fresh()->code);
    }

    public function test_asset_serial_number_is_immutable(): void
    {
        $asset = Asset::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'category_id' => $this->category()->getKey(),
            'serial_number' => 'SN-1',
        ]);

        $this->actingAs($this->member(Membership::ROLE_ADMIN))
            ->patchJson('/api/assets/'.$asset->getKey(), ['serial_number' => 'SN-2'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('serial_number');

        $this->assertSame('SN-1', $asset->fresh()->serial_number);
    }

    public function test_lifecycle_and_condition_cannot_be_edited_through_asset_form(): void
    {
        $asset = Asset::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'category_id' => $this->category()->getKey(),
        ]);

        $admin = $this->member(Membership::ROLE_ADMIN);

        $this->actingAs($admin)
            ->patchJson('/api/assets/'.$asset->getKey(), ['condition' => Asset::CONDITION_POOR])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->patchJson('/api/assets/'.$asset->getKey(), ['lifecycle_status' => Asset::LIFECYCLE_RETIRED])
            ->assertStatus(422);

        $this->assertSame(Asset::CONDITION_GOOD, $asset->fresh()->condition);
        $this->assertSame(Asset::LIFECYCLE_ACTIVE, $asset->fresh()->lifecycle_status);
    }

    public function test_operator_can_update_editable_fields(): void
    {
        $asset = Asset::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'category_id' => $this->category()->getKey(),
        ]);

        $this->actingAs($this->member(Membership::ROLE_OPERATOR))
            ->patchJson('/api/assets/'.$asset->getKey(), [
                'name' => 'Nama Diperbarui',
                // Kode boleh dikirim asal tidak berubah; server mengabaikannya.
                'code' => $asset->code,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Nama Diperbarui');

        $this->assertDatabaseHas('audit_events', ['action' => 'asset.updated']);
    }

    public function test_serial_number_is_unique_per_organization_when_set(): void
    {
        $category = $this->category();

        Asset::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'category_id' => $category->getKey(),
            'serial_number' => 'SN-UNIK',
        ]);

        $this->actingAs($this->member(Membership::ROLE_ADMIN))
            ->postJson('/api/assets', [
                'name' => 'Aset lain',
                'code' => 'AST-300',
                'category_id' => $category->getKey(),
                'serial_number' => 'SN-UNIK',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('serial_number');
    }

    public function test_catalog_excludes_retired_assets(): void
    {
        $category = $this->category();

        $active = Asset::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'category_id' => $category->getKey(),
        ]);
        $retired = Asset::factory()->retired()->create([
            'organization_id' => $this->organization->getKey(),
            'category_id' => $category->getKey(),
        ]);

        $response = $this->actingAs($this->member(Membership::ROLE_BORROWER))
            ->getJson('/api/assets')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id');

        $this->assertContains($active->getKey(), $ids);
        $this->assertNotContains($retired->getKey(), $ids);
    }

    public function test_management_can_filter_retired_assets(): void
    {
        $category = $this->category();

        Asset::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'category_id' => $category->getKey(),
        ]);
        $retired = Asset::factory()->retired()->create([
            'organization_id' => $this->organization->getKey(),
            'category_id' => $category->getKey(),
        ]);

        $response = $this->actingAs($this->member(Membership::ROLE_ADMIN))
            ->getJson('/api/assets?status='.Asset::LIFECYCLE_RETIRED)
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id');

        $this->assertContains($retired->getKey(), $ids);
        $this->assertCount(1, $ids);
    }

    public function test_admin_can_retire_asset_with_reason(): void
    {
        $asset = Asset::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'category_id' => $this->category()->getKey(),
        ]);

        $this->actingAs($this->member(Membership::ROLE_ADMIN))
            ->postJson('/api/assets/'.$asset->getKey().'/retire', ['reason' => 'Habis umur pakai'])
            ->assertOk()
            ->assertJsonPath('data.lifecycle_status', Asset::LIFECYCLE_RETIRED);

        $this->assertSame(Asset::LIFECYCLE_RETIRED, $asset->fresh()->lifecycle_status);

        $event = DB::table('audit_events')->where('action', 'asset.retired')->latest('id')->first();

        $this->assertNotNull($event);
        $this->assertSame('Habis umur pakai', json_decode($event->context, true)['reason']);
    }

    public function test_retire_requires_reason(): void
    {
        $asset = Asset::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'category_id' => $this->category()->getKey(),
        ]);

        $this->actingAs($this->member(Membership::ROLE_ADMIN))
            ->postJson('/api/assets/'.$asset->getKey().'/retire', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertSame(Asset::LIFECYCLE_ACTIVE, $asset->fresh()->lifecycle_status);
    }

    public function test_retired_asset_cannot_be_retired_again(): void
    {
        $asset = Asset::factory()->retired()->create([
            'organization_id' => $this->organization->getKey(),
            'category_id' => $this->category()->getKey(),
        ]);

        $this->actingAs($this->member(Membership::ROLE_ADMIN))
            ->postJson('/api/assets/'.$asset->getKey().'/retire', ['reason' => 'Sudah tidak dipakai'])
            ->assertStatus(422);
    }

    public function test_soft_deleted_asset_disappears_from_catalog(): void
    {
        $asset = Asset::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'category_id' => $this->category()->getKey(),
        ]);

        $this->actingAs($this->member(Membership::ROLE_ADMIN))
            ->deleteJson('/api/assets/'.$asset->getKey())
            ->assertOk();

        $this->assertSoftDeleted('assets', ['id' => $asset->getKey()]);
        $this->assertDatabaseHas('audit_events', ['action' => 'asset.deleted']);

        $response = $this->actingAs($this->member(Membership::ROLE_ADMIN))
            ->getJson('/api/assets')
            ->assertOk();

        $this->assertNotContains($asset->getKey(), collect($response->json('data'))->pluck('id'));
    }

    public function test_full_text_search_matches_name_and_description(): void
    {
        $category = $this->category();

        $asset = Asset::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'category_id' => $category->getKey(),
            'name' => 'Proyektor Epson',
            'description' => 'Untuk rapat di ruang besar',
        ]);

        $borrower = $this->member(Membership::ROLE_BORROWER);

        $byName = $this->actingAs($borrower)->getJson('/api/assets?q=proyektor')->assertOk();
        $this->assertContains($asset->getKey(), collect($byName->json('data'))->pluck('id'));

        $byDescription = $this->actingAs($borrower)->getJson('/api/assets?q=rapat')->assertOk();
        $this->assertContains($asset->getKey(), collect($byDescription->json('data'))->pluck('id'));

        $empty = $this->actingAs($borrower)->getJson('/api/assets?q=tidakadakata')->assertOk();
        $this->assertCount(0, $empty->json('data'));
    }

    public function test_index_paginates_24_per_page(): void
    {
        $category = $this->category();

        Asset::factory()->count(25)->create([
            'organization_id' => $this->organization->getKey(),
            'category_id' => $category->getKey(),
        ]);

        $borrower = $this->member(Membership::ROLE_BORROWER);

        $this->actingAs($borrower)
            ->getJson('/api/assets')
            ->assertOk()
            ->assertJsonCount(24, 'data')
            ->assertJsonPath('meta.total', 25);

        $this->actingAs($borrower)
            ->getJson('/api/assets?page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_filters_by_category_location_status_and_condition(): void
    {
        $categoryA = $this->category();
        $categoryB = $this->category();
        $location = $this->location();

        $match = Asset::factory()->underMaintenance()->condition(Asset::CONDITION_POOR)->create([
            'organization_id' => $this->organization->getKey(),
            'category_id' => $categoryA->getKey(),
            'location_id' => $location->getKey(),
        ]);
        Asset::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'category_id' => $categoryB->getKey(),
        ]);

        $admin = $this->member(Membership::ROLE_ADMIN);

        $assertOnly = function (string $query) use ($admin, $match): void {
            $response = $this->actingAs($admin)->getJson('/api/assets?'.$query)->assertOk();
            $ids = collect($response->json('data'))->pluck('id');

            $this->assertContains($match->getKey(), $ids);
            $this->assertCount(1, $ids);
        };

        $assertOnly('category_id='.$categoryA->getKey());
        $assertOnly('location_id='.$location->getKey());
        $assertOnly('status='.Asset::LIFECYCLE_UNDER_MAINTENANCE);
        $assertOnly('condition='.Asset::CONDITION_POOR);
    }

    public function test_create_validates_required_and_cross_field_rules(): void
    {
        $otherOrganization = Organization::factory()->create();
        $foreignCategory = $this->category($otherOrganization);
        $admin = $this->member(Membership::ROLE_ADMIN);

        $this->actingAs($admin)
            ->postJson('/api/assets', ['code' => 'AST-001'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'category_id']);

        $this->actingAs($admin)
            ->postJson('/api/assets', [
                'name' => 'Aset',
                'code' => 'AST-400',
                'category_id' => $foreignCategory->getKey(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('category_id');

        $this->actingAs($admin)
            ->postJson('/api/assets', [
                'name' => 'Aset',
                'code' => 'AST-401',
                'category_id' => $this->category()->getKey(),
                'purchase_date' => now()->addDay()->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('purchase_date');

        $this->actingAs($admin)
            ->postJson('/api/assets', [
                'name' => 'Aset',
                'code' => 'AST-402',
                'category_id' => $this->category()->getKey(),
                'purchase_date' => '2026-01-10',
                'warranty_until' => '2026-01-01',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('warranty_until');

        $this->actingAs($admin)
            ->postJson('/api/assets', [
                'name' => 'Aset',
                'code' => 'AST-403',
                'category_id' => $this->category()->getKey(),
                'purchase_price' => -1,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('purchase_price');
    }

    public function test_admin_can_upload_asset_photos(): void
    {
        Storage::fake();

        $response = $this->actingAs($this->member(Membership::ROLE_ADMIN))
            ->postJson('/api/assets', [
                'name' => 'Kamera',
                'code' => 'AST-P1',
                'category_id' => $this->category()->getKey(),
                'photos' => [
                    UploadedFile::fake()->image('satu.jpg', 20, 20),
                    UploadedFile::fake()->image('dua.png', 20, 20),
                ],
            ]);

        $response->assertCreated();

        $this->assertDatabaseCount('attachments', 2);
        $this->assertNotNull($response->json('data.photos.0.url'));
    }

    public function test_attachment_download_serves_uploaded_file(): void
    {
        Storage::fake();

        $response = $this->actingAs($this->member(Membership::ROLE_ADMIN))
            ->postJson('/api/assets', [
                'name' => 'Kamera',
                'code' => 'AST-P2',
                'category_id' => $this->category()->getKey(),
                'photos' => [UploadedFile::fake()->image('foto.jpg', 20, 20)],
            ])
            ->assertCreated();

        $attachmentId = $response->json('data.photos.0.id');

        $this->actingAs($this->member(Membership::ROLE_BORROWER))
            ->get('/api/attachments/'.$attachmentId)
            ->assertOk();

        $this->assertDatabaseCount('attachments', 1);
        $this->assertDatabaseHas('attachments', ['type' => Attachment::TYPE_PHOTO]);
    }
}
