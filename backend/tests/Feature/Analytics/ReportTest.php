<?php

namespace Tests\Feature\Analytics;

use App\Models\Asset;
use App\Models\Issue;
use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\MaintenanceTicket;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\Support\TenantTestCase;

class ReportTest extends TenantTestCase
{
    private function borrowedLoan(
        User $borrower,
        Asset $asset,
        string $status = Loan::STATUS_RETURNED,
        ?Carbon $createdAt = null,
    ): Loan {
        $factory = match ($status) {
            Loan::STATUS_APPROVED => Loan::factory()->approved(),
            Loan::STATUS_CHECKED_OUT => Loan::factory()->checkedOut(),
            default => Loan::factory()->state(['status' => $status]),
        };

        $loan = $factory->create([
            'organization_id' => $this->organization->getKey(),
            'borrower_id' => $borrower->getKey(),
        ]);

        LoanItem::query()->create([
            'loan_id' => $loan->getKey(),
            'asset_id' => $asset->getKey(),
        ]);

        if ($createdAt !== null) {
            $loan->forceFill(['created_at' => $createdAt])->save();
        }

        return $loan;
    }

    public function test_utilization_ranks_most_borrowed_assets(): void
    {
        $admin = $this->member(Membership::ROLE_ADMIN);
        $borrower = $this->member(Membership::ROLE_BORROWER);

        $popular = $this->asset(attributes: ['name' => 'Proyektor Populer']);
        $rare = $this->asset(attributes: ['name' => 'Kamera Jarang']);

        for ($i = 0; $i < 3; $i++) {
            $this->borrowedLoan($borrower, $popular);
        }

        $this->borrowedLoan($borrower, $rare);

        // Loan yang belum disetujui tidak dihitung sebagai peminjaman.
        $this->borrowedLoan($borrower, $popular, Loan::STATUS_REQUESTED);

        $this->actingAs($admin)
            ->getJson('/api/reports/utilization')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.rank', 1)
            ->assertJsonPath('data.0.asset_id', $popular->getKey())
            ->assertJsonPath('data.0.loan_count', 3)
            ->assertJsonPath('data.1.asset_id', $rare->getKey())
            ->assertJsonPath('data.1.loan_count', 1);
    }

    public function test_utilization_respects_period(): void
    {
        $admin = $this->member(Membership::ROLE_ADMIN);
        $borrower = $this->member(Membership::ROLE_BORROWER);

        $asset = $this->asset();

        $this->borrowedLoan($borrower, $asset, Loan::STATUS_RETURNED, now()->subMonths(2));
        $this->borrowedLoan($borrower, $asset, Loan::STATUS_RETURNED, now()->subDay());

        $this->actingAs($admin)
            ->getJson('/api/reports/utilization')
            ->assertOk()
            ->assertJsonPath('data.0.loan_count', 2);

        $from = now()->subWeek()->toDateString();

        $this->actingAs($admin)
            ->getJson('/api/reports/utilization?from='.$from)
            ->assertOk()
            ->assertJsonPath('data.0.loan_count', 1)
            ->assertJsonPath('period.from', $from);
    }

