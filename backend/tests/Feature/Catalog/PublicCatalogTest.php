<?php

namespace Tests\Feature\Catalog;

use App\Models\Asset;
use App\Models\Loan;
use App\Models\LoanReservation;
use App\Models\Organization;
use Illuminate\Support\Carbon;
use Tests\Support\TenantTestCase;

/**
 * Katalog publik. Hanya provider aktif dan terverifikasi yang tampil, hanya field
 * aman yang diekspor, dan setiap kueri terbatas pada provider yang diminta.
 */
class PublicCatalogTest extends TenantTestCase
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    private function provider(array $attributes = []): Organization
    {
        return Organization::factory()->verified()->create($attributes);
    }

    /**
     * Reservasi yang memblokir periode tanpa lewat service, supaya periode bisa
     * diatur presisi.
     */
    private function blockingReservation(Asset $asset, Carbon $start, Carbon $end): void
    {
        $loan = Loan::factory()->create([
            'organization_id' => $asset->organization_id,
            'status' => Loan::STATUS_APPROVED,
            'starts_at' => $start,
            'ends_at' => $end,
            'due_at' => $end,
            'approved_at' => now(),
        ]);

        LoanReservation::factory()->create([
            'organization_id' => $asset->organization_id,
            'loan_id' => $loan->getKey(),
            'asset_id' => $asset->getKey(),
            'status' => Loan::STATUS_APPROVED,
            'starts_at' => $start,
            'ends_at' => $end,
        ]);
    }

    public function test_lists_only_active_verified_providers(): void
    {
        $visible = $this->provider();
        Organization::factory()->unverified()->create();
        Organization::factory()->verified()->inactive()->create();

        $this->getJson('/api/public/providers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', $visible->slug);
    }

    public function test_unverified_provider_returns_404(): void
    {
        $provider = Organization::factory()->unverified()->create();

        $this->getJson('/api/public/providers/'.$provider->slug)->assertNotFound();
    }

    public function test_inactive_provider_returns_404(): void
    {
        $provider = Organization::factory()->verified()->inactive()->create();

        $this->getJson('/api/public/providers/'.$provider->slug)->assertNotFound();
    }

    public function test_provider_detail_exposes_only_public_fields(): void
    {
        $provider = $this->provider([
            'description' => 'Studio foto dan sewa kamera.',
            'contact_email' => 'halo@studio.test',
            'contact_phone' => '08123456789',
            'address' => 'Jalan Merdeka 1',
            'city' => 'Bandung',
        ]);

        $this->getJson('/api/public/providers/'.$provider->slug)
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'id' => $provider->id,
                    'name' => $provider->name,
                    'slug' => $provider->slug,
                    'description' => 'Studio foto dan sewa kamera.',
                    'contact_email' => 'halo@studio.test',
                    'contact_phone' => '08123456789',
                    'address' => 'Jalan Merdeka 1',
                    'city' => 'Bandung',
                ],
            ]);
    }

    public function test_equipment_list_shows_only_active_assets_of_the_requested_provider(): void
    {
        $provider = $this->provider();
        $active = $this->asset($provider);
        $this->asset($provider, ['lifecycle_status' => Asset::LIFECYCLE_RETIRED]);
        $this->asset($this->provider());

        $this->getJson('/api/public/providers/'.$provider->slug.'/equipment')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $active->id);
    }

    public function test_equipment_detail_never_exposes_internal_fields(): void
    {
        $provider = $this->provider();
        $asset = $this->asset($provider, [
            'serial_number' => 'SN-RAHASIA',
            'purchase_price' => 5_000_000,
            'notes' => 'Catatan internal.',
        ]);

        $this->getJson('/api/public/providers/'.$provider->slug.'/equipment/'.$asset->getKey())
            ->assertOk()
            ->assertJsonPath('data.id', $asset->getKey())
            ->assertJsonPath('data.name', $asset->name)
            ->assertJsonMissingPath('data.serial_number')
            ->assertJsonMissingPath('data.purchase_price')
            ->assertJsonMissingPath('data.condition')
            ->assertJsonMissingPath('data.lifecycle_status')
            ->assertJsonMissingPath('data.notes');
    }

    public function test_retired_asset_detail_returns_404(): void
    {
        $provider = $this->provider();
        $asset = $this->asset($provider, ['lifecycle_status' => Asset::LIFECYCLE_RETIRED]);

        $this->getJson('/api/public/providers/'.$provider->slug.'/equipment/'.$asset->getKey())
            ->assertNotFound();
    }

    public function test_another_providers_equipment_returns_404(): void
    {
        $provider = $this->provider();
        $foreign = $this->asset($this->provider());

        $this->getJson('/api/public/providers/'.$provider->slug.'/equipment/'.$foreign->getKey())
            ->assertNotFound();
    }

    public function test_free_period_is_requestable(): void
    {
        $provider = $this->provider();
        $asset = $this->asset($provider);

        $start = now()->addDay()->startOfHour();
        $end = $start->copy()->addHours(6);

        $this->getJson($this->availabilityUrl($provider, $asset, $start, $end))
            ->assertOk()
            ->assertJsonPath('data.requestable', true)
            ->assertJsonPath('data.assets.0.available', true);
    }

    public function test_reserved_period_is_not_requestable(): void
    {
        $provider = $this->provider();
        $asset = $this->asset($provider);

        $start = now()->addDay()->startOfHour();
        $end = $start->copy()->addDays(2);

        $this->blockingReservation($asset, $start, $end);

        $this->getJson($this->availabilityUrl($provider, $asset, $start->copy()->addHour(), $end))
            ->assertOk()
            ->assertJsonPath('data.requestable', false)
            ->assertJsonPath('data.assets.0.available', false);
    }

    public function test_availability_requires_a_period(): void
    {
        $provider = $this->provider();
        $asset = $this->asset($provider);

        $this->getJson('/api/public/providers/'.$provider->slug.'/equipment/'.$asset->getKey().'/availability')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_at', 'ends_at']);
    }

    private function availabilityUrl(Organization $provider, Asset $asset, Carbon $start, Carbon $end): string
    {
        return '/api/public/providers/'.$provider->slug.'/equipment/'.$asset->getKey().'/availability?'
            .http_build_query([
                'starts_at' => $start->toIso8601String(),
                'ends_at' => $end->toIso8601String(),
            ]);
    }
}
