<?php

namespace Tests\Feature\Lending;

use App\Models\Asset;
use App\Models\Loan;
use App\Models\LoanReservation;
use App\Models\Membership;
use App\Models\OrganizationPolicy;
use Illuminate\Support\Carbon;
use Tests\Support\TenantTestCase;

/**
 * Loop request -> approve/reject/cancel (Lending Lifecycle C dan D).
 */
class LoanLifecycleTest extends TenantTestCase
{
    /**
     * @param  list<Asset>  $assets
     * @return array<string, mixed>
     */
    private function payload(array $assets, ?Carbon $start = null, ?Carbon $end = null): array
    {
        $start ??= now()->addDay()->startOfHour();
        $end ??= $start->copy()->addDays(2);

        return [
            'starts_at' => $start->toIso8601String(),
            'ends_at' => $end->toIso8601String(),
            'purpose' => 'Rapat tim',
            'assets' => array_map(fn (Asset $asset): int => $asset->getKey(), $assets),
        ];
    }

    public function test_borrower_can_request_a_loan(): void
    {
        $asset = $this->asset();
        $borrower = $this->member(Membership::ROLE_BORROWER);

        $response = $this->actingAs($borrower)
            ->postJson('/api/loans', $this->payload([$asset]));

        $response->assertCreated()
            ->assertJsonPath('data.status', Loan::STATUS_REQUESTED)
            ->assertJsonPath('data.status_label', 'Menunggu persetujuan')
            ->assertJsonPath('data.items.0.asset.id', $asset->getKey());

        $this->assertDatabaseHas('loans', [
            'organization_id' => $this->organization->getKey(),
            'borrower_id' => $borrower->getKey(),
            'status' => Loan::STATUS_REQUESTED,
        ]);
        $this->assertDatabaseHas('audit_events', ['action' => 'loan.requested']);
        $this->assertDatabaseCount('loan_reservations', 0);
    }

    public function test_request_with_inactive_asset_is_rejected(): void
    {
        $asset = $this->asset(attributes: ['lifecycle_status' => Asset::LIFECYCLE_UNDER_MAINTENANCE]);

        $this->actingAs($this->member(Membership::ROLE_BORROWER))
            ->postJson('/api/loans', $this->payload([$asset]))
            ->assertStatus(422);
    }

