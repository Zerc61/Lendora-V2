<?php

namespace Tests\Feature\Performance;

use App\Models\Asset;
use App\Models\Membership;
use Illuminate\Support\Facades\DB;
use Tests\Support\TenantTestCase;

/**
 * Query budget katalog. Anggaran dinyatakan dalam JUMLAH round-trip ke database,
 * bukan milidetik, karena yang bisa diregresikan diam-diam adalah pola N+1.
 * Angka mengikuti Testing Strategy G: daftar aset 15, detail aset 10.
 */
class AssetQueryBudgetTest extends TenantTestCase
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

    /** Buang query infrastruktur (session) supaya yang dihitung hanya query aplikasi. */
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

    public function test_asset_index_stays_within_query_budget(): void
    {
        $category = $this->category();
        $location = $this->location();

        // Cukup banyak baris supaya N+1 pada relasi muncul di angkanya.
        Asset::factory()->count(25)->create([
            'organization_id' => $this->organization->getKey(),
            'category_id' => $category->getKey(),
            'location_id' => $location->getKey(),
        ]);

        // User disiapkan sebelum hitungan dimulai agar insert fixture tidak terhitung.
        $admin = $this->member(Membership::ROLE_ADMIN);

        $this->queries = [];

        $this->actingAs($admin)
            ->getJson('/api/assets')
            ->assertOk()
            ->assertJsonCount(24, 'data');

        $this->assertWithinBudget('Daftar aset', 15);
    }

    public function test_asset_detail_stays_within_query_budget(): void
    {
        $category = $this->category();

        Asset::factory()->count(5)->create([
            'organization_id' => $this->organization->getKey(),
            'category_id' => $category->getKey(),
        ]);

        $asset = Asset::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'category_id' => $category->getKey(),
        ]);

        $admin = $this->member(Membership::ROLE_ADMIN);

        $this->queries = [];

        $this->actingAs($admin)
            ->getJson('/api/assets/'.$asset->getKey())
            ->assertOk();

        $this->assertWithinBudget('Detail aset', 10);
    }
}
