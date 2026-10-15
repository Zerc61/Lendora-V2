<?php

namespace Tests\Feature\Lending;

use App\Models\Asset;
use App\Models\AssetConditionRecord;
use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TenantTestCase;

/**
 * Loop operasional: serah terima, pengembalian, inspeksi (Fase 7).
 *
 * Menguji CS-03 (aset kembali rusak), CS-07 (transisi tidak sah), gerbang
 * inspeksi ADR-007, dan pemisahan occupancy dari lifecycle (ADR-002).
 */
class LoanOperationsTest extends TenantTestCase
{
    /**
     * @param  list<Asset>  $assets
     * @param  array<string, mixed>  $attributes
     */
    private function loan(string $state, array $assets, User $borrower, array $attributes = []): Loan
    {
        $factory = Loan::factory();

        $factory = match ($state) {
            'approved' => $factory->approved(),
            'checkedOut' => $factory->checkedOut(),
            default => $factory,
        };

        $loan = $factory->create(array_merge([
            'organization_id' => $this->organization->getKey(),
            'borrower_id' => $borrower->getKey(),
        ], $attributes));

        foreach ($assets as $asset) {
            LoanItem::query()->create([
                'loan_id' => $loan->getKey(),
                'asset_id' => $asset->getKey(),
            ]);
        }

        return $loan;
    }