    public function test_request_past_end_is_rejected(): void
    {
        $asset = $this->asset();

        $this->actingAs($this->member(Membership::ROLE_BORROWER))
            ->postJson('/api/loans', [
                'starts_at' => now()->addDays(3)->toIso8601String(),
                'ends_at' => now()->addDay()->toIso8601String(),
                'assets' => [$asset->getKey()],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ends_at');
    }

    public function test_request_violating_notice_policy_is_rejected(): void
    {
        $asset = $this->asset();

        $this->actingAs($this->member(Membership::ROLE_BORROWER))
            ->postJson('/api/loans', $this->payload(
                [$asset],
                now()->addHour()->startOfHour(),
                now()->addHours(5),
            ))
            ->assertStatus(422)
            ->assertJsonValidationErrors('starts_at');
    }

    public function test_request_violating_max_duration_policy_is_rejected(): void
    {
        $asset = $this->asset();

        $this->actingAs($this->member(Membership::ROLE_BORROWER))
            ->postJson('/api/loans', $this->payload(
                [$asset],
                now()->addDay()->startOfHour(),
                now()->addDays(20),
            ))
            ->assertStatus(422)
            ->assertJsonValidationErrors('starts_at');
    }

    public function test_operator_can_approve_and_reservation_is_created(): void
    {
        $asset = $this->asset();
        $borrower = $this->member(Membership::ROLE_BORROWER);
        $operator = $this->member(Membership::ROLE_OPERATOR);

        $loanId = $this->actingAs($borrower)
            ->postJson('/api/loans', $this->payload([$asset]))
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($operator)
            ->postJson('/api/loans/'.$loanId.'/approve')
            ->assertOk()
            ->assertJsonPath('data.status', Loan::STATUS_APPROVED)
            ->assertJsonPath('data.is_awaiting_pickup', true);

        $this->assertDatabaseHas('loans', ['id' => $loanId, 'status' => Loan::STATUS_APPROVED]);
        $this->assertDatabaseHas('loan_reservations', [
            'loan_id' => $loanId,
            'asset_id' => $asset->getKey(),
            'status' => Loan::STATUS_APPROVED,
        ]);
        $this->assertDatabaseHas('audit_events', ['action' => 'loan.approved']);
    }

    public function test_approve_requires_reason_to_reject_and_records_it(): void
    {
        $asset = $this->asset();
        $borrower = $this->member(Membership::ROLE_BORROWER);
        $operator = $this->member(Membership::ROLE_OPERATOR);

        $loanId = $this->actingAs($borrower)
            ->postJson('/api/loans', $this->payload([$asset]))
            ->json('data.id');

        $this->actingAs($operator)
            ->postJson('/api/loans/'.$loanId.'/reject', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->actingAs($operator)
            ->postJson('/api/loans/'.$loanId.'/reject', ['reason' => 'Aset dipakai rapat lain'])
            ->assertOk()
            ->assertJsonPath('data.status', Loan::STATUS_REJECTED)
            ->assertJsonPath('data.rejection_reason', 'Aset dipakai rapat lain');

        $this->assertDatabaseHas('audit_events', ['action' => 'loan.rejected']);
    }

    public function test_borrower_can_cancel_own_request(): void
    {
        $asset = $this->asset();
        $borrower = $this->member(Membership::ROLE_BORROWER);

        $loanId = $this->actingAs($borrower)
            ->postJson('/api/loans', $this->payload([$asset]))
            ->json('data.id');

        $this->actingAs($borrower)
            ->postJson('/api/loans/'.$loanId.'/cancel')
            ->assertOk()
            ->assertJsonPath('data.status', Loan::STATUS_CANCELLED);

        $this->assertDatabaseHas('audit_events', ['action' => 'loan.cancelled']);
    }

    public function test_cancel_approved_loan_releases_reservation(): void
    {
        $asset = $this->asset();
        $borrower = $this->member(Membership::ROLE_BORROWER);

        $loan = Loan::factory()->approved()->create([
            'organization_id' => $this->organization->getKey(),
            'borrower_id' => $borrower->getKey(),
        ]);
        LoanReservation::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'loan_id' => $loan->getKey(),
            'asset_id' => $asset->getKey(),
            'status' => Loan::STATUS_APPROVED,
        ]);

        $this->actingAs($borrower)
            ->postJson('/api/loans/'.$loan->getKey().'/cancel')
            ->assertOk()
            ->assertJsonPath('data.status', Loan::STATUS_CANCELLED);

        $this->assertDatabaseCount('loan_reservations', 0);
    }

    public function test_admin_cancel_of_foreign_loan_requires_reason(): void
    {
        $asset = $this->asset();
        $borrower = $this->member(Membership::ROLE_BORROWER);
        $admin = $this->member(Membership::ROLE_ADMIN);

        $loanId = $this->actingAs($borrower)
            ->postJson('/api/loans', $this->payload([$asset]))
            ->json('data.id');

        $this->actingAs($admin)
            ->postJson('/api/loans/'.$loanId.'/cancel')
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->actingAs($admin)
            ->postJson('/api/loans/'.$loanId.'/cancel', ['reason' => 'Salah input'])
            ->assertOk()
            ->assertJsonPath('data.status', Loan::STATUS_CANCELLED);
    }

    public function test_borrower_cannot_cancel_approved_when_policy_disallows(): void
    {
        $this->policy(['allow_borrower_cancel' => false]);

        $asset = $this->asset();
        $borrower = $this->member(Membership::ROLE_BORROWER);

        $loan = Loan::factory()->approved()->create([
            'organization_id' => $this->organization->getKey(),
            'borrower_id' => $borrower->getKey(),
        ]);

        $this->actingAs($borrower)
            ->postJson('/api/loans/'.$loan->getKey().'/cancel')
            ->assertForbidden();
    }

    public function test_invalid_transition_is_rejected(): void
    {
        $borrower = $this->member(Membership::ROLE_BORROWER);
        $operator = $this->member(Membership::ROLE_OPERATOR);

        $loan = Loan::factory()->cancelled()->create([
            'organization_id' => $this->organization->getKey(),
            'borrower_id' => $borrower->getKey(),
        ]);

        $this->actingAs($operator)
            ->postJson('/api/loans/'.$loan->getKey().'/approve')
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertSame(Loan::STATUS_CANCELLED, $loan->fresh()->status);
    }

    public function test_approve_recheck_sets_due_at_after_ends_at(): void
    {
        $asset = $this->asset();
        $borrower = $this->member(Membership::ROLE_BORROWER);
        $operator = $this->member(Membership::ROLE_OPERATOR);

        $loanId = $this->actingAs($borrower)
            ->postJson('/api/loans', $this->payload([$asset]))
            ->json('data.id');

        $this->actingAs($operator)
            ->postJson('/api/loans/'.$loanId.'/approve')
            ->assertOk();

        $loan = Loan::withoutOrganizationScope()->findOrFail($loanId);

        $this->assertNotNull($loan->due_at);
        $this->assertTrue($loan->due_at->greaterThanOrEqualTo($loan->ends_at));
        $this->assertNotNull($loan->approved_at);
    }

    public function test_borrower_index_shows_only_own_loans(): void
    {
        $borrowerA = $this->member(Membership::ROLE_BORROWER);
        $borrowerB = $this->member(Membership::ROLE_BORROWER);

        $loanA = Loan::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'borrower_id' => $borrowerA->getKey(),
        ]);
        $loanB = Loan::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'borrower_id' => $borrowerB->getKey(),
        ]);

        $ids = collect(
            $this->actingAs($borrowerA)->getJson('/api/loans')->assertOk()->json('data')
        )->pluck('id');

        $this->assertContains($loanA->getKey(), $ids);
        $this->assertNotContains($loanB->getKey(), $ids);
    }

    public function test_operator_index_shows_all_loans_in_organization(): void
    {
        $borrower = $this->member(Membership::ROLE_BORROWER);
        $operator = $this->member(Membership::ROLE_OPERATOR);

        Loan::factory()->count(2)->create([
            'organization_id' => $this->organization->getKey(),
            'borrower_id' => $borrower->getKey(),
        ]);

        $response = $this->actingAs($operator)->getJson('/api/loans')->assertOk();

        $this->assertCount(2, $response->json('data'));
    }

    public function test_policy_table_bounds_are_default_when_absent(): void
    {
        $this->assertDatabaseCount('organization_policies', 0);

        $policy = OrganizationPolicy::resolve($this->organization->getKey());

        $this->assertSame(48, $policy->pickup_window_hours);
        $this->assertSame(14, $policy->max_loan_duration_days);
    }

    public function test_legacy_status_filter_and_group_filter(): void
    {
        $borrower = $this->member(Membership::ROLE_BORROWER);

        Loan::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'borrower_id' => $borrower->getKey(),
            'status' => Loan::STATUS_REQUESTED,
        ]);
        $approved = Loan::factory()->approved()->create([
            'organization_id' => $this->organization->getKey(),
            'borrower_id' => $borrower->getKey(),
        ]);

        $pending = $this->actingAs($borrower)->getJson('/api/loans?group=pending')->assertOk();
        $this->assertCount(1, $pending->json('data'));

        $active = $this->actingAs($borrower)->getJson('/api/loans?group=active')->assertOk();
        $this->assertCount(1, $active->json('data'));
        $this->assertSame($approved->getKey(), $active->json('data.0.id'));

        $byStatus = $this->actingAs($borrower)->getJson('/api/loans?status=requested')->assertOk();
        $this->assertCount(1, $byStatus->json('data'));
    }
}
