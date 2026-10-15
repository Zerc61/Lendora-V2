<?php

namespace Tests\Feature\Performance;

use App\Models\Asset;
use App\Models\Issue;
use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\MaintenanceTicket;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\TenantTestCase;

/**
 * Query budget analitik. Angka dipilih mengikuti pola Testing Strategy G
 * (dashboard 15, laporan 10). Anggaran dalam round-trip, bukan milidetik.
 */
class AnalyticsQueryBudgetTest extends TenantTestCase
{
    /** @var list<string> */
    private array $queries = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->queries = [];

        DB::listen(function ($query): void {
            $this->queries[] = $query->sql;
        });
    }

    private function appQueries(): array
    {
        return array_values(array_filter(
            $this->queries,
            fn (string $sql) => ! str_contains($sql, '"sessions"')
                && ! str_contains($sql, '"cache"'),
        ));
    }

    private function assertWithinBudget(string $label, int $budget): void
    {
        $queries = $this->appQueries();
        $count = count($queries);

        $this->assertLessThanOrEqual(
            $budget,
            $count,
            sprintf(
                "%s mengirim %d query (anggaran %d).\nQuery yang jalan:\n  %s",
                $label,
                $count,
                $budget,
                implode("\n  ", array_map('trim', $queries)),
            ),
        );
    }

    private function seedData(User $borrower, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $asset = $this->asset(attributes: [
                'lifecycle_status' => Asset::LIFECYCLE_UNDER_MAINTENANCE,
                'condition' => Asset::CONDITION_POOR,
            ]);

            Issue::factory()->create([
                'organization_id' => $this->organization->getKey(),
                'asset_id' => $asset->getKey(),
                'status' => Issue::STATUS_OPEN,
                'reported_by' => $borrower->getKey(),
            ]);

            MaintenanceTicket::factory()->create([
                'organization_id' => $this->organization->getKey(),
                'asset_id' => $asset->getKey(),
                'status' => MaintenanceTicket::STATUS_IN_PROGRESS,
            ]);

            $loan = Loan::factory()->state(['status' => Loan::STATUS_RETURNED])->create([
                'organization_id' => $this->organization->getKey(),
                'borrower_id' => $borrower->getKey(),
            ]);

            LoanItem::query()->create([
                'loan_id' => $loan->getKey(),
                'asset_id' => $asset->getKey(),
            ]);
        }
    }

    public function test_admin_overview_stays_within_query_budget(): void
    {
        $admin = $this->member(Membership::ROLE_ADMIN);
        $borrower = $this->member(Membership::ROLE_BORROWER);
        $this->seedData($borrower, 5);

        $this->queries = [];

        $this->actingAs($admin)
            ->getJson('/api/admin/overview')
            ->assertOk();

        $this->assertWithinBudget('Ringkasan admin', 15);
    }

    public function test_utilization_stays_within_query_budget(): void
    {
        $admin = $this->member(Membership::ROLE_ADMIN);
        $borrower = $this->member(Membership::ROLE_BORROWER);
        $this->seedData($borrower, 5);

        $this->queries = [];

        $this->actingAs($admin)
            ->getJson('/api/reports/utilization')
            ->assertOk();

        $this->assertWithinBudget('Laporan utilisasi', 10);
    }

    public function test_problems_stays_within_query_budget(): void
    {
        $admin = $this->member(Membership::ROLE_ADMIN);
        $borrower = $this->member(Membership::ROLE_BORROWER);
        $this->seedData($borrower, 5);

        $this->queries = [];

        $this->actingAs($admin)
            ->getJson('/api/reports/problems')
            ->assertOk();

        $this->assertWithinBudget('Laporan kondisi', 10);
    }

    public function test_loan_report_stays_within_query_budget(): void
    {
        $admin = $this->member(Membership::ROLE_ADMIN);
        $borrower = $this->member(Membership::ROLE_BORROWER);
        $this->seedData($borrower, 5);

        $this->queries = [];

        $this->actingAs($admin)
            ->getJson('/api/reports/loans')
            ->assertOk();

        $this->assertWithinBudget('Laporan peminjaman', 10);
    }

    public function test_exports_stay_within_query_budget(): void
    {
        $admin = $this->member(Membership::ROLE_ADMIN);
        $borrower = $this->member(Membership::ROLE_BORROWER);
        $this->seedData($borrower, 5);

        foreach (['utilization', 'problems', 'loans'] as $report) {
            $this->queries = [];

            $this->actingAs($admin)
                ->get('/api/reports/export?report='.$report)
                ->assertOk();

            $this->assertWithinBudget('Ekspor '.$report, 10);
        }
    }
}
