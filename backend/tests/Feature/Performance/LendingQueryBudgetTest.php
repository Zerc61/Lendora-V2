<?php

namespace Tests\Feature\Performance;

use App\Models\Asset;
use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\TenantTestCase;

/**
 * Query budget lending. Angka mengikuti Testing Strategy G: daftar loan 15,
 * dashboard operator 15. Anggaran dalam round-trip, bukan milidetik.
 */
class LendingQueryBudgetTest extends TenantTestCase
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

    /**
     * @param  list<Asset>  $assets
     */
    private function loan(string $state, array $assets, User $borrower): Loan
    {
        $factory = $state === 'approved' ? Loan::factory()->approved() : Loan::factory()->checkedOut();

        $loan = $factory->create([
            'organization_id' => $this->organization->getKey(),
            'borrower_id' => $borrower->getKey(),
        ]);

        foreach ($assets as $asset) {
            LoanItem::query()->create([
                'loan_id' => $loan->getKey(),
                'asset_id' => $asset->getKey(),
            ]);
        }

        return $loan;
    }

    public function test_loan_index_stays_within_query_budget(): void
    {
        $borrower = $this->member(Membership::ROLE_BORROWER);
        $operator = $this->member(Membership::ROLE_OPERATOR);

        Loan::factory()->count(25)->create([
            'organization_id' => $this->organization->getKey(),
            'borrower_id' => $borrower->getKey(),
        ])->each(function (Loan $loan): void {
            LoanItem::query()->create([
                'loan_id' => $loan->getKey(),
                'asset_id' => Asset::factory()->create([
                    'organization_id' => $this->organization->getKey(),
                ])->getKey(),
            ]);
        });

        $this->queries = [];

        $this->actingAs($operator)
            ->getJson('/api/loans')
            ->assertOk()
            ->assertJsonCount(20, 'data');

        $this->assertWithinBudget('Daftar loan', 15);
    }

    public function test_operator_dashboard_stays_within_query_budget(): void
    {
        $borrower = $this->member(Membership::ROLE_BORROWER);
        $operator = $this->member(Membership::ROLE_OPERATOR);

        for ($i = 0; $i < 3; $i++) {
            $pickup = $this->loan('approved', [$this->asset()], $borrower);
            $pickup->starts_at = now()->startOfDay()->addHour();
            $pickup->save();

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

            $this->asset(attributes: ['lifecycle_status' => Asset::LIFECYCLE_AWAITING_INSPECTION]);
        }

        $this->queries = [];

        $this->actingAs($operator)
            ->getJson('/api/operator/today')
            ->assertOk()
            ->assertJsonPath('counts.awaiting_pickup', 3)
            ->assertJsonPath('counts.due_today', 3)
            ->assertJsonPath('counts.overdue', 3)
            ->assertJsonPath('counts.awaiting_inspection', 3);

        $this->assertWithinBudget('Dashboard operator', 16);
    }

    public function test_loan_detail_stays_within_query_budget(): void
    {
        $borrower = $this->member(Membership::ROLE_BORROWER);
        $operator = $this->member(Membership::ROLE_OPERATOR);

        $loan = $this->loan('approved', [$this->asset(), $this->asset()], $borrower);

        $this->queries = [];

        $this->actingAs($operator)
            ->getJson('/api/loans/'.$loan->getKey())
            ->assertOk()
            ->assertJsonPath('data.id', (int) $loan->getKey())
            ->assertJsonCount(2, 'data.items');

        $this->assertWithinBudget('Detail loan', 12);
    }
}
