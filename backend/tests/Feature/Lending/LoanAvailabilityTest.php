<?php

namespace Tests\Feature\Lending;

use App\Models\Asset;
use App\Models\Loan;
use App\Models\LoanReservation;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Tests\Support\TenantTestCase;

/**
 * Availability Rules: overlap setengah terbuka, dua lapis pengaman (CS-01),
 * dan pelepasan periode oleh loan yang kedaluwarsa (CS-05).
 */
class LoanAvailabilityTest extends TenantTestCase
{
    /**
     * Loan disetujui plus baris reservasinya, tanpa lewat service. Dipakai untuk
     * mengatur periode secara presisi.
     */
    private function approvedReservation(Asset $asset, Carbon $start, Carbon $end, ?User $borrower = null, ?Carbon $approvedAt = null): Loan
    {
        $borrower ??= $this->member(Membership::ROLE_BORROWER);

        $loan = Loan::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'borrower_id' => $borrower->getKey(),
            'status' => Loan::STATUS_APPROVED,
            'starts_at' => $start,
            'ends_at' => $end,
            'due_at' => $end,
            'approved_by' => null,
            'approved_at' => $approvedAt ?? now(),
        ]);

        LoanReservation::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'loan_id' => $loan->getKey(),
            'asset_id' => $asset->getKey(),
            'status' => Loan::STATUS_APPROVED,
            'starts_at' => $start,
            'ends_at' => $end,
        ]);

        return $loan;
    }

    /**
     * @return array<string, string>
     */
    private function period(Carbon $start, Carbon $end): array
    {
        return [
            'starts_at' => $start->toIso8601String(),
            'ends_at' => $end->toIso8601String(),
        ];
    }

    public function test_free_period_is_requestable(): void
    {
        $asset = $this->asset();
        $start = now()->addDay()->startOfHour();
        $end = $start->copy()->addHours(6);

        $this->actingAs($this->member(Membership::ROLE_BORROWER))
            ->getJson('/api/assets/'.$asset->getKey().'/availability?'.http_build_query($this->period($start, $end)))
            ->assertOk()
            ->assertJsonPath('data.requestable', true)
            ->assertJsonPath('data.asset.available', true);
    }

    public function test_overlapping_approved_loan_is_reported(): void
    {
        $asset = $this->asset();
        $start = now()->addDay()->startOfHour();
        $end = $start->copy()->addHours(4);

        $this->approvedReservation($asset, $start, $end);

        $viewer = $this->member(Membership::ROLE_BORROWER);
        $query = $this->period($start->copy()->addHour(), $end->copy()->addHours(2));

        $this->actingAs($viewer)
            ->getJson('/api/assets/'.$asset->getKey().'/availability?'.http_build_query($query))
            ->assertOk()
            ->assertJsonPath('data.requestable', false)
            ->assertJsonPath('data.blocking.0.status', Loan::STATUS_APPROVED);
    }

    public function test_half_open_boundary_is_not_a_conflict(): void
    {
        $asset = $this->asset();
        $start = now()->addDay()->startOfHour();
        $end = $start->copy()->addHours(4);

        $this->approvedReservation($asset, $start, $end);

        $viewer = $this->member(Membership::ROLE_BORROWER);

        // Mulai tepat saat loan lama berakhir: tidak overlap.
        $after = $this->period($end, $end->copy()->addHours(4));
        $this->actingAs($viewer)
            ->getJson('/api/assets/'.$asset->getKey().'/availability?'.http_build_query($after))
            ->assertOk()
            ->assertJsonPath('data.requestable', true);
    }

    public function test_requested_loans_do_not_block_each_other(): void
    {
        $asset = $this->asset();
        $borrowerA = $this->member(Membership::ROLE_BORROWER);
        $borrowerB = $this->member(Membership::ROLE_BORROWER);

        $start = now()->addDay()->startOfHour();
        $end = $start->copy()->addDays(2);

        $this->actingAs($borrowerA)
            ->postJson('/api/loans', [
                'starts_at' => $start->toIso8601String(),
                'ends_at' => $end->toIso8601String(),
                'assets' => [$asset->getKey()],
            ])
            ->assertCreated();

        $this->actingAs($borrowerB)
            ->postJson('/api/loans', [
                'starts_at' => $start->toIso8601String(),
                'ends_at' => $end->toIso8601String(),
                'assets' => [$asset->getKey()],
            ])
            ->assertCreated();

        $this->assertDatabaseCount('loans', 2);
        $this->assertDatabaseCount('loan_reservations', 0);
    }

    public function test_second_overlapping_approval_is_rejected(): void
    {
        $asset = $this->asset();
        $borrowerA = $this->member(Membership::ROLE_BORROWER);
        $borrowerB = $this->member(Membership::ROLE_BORROWER);
        $operator = $this->member(Membership::ROLE_OPERATOR);

        $start = now()->addDay()->startOfHour();
        $end = $start->copy()->addDays(2);
        $payload = [
            'starts_at' => $start->toIso8601String(),
            'ends_at' => $end->toIso8601String(),
            'assets' => [$asset->getKey()],
        ];

        $loanA = $this->actingAs($borrowerA)->postJson('/api/loans', $payload)->json('data.id');
        $loanB = $this->actingAs($borrowerB)->postJson('/api/loans', $payload)->json('data.id');

        $this->actingAs($operator)->postJson('/api/loans/'.$loanA.'/approve')->assertOk();

        $this->actingAs($operator)
            ->postJson('/api/loans/'.$loanB.'/approve')
            ->assertStatus(422)
            ->assertJsonValidationErrors('assets');

        $this->assertDatabaseCount('loan_reservations', 1);
        $this->assertDatabaseHas('loan_reservations', ['loan_id' => $loanA]);
    }

    public function test_expired_approved_loan_releases_the_period(): void
    {
        $asset = $this->asset();
        $borrowerA = $this->member(Membership::ROLE_BORROWER);
        $borrowerB = $this->member(Membership::ROLE_BORROWER);
        $operator = $this->member(Membership::ROLE_OPERATOR);

        $start = now()->addDay()->startOfHour();
        $end = $start->copy()->addDays(2);

        // Disetujui 49 jam lalu, jendela pengambilan default 48 jam: turunan kedaluwarsa.
        $this->approvedReservation($asset, $start, $end, $borrowerA, now()->subHours(49));

        $payload = [
            'starts_at' => $start->toIso8601String(),
            'ends_at' => $end->toIso8601String(),
            'assets' => [$asset->getKey()],
        ];

        $loanB = $this->actingAs($borrowerB)->postJson('/api/loans', $payload)->json('data.id');

        $this->actingAs($operator)
            ->postJson('/api/loans/'.$loanB.'/approve')
            ->assertOk()
            ->assertJsonPath('data.status', Loan::STATUS_APPROVED);

        $this->assertDatabaseCount('loan_reservations', 1);
        $this->assertDatabaseHas('loan_reservations', ['loan_id' => $loanB]);
    }

    public function test_database_exclusion_constraint_exists(): void
    {
        $exists = \DB::table('pg_constraint')
            ->where('conname', 'loan_reservations_no_overlap')
            ->exists();

        $this->assertTrue($exists, 'Exclusion constraint lapis 2 tidak ada.');
    }

    public function test_database_rejects_overlapping_reservations(): void
    {
        $asset = $this->asset();
        $loanA = Loan::factory()->create(['organization_id' => $this->organization->getKey()]);
        $loanB = Loan::factory()->create(['organization_id' => $this->organization->getKey()]);

        $start = now()->addDay()->startOfHour();

        LoanReservation::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'loan_id' => $loanA->getKey(),
            'asset_id' => $asset->getKey(),
            'status' => Loan::STATUS_APPROVED,
            'starts_at' => $start,
            'ends_at' => $start->copy()->addHours(4),
        ]);

        $this->expectException(QueryException::class);

        LoanReservation::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'loan_id' => $loanB->getKey(),
            'asset_id' => $asset->getKey(),
            'status' => Loan::STATUS_APPROVED,
            'starts_at' => $start->copy()->addHours(2),
            'ends_at' => $start->copy()->addHours(6),
        ]);
    }
}
