<?php

namespace Tests\Feature\Lending;

use App\Models\Asset;
use App\Models\Loan;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\LoanApproved;
use App\Notifications\LoanCheckedOut;
use App\Notifications\LoanRequested;
use Tests\Support\TenantTestCase;

/**
 * Alur ujung ke ujung booking customer tanpa membership (Fase 3).
 *
 * Customer memesan lewat katalog publik, lalu staf provider memprosesnya dengan
 * alur operasional yang sudah ada: setujui, serah terima, pengembalian,
 * inspeksi. Belum ada pembayaran pada tahap ini.
 */
class CustomerLifecycleTest extends TenantTestCase
{
    private function provider(): Organization
    {
        return Organization::factory()->verified()->create();
    }

    private function book(Organization $provider, Asset $asset, User $customer): int
    {
        $start = now()->addDays(2)->startOfHour();

        return $this->actingAs($customer)
            ->postJson('/api/providers/'.$provider->slug.'/bookings', [
                'starts_at' => $start->toIso8601String(),
                'ends_at' => $start->copy()->addHours(8)->toIso8601String(),
                'assets' => [$asset->getKey()],
            ])
            ->assertCreated()
            ->json('data.id');
    }

    /**
     * @return array{items: list<array{asset_id: int, condition: string}>}
     */
    private function items(Asset $asset, string $condition = Asset::CONDITION_GOOD): array
    {
        return ['items' => [['asset_id' => $asset->getKey(), 'condition' => $condition]]];
    }

    public function test_provider_processes_a_customer_booking_end_to_end(): void
    {
        $provider = $this->provider();
        $asset = $this->asset($provider, ['daily_rate' => '150000.00']);
        $customer = User::factory()->create();
        $operator = $this->userIn($provider, Membership::ROLE_OPERATOR);

        $loanId = $this->book($provider, $asset, $customer);

        $this->actingAs($operator)
            ->postJson('/api/loans/'.$loanId.'/approve')
            ->assertOk()
            ->assertJsonPath('data.status', Loan::STATUS_APPROVED)
            // Petugas melihat jumlah yang dibekukan saat customer memesan.
            ->assertJsonPath('data.subtotal', '150000.00');

        $this->assertDatabaseHas('loan_reservations', [
            'loan_id' => $loanId,
            'asset_id' => $asset->getKey(),
            'status' => Loan::STATUS_APPROVED,
        ]);

        $this->actingAs($operator)
            ->postJson('/api/loans/'.$loanId.'/checkout', $this->items($asset))
            ->assertOk()
            ->assertJsonPath('data.status', Loan::STATUS_CHECKED_OUT);

        $this->actingAs($operator)
            ->postJson('/api/loans/'.$loanId.'/return', $this->items($asset))
            ->assertOk()
            ->assertJsonPath('data.status', Loan::STATUS_RETURNED);

        // Gerbang inspeksi: aset belum kembali tersedia sebelum diperiksa.
        $this->assertSame(Asset::LIFECYCLE_AWAITING_INSPECTION, $asset->fresh()->lifecycle_status);

        $this->actingAs($operator)
            ->postJson('/api/assets/'.$asset->getKey().'/inspection', ['notes' => 'Layak dipakai lagi'])
            ->assertOk();

        $this->assertSame(Asset::LIFECYCLE_ACTIVE, $asset->fresh()->lifecycle_status);

        // Customer melihat hasil akhirnya di riwayatnya sendiri.
        $this->actingAs($customer)
            ->getJson('/api/bookings')
            ->assertOk()
            ->assertJsonPath('data.0.id', $loanId)
            ->assertJsonPath('data.0.status', Loan::STATUS_RETURNED);
    }

    public function test_customer_without_membership_still_receives_notifications(): void
    {
        $provider = $this->provider();
        $asset = $this->asset($provider);
        $customer = User::factory()->create();
        $operator = $this->userIn($provider, Membership::ROLE_OPERATOR);

        $loanId = $this->book($provider, $asset, $customer);

        // Pengelola tetap menerima pengajuan, dan tautannya ke /app.
        $requested = $operator->notifications()->where('type', LoanRequested::class)->first();

        $this->assertNotNull($requested);
        $this->assertSame('/app/loans/'.$loanId, $requested->data['action_url']);

        $this->actingAs($operator)->postJson('/api/loans/'.$loanId.'/approve')->assertOk();

        $approved = $customer->notifications()->where('type', LoanApproved::class)->first();

        $this->assertNotNull(
            $approved,
            'Customer tanpa membership tetap harus menerima notifikasi persetujuan.',
        );

        // /app memerlukan membership, jadi tautannya ke halaman booking customer.
        $this->assertSame('/booking', $approved->data['action_url']);

        $this->actingAs($operator)
            ->postJson('/api/loans/'.$loanId.'/checkout', $this->items($asset))
            ->assertOk();

        $this->assertTrue($customer->notifications()->where('type', LoanCheckedOut::class)->exists());
    }

    public function test_another_provider_cannot_operate_on_a_customer_booking(): void
    {
        $provider = $this->provider();
        $other = $this->provider();
        $asset = $this->asset($provider);
        $customer = User::factory()->create();
        $foreignOperator = $this->userIn($other, Membership::ROLE_OPERATOR);
        $foreignAsset = $this->asset($other);

        $loanId = $this->book($provider, $asset, $customer);

        // Payload memakai aset sendiri supaya validasi lolos; loan milik provider
        // lain tidak terlihat (global scope -> 404).
        $this->actingAs($foreignOperator)->postJson('/api/loans/'.$loanId.'/approve')->assertNotFound();
        $this->actingAs($foreignOperator)
            ->postJson('/api/loans/'.$loanId.'/checkout', $this->items($foreignAsset))
            ->assertNotFound();
        $this->actingAs($foreignOperator)->getJson('/api/loans/'.$loanId)->assertNotFound();

        $this->assertDatabaseHas('loans', ['id' => $loanId, 'status' => Loan::STATUS_REQUESTED]);
    }

    public function test_customer_cannot_operate_the_provider_queue(): void
    {
        $provider = $this->provider();
        $asset = $this->asset($provider);
        $customer = User::factory()->create();

        $loanId = $this->book($provider, $asset, $customer);

        // Tanpa membership, konteks organisasi tidak terbentuk sama sekali (403).
        $this->actingAs($customer)->postJson('/api/loans/'.$loanId.'/approve')->assertForbidden();
        $this->actingAs($customer)
            ->postJson('/api/loans/'.$loanId.'/checkout', $this->items($asset))
            ->assertForbidden();
        $this->actingAs($customer)->getJson('/api/loans')->assertForbidden();
    }
}
