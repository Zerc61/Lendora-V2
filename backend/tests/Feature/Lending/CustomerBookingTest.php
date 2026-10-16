<?php

namespace Tests\Feature\Lending;

use App\Models\Asset;
use App\Models\Loan;
use App\Models\LoanReservation;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\LoanCancelled;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\TenantTestCase;

/**
 * Pemesanan oleh customer (Fase 2).
 *
 * Customer cukup punya akun: tanpa membership di organisasi provider pun
 * pemesanan harus berhasil. Harga selalu dihitung server dan dibekukan sebagai
 * snapshot pada loan.
 */
class CustomerBookingTest extends TenantTestCase
{
    private function provider(): Organization
    {
        return Organization::factory()->verified()->create();
    }

    /**
     * @return array{starts_at: string, ends_at: string}
     */
    private function period(int $hours = 8, int $startInDays = 2): array
    {
        $start = now()->addDays($startInDays)->startOfHour();

        return [
            'starts_at' => $start->toIso8601String(),
            'ends_at' => $start->copy()->addHours($hours)->toIso8601String(),
        ];
    }

    /**
     * Loan disetujui plus reservasinya, dibuat langsung supaya periode terkunci
     * tanpa lewat alur persetujuan.
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

    public function test_customer_without_membership_can_book_and_price_is_snapshotted(): void
    {
        $provider = $this->provider();
        $asset = $this->asset($provider, ['daily_rate' => '150000.00']);
        $customer = User::factory()->create();

        $response = $this->actingAs($customer)->postJson(
            '/api/providers/'.$provider->slug.'/bookings',
            $this->period() + ['assets' => [$asset->getKey()]],
        );

        $response->assertCreated()
            ->assertJsonPath('data.status', Loan::STATUS_REQUESTED)
            ->assertJsonPath('data.days', 1)
            ->assertJsonPath('data.subtotal', '150000.00')
            ->assertJsonPath('data.currency', 'IDR')
            ->assertJsonPath('data.provider.slug', $provider->slug)
            ->assertJsonPath('data.items.0.name', $asset->name)
            ->assertJsonPath('data.items.0.amount', '150000.00');

        $this->assertDatabaseHas('loans', [
            'organization_id' => $provider->getKey(),
            'borrower_id' => $customer->getKey(),
            'status' => Loan::STATUS_REQUESTED,
            'days' => 1,
            'subtotal' => '150000.00',
            'rate_snapshot' => '150000.00',
            'currency' => 'IDR',
        ]);

        $this->assertDatabaseHas('audit_events', ['action' => 'loan.requested']);
    }

    public function test_booking_is_snapshotted_but_not_yet_reserved(): void
    {
        $provider = $this->provider();
        $asset = $this->asset($provider);
        $customer = User::factory()->create();

        $this->actingAs($customer)
            ->postJson('/api/providers/'.$provider->slug.'/bookings', $this->period() + ['assets' => [$asset->getKey()]])
            ->assertCreated();

        // Tanpa persetujuan provider, jadwal belum terkunci.
        $this->assertSame(0, DB::table('loan_reservations')->count());
    }

    public function test_two_customers_may_request_the_same_period_until_one_is_approved(): void
    {
        $provider = $this->provider();
        $asset = $this->asset($provider);
        $period = $this->period();

        $this->actingAs(User::factory()->create())
            ->postJson('/api/providers/'.$provider->slug.'/bookings', $period + ['assets' => [$asset->getKey()]])
            ->assertCreated();

        $this->actingAs(User::factory()->create())
            ->postJson('/api/providers/'.$provider->slug.'/bookings', $period + ['assets' => [$asset->getKey()]])
            ->assertCreated();

        $this->assertSame(2, DB::table('loans')->where('status', Loan::STATUS_REQUESTED)->count());
        $this->assertSame(0, DB::table('loan_reservations')->count());
    }

    public function test_client_supplied_price_is_rejected(): void
    {
        $provider = $this->provider();
        $asset = $this->asset($provider);

        $this->actingAs(User::factory()->create())
            ->postJson('/api/providers/'.$provider->slug.'/bookings', $this->period() + [
                'assets' => [$asset->getKey()],
                'subtotal' => 1,
                'days' => 1,
                'status' => Loan::STATUS_APPROVED,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['subtotal', 'days', 'status']);

        $this->assertDatabaseCount('loans', 0);
    }

    public function test_price_does_not_change_when_the_rate_changes_later(): void
    {
        $provider = $this->provider();
        $asset = $this->asset($provider, ['daily_rate' => '150000.00']);
        $customer = User::factory()->create();

        $this->actingAs($customer)
            ->postJson('/api/providers/'.$provider->slug.'/bookings', $this->period() + ['assets' => [$asset->getKey()]])
            ->assertCreated();

        $asset->update(['daily_rate' => '999000.00']);

        $this->assertDatabaseHas('loans', ['borrower_id' => $customer->getKey(), 'subtotal' => '150000.00']);

        $this->actingAs($customer)
            ->getJson('/api/bookings')
            ->assertOk()
            ->assertJsonPath('data.0.subtotal', '150000.00');
    }

    public function test_min_days_raises_the_billed_days(): void
    {
        $provider = $this->provider();
        $asset = $this->asset($provider, ['daily_rate' => '100000.00', 'min_days' => 3]);

        $this->actingAs(User::factory()->create())
            ->postJson(
                '/api/providers/'.$provider->slug.'/bookings',
                $this->period(hours: 8) + ['assets' => [$asset->getKey()]],
            )
            ->assertCreated()
            ->assertJsonPath('data.days', 3)
            ->assertJsonPath('data.subtotal', '300000.00');
    }

    public function test_multi_day_period_is_rounded_up(): void
    {
        $provider = $this->provider();
        $asset = $this->asset($provider, ['daily_rate' => '200000.00']);

        $this->actingAs(User::factory()->create())
            ->postJson(
                '/api/providers/'.$provider->slug.'/bookings',
                $this->period(hours: 26) + ['assets' => [$asset->getKey()]],
            )
            ->assertCreated()
            ->assertJsonPath('data.days', 2)
            ->assertJsonPath('data.subtotal', '400000.00');
    }

    public function test_equipment_without_a_rate_cannot_be_booked(): void
    {
        $provider = $this->provider();
        $asset = $this->asset($provider, ['daily_rate' => null]);

        $this->actingAs(User::factory()->create())
            ->postJson('/api/providers/'.$provider->slug.'/bookings', $this->period() + ['assets' => [$asset->getKey()]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['assets.'.$asset->code]);

        $this->assertDatabaseCount('loans', 0);
    }

    public function test_reserved_period_is_rejected(): void
    {
        $provider = $this->provider();
        $asset = $this->asset($provider);
        $period = $this->period();

        $this->blockingReservation(
            $asset,
            Carbon::parse($period['starts_at']),
            Carbon::parse($period['ends_at']),
        );

        $this->actingAs(User::factory()->create())
            ->postJson('/api/providers/'.$provider->slug.'/bookings', $period + ['assets' => [$asset->getKey()]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['assets.'.$asset->code]);

        // Hanya loan yang memblokir tadi; pemesanan customer tidak tersimpan.
        $this->assertSame(0, DB::table('loans')->where('status', Loan::STATUS_REQUESTED)->count());
        $this->assertSame(1, DB::table('loans')->count());
    }

    public function test_booking_is_auto_approved_when_provider_does_not_require_approval(): void
    {
        $provider = $this->provider();
        $asset = $this->asset($provider, ['daily_rate' => '120000.00']);
        $customer = User::factory()->create();

        $this->policy(['require_approval' => false], $provider);

        $this->actingAs($customer)
            ->postJson('/api/providers/'.$provider->slug.'/bookings', $this->period() + ['assets' => [$asset->getKey()]])
            ->assertCreated()
            ->assertJsonPath('data.status', Loan::STATUS_APPROVED);

        $loan = Loan::query()->withoutOrganizationScope()->where('borrower_id', $customer->getKey())->firstOrFail();

        $this->assertNull($loan->approved_by);
        $this->assertNotNull($loan->approved_at);
        $this->assertNotNull($loan->due_at);
        $this->assertSame(1, DB::table('loan_reservations')->where('loan_id', $loan->getKey())->count());
        $this->assertDatabaseHas('audit_events', ['action' => 'loan.approved', 'subject_id' => $loan->getKey()]);
    }

    public function test_unverified_provider_cannot_be_booked(): void
    {
        $provider = Organization::factory()->create();
        $asset = $this->asset($provider);

        $this->actingAs(User::factory()->create())
            ->postJson('/api/providers/'.$provider->slug.'/bookings', $this->period() + ['assets' => [$asset->getKey()]])
            ->assertNotFound();

        $this->assertDatabaseCount('loans', 0);
    }

    public function test_booking_requires_authentication(): void
    {
        $provider = $this->provider();
        $asset = $this->asset($provider);

        $this->postJson('/api/providers/'.$provider->slug.'/bookings', $this->period() + ['assets' => [$asset->getKey()]])
            ->assertUnauthorized();

        $this->assertDatabaseCount('loans', 0);
    }

    public function test_history_shows_only_own_bookings_across_providers(): void
    {
        $first = $this->provider();
        $second = $this->provider();
        $customer = User::factory()->create();

        $firstAsset = $this->asset($first, ['daily_rate' => '100000.00']);
        $secondAsset = $this->asset($second, ['daily_rate' => '250000.00']);

        $this->actingAs($customer)
            ->postJson('/api/providers/'.$first->slug.'/bookings', $this->period() + ['assets' => [$firstAsset->getKey()]])
            ->assertCreated();

        $this->actingAs($customer)
            ->postJson('/api/providers/'.$second->slug.'/bookings', $this->period() + ['assets' => [$secondAsset->getKey()]])
            ->assertCreated();

        $foreign = $this->asset($first);
        $this->actingAs(User::factory()->create())
            ->postJson('/api/providers/'.$first->slug.'/bookings', $this->period() + ['assets' => [$foreign->getKey()]])
            ->assertCreated();

        $response = $this->actingAs($customer)->getJson('/api/bookings');

        $response->assertOk()->assertJsonCount(2, 'data');

        $slugs = array_column($response->json('data'), 'provider');
        $this->assertEqualsCanonicalizing(
            [$first->slug, $second->slug],
            array_map(fn (array $provider): string => $provider['slug'], $slugs),
        );

        // Snapshot tidak pernah membawa kolom internal aset.
        $response
            ->assertJsonMissingPath('data.0.items.0.serial_number')
            ->assertJsonMissingPath('data.0.items.0.purchase_price')
            ->assertJsonMissingPath('data.0.items.0.condition');
    }

    public function test_customer_can_cancel_before_approval(): void
    {
        $provider = $this->provider();
        $asset = $this->asset($provider, ['daily_rate' => '150000.00']);
        $customer = User::factory()->create();
        $operator = $this->userIn($provider, Membership::ROLE_OPERATOR);

        $loanId = $this->actingAs($customer)
            ->postJson('/api/providers/'.$provider->slug.'/bookings', $this->period() + ['assets' => [$asset->getKey()]])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($customer)
            ->postJson('/api/providers/'.$provider->slug.'/bookings/'.$loanId.'/cancel', ['reason' => 'Salah tanggal'])
            ->assertOk()
            ->assertJsonPath('data.status', Loan::STATUS_CANCELLED);

        $this->assertDatabaseHas('loans', ['id' => $loanId, 'status' => Loan::STATUS_CANCELLED]);
        $this->assertSame(0, DB::table('loan_reservations')->count());
        $this->assertDatabaseHas('audit_events', ['action' => 'loan.cancelled', 'subject_id' => $loanId]);

        // Pihak yang tidak membatalkan tetap diberi tahu.
        $this->assertTrue($operator->notifications()->where('type', LoanCancelled::class)->exists());
    }

    public function test_customer_cannot_cancel_after_approval(): void
    {
        $provider = $this->provider();
        $asset = $this->asset($provider);
        $customer = User::factory()->create();
        $operator = $this->userIn($provider, Membership::ROLE_OPERATOR);

        $loanId = $this->actingAs($customer)
            ->postJson('/api/providers/'.$provider->slug.'/bookings', $this->period() + ['assets' => [$asset->getKey()]])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($operator)->postJson('/api/loans/'.$loanId.'/approve')->assertOk();

        $this->actingAs($customer)
            ->postJson('/api/providers/'.$provider->slug.'/bookings/'.$loanId.'/cancel')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->assertDatabaseHas('loans', ['id' => $loanId, 'status' => Loan::STATUS_APPROVED]);
    }

    public function test_customer_cannot_cancel_someone_elses_booking(): void
    {
        $provider = $this->provider();
        $asset = $this->asset($provider);

        $loanId = $this->actingAs(User::factory()->create())
            ->postJson('/api/providers/'.$provider->slug.'/bookings', $this->period() + ['assets' => [$asset->getKey()]])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs(User::factory()->create())
            ->postJson('/api/providers/'.$provider->slug.'/bookings/'.$loanId.'/cancel')
            ->assertForbidden();

        $this->assertDatabaseHas('loans', ['id' => $loanId, 'status' => Loan::STATUS_REQUESTED]);
    }

    public function test_cancelling_a_booking_requires_authentication(): void
    {
        $provider = $this->provider();
        $asset = $this->asset($provider);

        $loanId = $this->actingAs(User::factory()->create())
            ->postJson('/api/providers/'.$provider->slug.'/bookings', $this->period() + ['assets' => [$asset->getKey()]])
            ->assertCreated()
            ->json('data.id');

        $this->app['auth']->forgetGuards();

        $this->postJson('/api/providers/'.$provider->slug.'/bookings/'.$loanId.'/cancel')->assertUnauthorized();

        $this->assertDatabaseHas('loans', ['id' => $loanId, 'status' => Loan::STATUS_REQUESTED]);
    }
}