    public function test_problems_lists_assets_that_need_attention(): void
    {
        $admin = $this->member(Membership::ROLE_ADMIN);

        $bad = $this->asset(attributes: [
            'lifecycle_status' => Asset::LIFECYCLE_UNDER_MAINTENANCE,
            'condition' => Asset::CONDITION_POOR,
        ]);
        $this->asset(attributes: [
            'lifecycle_status' => Asset::LIFECYCLE_ACTIVE,
            'condition' => Asset::CONDITION_GOOD,
        ]);
        $unserviceable = $this->asset(attributes: ['condition' => Asset::CONDITION_UNSERVICEABLE]);

        MaintenanceTicket::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'asset_id' => $bad->getKey(),
            'status' => MaintenanceTicket::STATUS_IN_PROGRESS,
        ]);
        MaintenanceTicket::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'asset_id' => $bad->getKey(),
            'status' => MaintenanceTicket::STATUS_RESOLVED,
        ]);
        Issue::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'asset_id' => $bad->getKey(),
            'status' => Issue::STATUS_OPEN,
        ]);

        $this->actingAs($admin)
            ->getJson('/api/reports/problems')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.asset_id', $bad->getKey())
            ->assertJsonPath('data.0.open_tickets', 1)
            ->assertJsonPath('data.0.total_tickets', 2)
            ->assertJsonPath('data.0.open_issues', 1)
            ->assertJsonPath('data.1.asset_id', $unserviceable->getKey());
    }

    public function test_loan_report_counts_by_status_and_overdue(): void
    {
        $admin = $this->member(Membership::ROLE_ADMIN);
        $borrower = $this->member(Membership::ROLE_BORROWER);

        $asset = $this->asset();

        $this->borrowedLoan($borrower, $asset, Loan::STATUS_REQUESTED);

        $overdue = $this->borrowedLoan($borrower, $asset, Loan::STATUS_CHECKED_OUT);
        $overdue->starts_at = now()->subDays(10);
        $overdue->ends_at = now()->subDays(3);
        $overdue->due_at = now()->subDays(1);
        $overdue->save();

        $this->actingAs($admin)
            ->getJson('/api/reports/loans')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('by_status.requested', 1)
            ->assertJsonPath('by_status.checked_out', 1)
            ->assertJsonPath('by_status.returned', 0)
            ->assertJsonPath('overdue', 1);
    }

    public function test_export_utilization_returns_csv(): void
    {
        $admin = $this->member(Membership::ROLE_ADMIN);
        $borrower = $this->member(Membership::ROLE_BORROWER);

        $asset = $this->asset(attributes: ['name' => 'Bor Listrik', 'code' => 'AST-001']);
        $this->borrowedLoan($borrower, $asset);

        $response = $this->actingAs($admin)->get('/api/reports/export?report=utilization');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=utf-8');

        $csv = $response->streamedContent();

        $this->assertStringContainsString('asset_id', $csv);
        $this->assertStringContainsString('AST-001', $csv);
        $this->assertStringContainsString('Bor Listrik', $csv);
    }

    public function test_export_requires_a_known_report(): void
    {
        $admin = $this->member(Membership::ROLE_ADMIN);

        $this->actingAs($admin)
            ->getJson('/api/reports/export')
            ->assertStatus(422);

        $this->actingAs($admin)
            ->getJson('/api/reports/export?report=nope')
            ->assertStatus(422);
    }

    public function test_export_problems_and_loans_return_csv(): void
    {
        $admin = $this->member(Membership::ROLE_ADMIN);
        $borrower = $this->member(Membership::ROLE_BORROWER);

        $bad = $this->asset(attributes: [
            'condition' => Asset::CONDITION_UNSERVICEABLE,
            'code' => 'AST-999',
        ]);
        $this->borrowedLoan($borrower, $bad, Loan::STATUS_RETURNED);

        $problems = $this->actingAs($admin)->get('/api/reports/export?report=problems');
        $problems->assertOk();
        $this->assertStringContainsString('AST-999', $problems->streamedContent());

        $loans = $this->actingAs($admin)->get('/api/reports/export?report=loans');
        $loans->assertOk();
        $this->assertStringContainsString('returned', $loans->streamedContent());
    }

    public function test_report_filter_rejects_invalid_period(): void
    {
        $admin = $this->member(Membership::ROLE_ADMIN);

        $this->actingAs($admin)
            ->getJson('/api/reports/loans?from=2026-10-10&to=2026-10-01')
            ->assertStatus(422);
    }

    public function test_operator_and_borrower_cannot_access_reports(): void
    {
        $operator = $this->member(Membership::ROLE_OPERATOR);
        $borrower = $this->member(Membership::ROLE_BORROWER);

        foreach (['utilization', 'problems', 'loans'] as $report) {
            $this->actingAs($operator)->getJson('/api/reports/'.$report)->assertForbidden();
            $this->actingAs($borrower)->getJson('/api/reports/'.$report)->assertForbidden();
        }

        $this->actingAs($operator)
            ->getJson('/api/reports/export?report=utilization')
            ->assertForbidden();
    }

    public function test_utilization_is_isolated_per_organization(): void
    {
        $admin = $this->member(Membership::ROLE_ADMIN);
        $borrower = $this->member(Membership::ROLE_BORROWER);

        $mine = $this->asset(attributes: ['name' => 'Aset Sendiri']);
        $this->borrowedLoan($borrower, $mine, Loan::STATUS_RETURNED);

        $other = Organization::factory()->create();
        $otherBorrower = $this->userIn($other, Membership::ROLE_BORROWER);
        $otherAsset = $this->asset($other, ['name' => 'Aset Organisasi Lain']);

        $loan = Loan::factory()->state(['status' => Loan::STATUS_RETURNED])->create([
            'organization_id' => $other->getKey(),
            'borrower_id' => $otherBorrower->getKey(),
        ]);
        LoanItem::query()->create([
            'loan_id' => $loan->getKey(),
            'asset_id' => $otherAsset->getKey(),
        ]);

        $this->actingAs($admin)
            ->getJson('/api/reports/utilization')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Aset Sendiri');
    }
}
