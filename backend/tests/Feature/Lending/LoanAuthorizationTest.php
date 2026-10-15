<?php

namespace Tests\Feature\Lending;

use App\Models\Loan;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Tests\Support\TenantTestCase;

/**
 * Authorization Model untuk loan: matriks role, object level 403, isolasi lintas
 * organisasi 404 (CS-04), dan pencatatan access_denied.
 */
class LoanAuthorizationTest extends TenantTestCase
{
    private Organization $organizationB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organizationB = Organization::factory()->create();
    }

    private function loanFor(User $borrower): Loan
    {
        return Loan::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'borrower_id' => $borrower->getKey(),
        ]);
    }

    public function test_guest_cannot_list_loans(): void
    {
        $this->getJson('/api/loans')->assertUnauthorized();
    }

    public function test_borrower_cannot_approve(): void
    {
        $borrower = $this->member(Membership::ROLE_BORROWER);
        $loan = $this->loanFor($borrower);

        $this->actingAs($borrower)
            ->postJson('/api/loans/'.$loan->getKey().'/approve')
            ->assertForbidden();
    }

    public function test_borrower_cannot_reject(): void
    {
        $borrower = $this->member(Membership::ROLE_BORROWER);
        $loan = $this->loanFor($borrower);

        $this->actingAs($borrower)
            ->postJson('/api/loans/'.$loan->getKey().'/reject', ['reason' => 'Coba-coba'])
            ->assertForbidden();
    }

    public function test_borrower_cannot_view_another_borrowers_loan(): void
    {
        $borrowerA = $this->member(Membership::ROLE_BORROWER);
        $borrowerB = $this->member(Membership::ROLE_BORROWER);
        $loan = $this->loanFor($borrowerA);

        $this->actingAs($borrowerB)
            ->getJson('/api/loans/'.$loan->getKey())
            ->assertForbidden();
    }

    public function test_operator_can_view_any_loan_in_organization(): void
    {
        $borrower = $this->member(Membership::ROLE_BORROWER);
        $operator = $this->member(Membership::ROLE_OPERATOR);
        $loan = $this->loanFor($borrower);

        $this->actingAs($operator)
            ->getJson('/api/loans/'.$loan->getKey())
            ->assertOk()
            ->assertJsonPath('data.id', $loan->getKey());
    }

    public function test_foreign_organization_loan_returns_404(): void
    {
        $adminA = $this->member(Membership::ROLE_ADMIN);
        $borrowerB = $this->userIn($this->organizationB, Membership::ROLE_BORROWER);

        $foreign = Loan::factory()->create([
            'organization_id' => $this->organizationB->getKey(),
            'borrower_id' => $borrowerB->getKey(),
        ]);

        $this->actingAs($adminA)
            ->getJson('/api/loans/'.$foreign->getKey())
            ->assertNotFound();

        $this->assertDatabaseHas('audit_events', ['action' => 'access_denied']);
    }

    public function test_foreign_organization_loan_cannot_be_approved(): void
    {
        $adminA = $this->member(Membership::ROLE_ADMIN);
        $borrowerB = $this->userIn($this->organizationB, Membership::ROLE_BORROWER);

        $foreign = Loan::factory()->create([
            'organization_id' => $this->organizationB->getKey(),
            'borrower_id' => $borrowerB->getKey(),
        ]);

        $this->actingAs($adminA)
            ->postJson('/api/loans/'.$foreign->getKey().'/approve')
            ->assertNotFound();

        $this->assertSame(Loan::STATUS_REQUESTED, $foreign->fresh()->status);
    }

    public function test_borrower_list_excludes_foreign_organization(): void
    {
        $borrowerA = $this->member(Membership::ROLE_BORROWER);
        $borrowerB = $this->userIn($this->organizationB, Membership::ROLE_BORROWER);

        $own = $this->loanFor($borrowerA);
        Loan::factory()->create([
            'organization_id' => $this->organizationB->getKey(),
            'borrower_id' => $borrowerB->getKey(),
        ]);

        $ids = collect(
            $this->actingAs($borrowerA)->getJson('/api/loans')->assertOk()->json('data')
        )->pluck('id');

        $this->assertContains($own->getKey(), $ids);
        $this->assertCount(1, $ids);
    }
}