    /**
     * @param  list<Asset>  $assets
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function payload(array $assets, string $condition = Asset::CONDITION_GOOD, array $extra = []): array
    {
        return [
            'items' => array_map(
                fn (Asset $asset): array => ['asset_id' => $asset->getKey(), 'condition' => $condition] + $extra,
                $assets,
            ),
        ];
    }

    public function test_operator_can_checkout_an_approved_loan(): void
    {
        $asset = $this->asset();
        $borrower = $this->member(Membership::ROLE_BORROWER);
        $operator = $this->member(Membership::ROLE_OPERATOR);

        $loan = $this->loan('approved', [$asset], $borrower);

        $this->actingAs($operator)
            ->postJson('/api/loans/'.$loan->getKey().'/checkout', $this->payload([$asset]))
            ->assertOk()
            ->assertJsonPath('data.status', Loan::STATUS_CHECKED_OUT);

        $this->assertDatabaseHas('loans', [
            'id' => $loan->getKey(),
            'status' => Loan::STATUS_CHECKED_OUT,
        ]);
        $this->assertDatabaseHas('asset_condition_records', [
            'asset_id' => $asset->getKey(),
            'condition' => Asset::CONDITION_GOOD,
            'source' => AssetConditionRecord::SOURCE_CHECKOUT,
            'loan_id' => $loan->getKey(),
        ]);
        $this->assertDatabaseHas('audit_events', ['action' => 'loan.checked_out']);

        // Occupancy tidak disimpan di aset (ADR-002).
        $this->assertSame(Asset::LIFECYCLE_ACTIVE, $asset->fresh()->lifecycle_status);
    }

    public function test_checkout_is_atomic_when_one_asset_is_not_borrowable(): void
    {
        $good = $this->asset();
        $bad = $this->asset(attributes: ['lifecycle_status' => Asset::LIFECYCLE_UNDER_MAINTENANCE]);
        $borrower = $this->member(Membership::ROLE_BORROWER);
        $operator = $this->member(Membership::ROLE_OPERATOR);

        $loan = $this->loan('approved', [$good, $bad], $borrower);

        $this->actingAs($operator)
            ->postJson('/api/loans/'.$loan->getKey().'/checkout', $this->payload([$good, $bad]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');

        $this->assertDatabaseCount('asset_condition_records', 0);
        $this->assertSame(Loan::STATUS_APPROVED, $loan->fresh()->status);
    }

    public function test_checkout_requires_notes_and_photo_for_poor_condition(): void
    {
        Storage::fake();

        $asset = $this->asset();
        $borrower = $this->member(Membership::ROLE_BORROWER);
        $operator = $this->member(Membership::ROLE_OPERATOR);
        $loan = $this->loan('approved', [$asset], $borrower);

        $this->actingAs($operator)
            ->postJson('/api/loans/'.$loan->getKey().'/checkout', $this->payload([$asset], Asset::CONDITION_POOR))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.notes', 'items.0.photos']);
    }

    public function test_poor_condition_holds_checkout_even_with_notes_and_photo(): void
    {
        Storage::fake();

        $asset = $this->asset();
        $borrower = $this->member(Membership::ROLE_BORROWER);
        $operator = $this->member(Membership::ROLE_OPERATOR);
        $loan = $this->loan('approved', [$asset], $borrower);

        $this->actingAs($operator)
            ->postJson('/api/loans/'.$loan->getKey().'/checkout', $this->payload([$asset], Asset::CONDITION_POOR, [
                'notes' => 'Lecet halus',
                'photos' => [UploadedFile::fake()->image('lecet.jpg')],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');

        $this->assertDatabaseCount('asset_condition_records', 0);
        $this->assertSame(Asset::LIFECYCLE_ACTIVE, $asset->fresh()->lifecycle_status);
    }

    public function test_unserviceable_asset_cancels_checkout_and_goes_to_maintenance(): void
    {
        Storage::fake();

        $asset = $this->asset();
        $borrower = $this->member(Membership::ROLE_BORROWER);
        $operator = $this->member(Membership::ROLE_OPERATOR);
        $loan = $this->loan('approved', [$asset], $borrower);

        $this->actingAs($operator)
            ->postJson('/api/loans/'.$loan->getKey().'/checkout', $this->payload([$asset], Asset::CONDITION_UNSERVICEABLE, [
                'notes' => 'Tidak menyala',
                'photos' => [UploadedFile::fake()->image('rusak.jpg')],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');

        $this->assertSame(Asset::LIFECYCLE_UNDER_MAINTENANCE, $asset->fresh()->lifecycle_status);
        $this->assertDatabaseHas('asset_condition_records', [
            'asset_id' => $asset->getKey(),
            'condition' => Asset::CONDITION_UNSERVICEABLE,
            'source' => AssetConditionRecord::SOURCE_CHECKOUT,
        ]);
        $this->assertDatabaseHas('audit_events', ['action' => 'asset.maintenance_required']);
        // Checkout tetap tidak terjadi.
        $this->assertSame(Loan::STATUS_APPROVED, $loan->fresh()->status);
    }

    public function test_expired_approved_loan_cannot_be_checked_out(): void
    {
        $asset = $this->asset();
        $borrower = $this->member(Membership::ROLE_BORROWER);
        $operator = $this->member(Membership::ROLE_OPERATOR);

        $loan = $this->loan('approved', [$asset], $borrower);
        $loan->approved_at = now()->subDays(5);
        $loan->save();

        $this->actingAs($operator)
            ->postJson('/api/loans/'.$loan->getKey().'/checkout', $this->payload([$asset]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertSame(Loan::STATUS_APPROVED, $loan->fresh()->status);
    }

    public function test_checkout_rejects_invalid_transition(): void
    {
        $asset = $this->asset();
        $borrower = $this->member(Membership::ROLE_BORROWER);
        $operator = $this->member(Membership::ROLE_OPERATOR);

        $loan = $this->loan('requested', [$asset], $borrower);

        $this->actingAs($operator)
            ->postJson('/api/loans/'.$loan->getKey().'/checkout', $this->payload([$asset]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_operator_can_checkin_a_checked_out_loan(): void
    {
        $asset = $this->asset();
        $borrower = $this->member(Membership::ROLE_BORROWER);
        $operator = $this->member(Membership::ROLE_OPERATOR);

        $loan = $this->loan('checkedOut', [$asset], $borrower);

        $this->actingAs($operator)
            ->postJson('/api/loans/'.$loan->getKey().'/return', $this->payload([$asset]))
            ->assertOk()
            ->assertJsonPath('data.status', Loan::STATUS_RETURNED);

        $this->assertDatabaseHas('asset_condition_records', [
            'asset_id' => $asset->getKey(),
            'source' => AssetConditionRecord::SOURCE_CHECKIN,
            'loan_id' => $loan->getKey(),
        ]);
        // Gerbang inspeksi: aset menunggu pemeriksaan, tidak langsung aktif (ADR-007).
        $this->assertSame(Asset::LIFECYCLE_AWAITING_INSPECTION, $asset->fresh()->lifecycle_status);
        $this->assertFalse($asset->fresh()->isBorrowable());
        $this->assertDatabaseHas('audit_events', ['action' => 'loan.returned']);
    }

    public function test_late_return_is_flagged(): void
    {
        $asset = $this->asset();
        $borrower = $this->member(Membership::ROLE_BORROWER);
        $operator = $this->member(Membership::ROLE_OPERATOR);

        $loan = $this->loan('checkedOut', [$asset], $borrower);
        $loan->starts_at = now()->subDays(5);
        $loan->ends_at = now()->subDays(2);
        $loan->due_at = now()->subDay();
        $loan->save();

        $this->actingAs($operator)
            ->postJson('/api/loans/'.$loan->getKey().'/return', $this->payload([$asset]))
            ->assertOk()
            ->assertJsonPath('data.is_late', true);

        $this->assertTrue($loan->fresh()->is_late);
    }

    public function test_checkin_rejects_invalid_transition(): void
    {
        $asset = $this->asset();
        $borrower = $this->member(Membership::ROLE_BORROWER);
        $operator = $this->member(Membership::ROLE_OPERATOR);

        $loan = $this->loan('approved', [$asset], $borrower);

        $this->actingAs($operator)
            ->postJson('/api/loans/'.$loan->getKey().'/return', $this->payload([$asset]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_return_does_not_alter_previous_condition_records(): void
    {
        Storage::fake();

        $asset = $this->asset();
        $borrower = $this->member(Membership::ROLE_BORROWER);
        $operator = $this->member(Membership::ROLE_OPERATOR);
        $loan = $this->loan('checkedOut', [$asset], $borrower);

        $previous = AssetConditionRecord::factory()->create([
            'asset_id' => $asset->getKey(),
            'condition' => Asset::CONDITION_GOOD,
            'source' => AssetConditionRecord::SOURCE_CHECKOUT,
            'loan_id' => $loan->getKey(),
        ]);

        // CS-03: dikembalikan dalam kondisi buruk.
        $this->actingAs($operator)
            ->postJson('/api/loans/'.$loan->getKey().'/return', $this->payload([$asset], Asset::CONDITION_POOR, [
                'notes' => 'Layar retak',
                'photos' => [UploadedFile::fake()->image('retak.jpg')],
            ]))
            ->assertOk();

        $fresh = $asset->fresh();

        $this->assertSame(Asset::LIFECYCLE_AWAITING_INSPECTION, $fresh->lifecycle_status);
        $this->assertSame(Asset::CONDITION_POOR, $fresh->condition);

        $this->assertSame(Asset::CONDITION_GOOD, $previous->fresh()->condition);
        $this->assertSame(AssetConditionRecord::SOURCE_CHECKOUT, $previous->fresh()->source);
    }

    public function test_inspection_returns_asset_to_active_when_condition_is_fair_or_better(): void
    {
        $asset = $this->asset(attributes: ['lifecycle_status' => Asset::LIFECYCLE_AWAITING_INSPECTION]);
        $operator = $this->member(Membership::ROLE_OPERATOR);

        AssetConditionRecord::factory()->create([
            'asset_id' => $asset->getKey(),
            'condition' => Asset::CONDITION_FAIR,
            'source' => AssetConditionRecord::SOURCE_CHECKIN,
        ]);

        $this->actingAs($operator)
            ->postJson('/api/assets/'.$asset->getKey().'/inspection', ['notes' => 'Masih layak'])
            ->assertOk()
            ->assertJsonPath('data.is_borrowable', true);

        $this->assertSame(Asset::LIFECYCLE_ACTIVE, $asset->fresh()->lifecycle_status);
        $this->assertDatabaseHas('audit_events', ['action' => 'asset.inspected']);
    }

    public function test_inspection_keeps_poor_asset_in_maintenance(): void
    {
        $asset = $this->asset(attributes: ['lifecycle_status' => Asset::LIFECYCLE_AWAITING_INSPECTION]);
        $operator = $this->member(Membership::ROLE_OPERATOR);

        AssetConditionRecord::factory()->create([
            'asset_id' => $asset->getKey(),
            'condition' => Asset::CONDITION_POOR,
            'notes' => 'Perlu perbaikan',
            'source' => AssetConditionRecord::SOURCE_CHECKIN,
        ]);

        $this->actingAs($operator)
            ->postJson('/api/assets/'.$asset->getKey().'/inspection')
            ->assertOk()
            ->assertJsonPath('data.is_borrowable', false);

        $this->assertSame(Asset::LIFECYCLE_UNDER_MAINTENANCE, $asset->fresh()->lifecycle_status);
    }

    public function test_inspection_requires_asset_awaiting_inspection(): void
    {
        $asset = $this->asset();
        $operator = $this->member(Membership::ROLE_OPERATOR);

        $this->actingAs($operator)
            ->postJson('/api/assets/'.$asset->getKey().'/inspection')
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_condition_record_is_append_only(): void
    {
        $asset = $this->asset();
        $record = AssetConditionRecord::factory()->create([
            'asset_id' => $asset->getKey(),
            'condition' => Asset::CONDITION_GOOD,
        ]);

        $this->expectException(QueryException::class);

        $record->update(['condition' => Asset::CONDITION_POOR, 'notes' => 'tampered']);
    }

    public function test_operator_can_list_condition_records(): void
    {
        $asset = $this->asset();
        $operator = $this->member(Membership::ROLE_OPERATOR);

        AssetConditionRecord::factory()->count(2)->create([
            'asset_id' => $asset->getKey(),
            'condition' => Asset::CONDITION_GOOD,
        ]);

        $this->actingAs($operator)
            ->getJson('/api/assets/'.$asset->getKey().'/condition-records')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_borrower_cannot_checkout_checkin_or_inspect(): void
    {
        $asset = $this->asset();
        $borrower = $this->member(Membership::ROLE_BORROWER);

        $approved = $this->loan('approved', [$asset], $borrower);
        $checkedOut = $this->loan('checkedOut', [$asset], $borrower);

        $this->actingAs($borrower)
            ->postJson('/api/loans/'.$approved->getKey().'/checkout', $this->payload([$asset]))
            ->assertForbidden();

        $this->actingAs($borrower)
            ->postJson('/api/loans/'.$checkedOut->getKey().'/return', $this->payload([$asset]))
            ->assertForbidden();

        $this->actingAs($borrower)
            ->postJson('/api/assets/'.$asset->getKey().'/inspection')
            ->assertForbidden();

        $this->actingAs($borrower)
            ->getJson('/api/assets/'.$asset->getKey().'/condition-records')
            ->assertForbidden();
    }

    public function test_guest_is_unauthenticated(): void
    {
        $asset = $this->asset();

        $this->postJson('/api/assets/'.$asset->getKey().'/inspection')->assertUnauthorized();
        $this->getJson('/api/operator/today')->assertUnauthorized();
    }

    public function test_cross_organization_checkout_returns_not_found(): void
    {
        $organizationB = Organization::factory()->create();

        $assetB = $this->asset($organizationB);
        $borrowerB = $this->userIn($organizationB, Membership::ROLE_BORROWER);
        $loanB = $this->loan('approved', [$assetB], $borrowerB, [
            'organization_id' => $organizationB->getKey(),
        ]);

        // Payload memakai aset organisasi sendiri supaya validasi lolos; loan
        // milik organisasi lain tidak terlihat (global scope -> 404).
        $assetA = $this->asset();
        $operatorA = $this->member(Membership::ROLE_OPERATOR);

        $this->actingAs($operatorA)
            ->postJson('/api/loans/'.$loanB->getKey().'/checkout', $this->payload([$assetA]))
            ->assertNotFound();
    }

    public function test_operator_dashboard_reports_todays_queues(): void
    {
        $borrower = $this->member(Membership::ROLE_BORROWER);
        $operator = $this->member(Membership::ROLE_OPERATOR);

        $asset = $this->asset(attributes: ['lifecycle_status' => Asset::LIFECYCLE_AWAITING_INSPECTION]);

        $awaitingPickup = $this->loan('approved', [$this->asset()], $borrower);
        $awaitingPickup->starts_at = now()->startOfDay()->addHour();
        $awaitingPickup->save();

        $dueToday = $this->loan('checkedOut', [$this->asset()], $borrower);
        $dueToday->starts_at = now()->subDays(3);
        $dueToday->ends_at = now()->subDay();
        $dueToday->due_at = now()->startOfDay()->addHours(12);
        $dueToday->save();

        $overdue = $this->loan('checkedOut', [$this->asset()], $borrower);
        $overdue->starts_at = now()->subDays(5);
        $overdue->ends_at = now()->subDays(4);
        $overdue->due_at = now()->subDays(2);
        $overdue->save();

        $this->loan('requested', [$this->asset()], $borrower);

        $this->actingAs($operator)
            ->getJson('/api/operator/today')
            ->assertOk()
            ->assertJsonPath('counts.awaiting_approval', 1)
            ->assertJsonPath('counts.awaiting_pickup', 1)
            ->assertJsonPath('counts.due_today', 1)
            ->assertJsonPath('counts.overdue', 1)
            ->assertJsonPath('counts.awaiting_inspection', 1);
    }

    public function test_borrower_cannot_view_operator_dashboard(): void
    {
        $this->actingAs($this->member(Membership::ROLE_BORROWER))
            ->getJson('/api/operator/today')
            ->assertForbidden();
    }
}
