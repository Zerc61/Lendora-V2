<?php

namespace Tests\Feature\Payments;

use App\Models\Asset;
use App\Models\Loan;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\Support\TenantTestCase;

/**
 * Pembayaran sewa (Tahap 4).
 *
 * Hanya saldo dompet: penyewa mengisi dompet dulu, baru membayar. Dua gerbang
 * sebelum serah terima — penyewa membayar, lalu admin memverifikasi dananya.
 */
class PaymentTest extends TenantTestCase
{
    private function provider(): Organization
    {
        return Organization::factory()->verified()->create();
    }

    /**
     * @return array{starts_at: string, ends_at: string}
     */
    private function period(): array
    {
        $start = now()->addDays(2)->startOfHour();

        return [
            'starts_at' => $start->toIso8601String(),
            'ends_at' => $start->copy()->addHours(8)->toIso8601String(),
        ];
    }

    /**
     * Booking customer yang sudah disetujui operator, plus aktornya.
     *
     * @param  int  $balance  saldo dompet customer, 0 berarti belum diisi.
     * @return array{loan: Loan, customer: User, operator: User, asset: Asset, provider: Organization}
     */
    private function approvedBooking(string $rate = '150000.00', int $balance = 200000): array
    {
        $provider = $this->provider();
        $asset = $this->asset($provider, ['daily_rate' => $rate]);
        $customer = User::factory()->create(['wallet_balance' => $balance]);
        $operator = $this->userIn($provider, Membership::ROLE_OPERATOR);

        $loanId = $this->actingAs($customer)
            ->postJson('/api/providers/'.$provider->slug.'/bookings', $this->period() + ['assets' => [$asset->getKey()]])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($operator)->postJson('/api/loans/'.$loanId.'/approve')->assertOk();

        $loan = Loan::query()
            ->withoutOrganizationScope()
            ->with('latestPayment')
            ->findOrFail($loanId);

        return compact('loan', 'customer', 'operator', 'asset', 'provider');
    }

    public function test_client_supplied_amount_is_ignored(): void
    {
        Http::fake();
        ['loan' => $loan, 'customer' => $customer, 'provider' => $provider] = $this->approvedBooking();

        $this->actingAs($customer)
            ->postJson(
                '/api/providers/'.$provider->slug.'/bookings/'.$loan->getKey().'/payment',
                ['amount' => 1, 'status' => Payment::STATUS_PAID, 'payment_method' => Payment::METHOD_WALLET],
            )
            ->assertCreated()
            ->assertJsonPath('data.amount', '150000.00')
            ->assertJsonPath('data.status', Payment::STATUS_PAID);

        $this->assertSame('50000.00', $customer->fresh()->wallet_balance);
    }

    public function test_payment_cannot_start_before_approval(): void
    {
        $provider = $this->provider();
        $asset = $this->asset($provider, ['daily_rate' => '150000.00']);
        $customer = User::factory()->create(['wallet_balance' => 200000]);

        $loanId = $this->actingAs($customer)
            ->postJson('/api/providers/'.$provider->slug.'/bookings', $this->period() + ['assets' => [$asset->getKey()]])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($customer)
            ->postJson('/api/providers/'.$provider->slug.'/bookings/'.$loanId.'/payment', [
                'payment_method' => Payment::METHOD_WALLET,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payment']);

        $this->assertDatabaseCount('payments', 0);
        // Saldo tidak tersentuh saat pembayaran ditolak.
        $this->assertSame('200000.00', $customer->fresh()->wallet_balance);
    }

    public function test_customer_cannot_pay_someone_elses_booking(): void
    {
        ['loan' => $loan, 'provider' => $provider] = $this->approvedBooking();

        $this->actingAs(User::factory()->create(['wallet_balance' => 200000]))
            ->postJson('/api/providers/'.$provider->slug.'/bookings/'.$loan->getKey().'/payment', [
                'payment_method' => Payment::METHOD_WALLET,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_payment_requires_authentication(): void
    {
        ['loan' => $loan, 'provider' => $provider] = $this->approvedBooking();

        $this->app['auth']->forgetGuards();

        $this->postJson('/api/providers/'.$provider->slug.'/bookings/'.$loan->getKey().'/payment', [
            'payment_method' => Payment::METHOD_WALLET,
        ])->assertUnauthorized();

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_borrower_pays_own_loan_inside_the_app(): void
    {
        Http::fake();

        $borrower = $this->member(Membership::ROLE_BORROWER);
        $borrower->update(['wallet_balance' => 200000]);

        $loan = Loan::factory()->for($this->organization)->create([
            'borrower_id' => $borrower->getKey(),
            'status' => Loan::STATUS_APPROVED,
            'subtotal' => '150000.00',
        ]);

        $this->actingAs($borrower)
            ->postJson('/api/loans/'.$loan->getKey().'/payment', ['payment_method' => Payment::METHOD_WALLET])
            ->assertCreated()
            ->assertJsonPath('data.status', Payment::STATUS_PAID)
            ->assertJsonPath('data.is_verified', false);

        $this->assertSame('50000.00', $borrower->fresh()->wallet_balance);

        $this->actingAs($borrower)
            ->getJson('/api/loans/'.$loan->getKey())
            ->assertOk()
            ->assertJsonPath('data.is_paid', true)
            ->assertJsonPath('data.is_payment_verified', false);
    }

    public function test_checkout_is_blocked_when_prepayment_is_required_and_unpaid(): void
    {
        ['loan' => $loan, 'operator' => $operator, 'asset' => $asset] = $this->approvedBooking();
        $provider = $loan->organization_id;

        $this->policy(['require_prepayment' => true], Organization::query()->findOrFail($provider));

        $this->actingAs($operator)
            ->postJson('/api/loans/'.$loan->getKey().'/checkout', $this->items($asset))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items']);

        $this->assertDatabaseHas('loans', ['id' => $loan->getKey(), 'status' => Loan::STATUS_APPROVED]);
    }

    public function test_checkout_waits_until_admin_verifies_the_funds(): void
    {
        ['loan' => $loan, 'customer' => $customer, 'operator' => $operator, 'asset' => $asset, 'provider' => $provider] = $this->approvedBooking();

        $this->policy(['require_prepayment' => true], Organization::query()->findOrFail($provider->getKey()));

        $this->actingAs($customer)
            ->postJson('/api/providers/'.$provider->slug.'/bookings/'.$loan->getKey().'/payment', [
                'payment_method' => Payment::METHOD_WALLET,
            ])
            ->assertCreated();

        // Lunas, tapi dana belum diverifikasi: serah terima tetap ditahan.
        $this->actingAs($operator)
            ->postJson('/api/loans/'.$loan->getKey().'/checkout', $this->items($asset))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items']);

        $payment = Payment::query()->withoutOrganizationScope()->firstOrFail();

        // Staf penyedia memverifikasi dana dari halaman peminjaman.
        $this->actingAs($this->userIn($provider, Membership::ROLE_ADMIN))
            ->postJson('/api/payments/'.$payment->getKey().'/verify')
            ->assertOk()
            ->assertJsonPath('data.is_verified', true);

        $this->actingAs($operator)
            ->postJson('/api/loans/'.$loan->getKey().'/checkout', $this->items($asset))
            ->assertOk()
            ->assertJsonPath('data.status', Loan::STATUS_CHECKED_OUT);
    }

    public function test_borrower_cannot_verify_the_funds(): void
    {
        ['loan' => $loan, 'customer' => $customer, 'provider' => $provider] = $this->approvedBooking();

        $this->actingAs($customer)
            ->postJson('/api/providers/'.$provider->slug.'/bookings/'.$loan->getKey().'/payment', [
                'payment_method' => Payment::METHOD_WALLET,
            ])
            ->assertCreated();

        $payment = Payment::query()->withoutOrganizationScope()->firstOrFail();

        $this->actingAs($this->userIn($provider, Membership::ROLE_BORROWER))
            ->postJson('/api/payments/'.$payment->getKey().'/verify')
            ->assertForbidden();

        $this->assertNull($payment->fresh()->verified_at);
    }

    public function test_checkout_proceeds_when_the_funds_are_verified(): void
    {
        ['loan' => $loan, 'operator' => $operator, 'asset' => $asset] = $this->approvedBooking();

        $this->policy(['require_prepayment' => true], Organization::query()->findOrFail($loan->organization_id));

        Payment::factory()->paid()->verified()->create([
            'organization_id' => $loan->organization_id,
            'loan_id' => $loan->getKey(),
        ]);

        $this->actingAs($operator)
            ->postJson('/api/loans/'.$loan->getKey().'/checkout', $this->items($asset))
            ->assertOk()
            ->assertJsonPath('data.status', Loan::STATUS_CHECKED_OUT);
    }

    public function test_checkout_proceeds_without_payment_when_policy_does_not_require_it(): void
    {
        ['loan' => $loan, 'operator' => $operator, 'asset' => $asset] = $this->approvedBooking();

        // Penyedia memilih menonaktifkan bayar di muka; alur tetap berjalan
        // tanpa pembayaran.
        $this->policy(['require_prepayment' => false], Organization::query()->findOrFail($loan->organization_id));

        $this->actingAs($operator)
            ->postJson('/api/loans/'.$loan->getKey().'/checkout', $this->items($asset))
            ->assertOk()
            ->assertJsonPath('data.status', Loan::STATUS_CHECKED_OUT);
    }

    public function test_prepayment_gate_is_required_by_default(): void
    {
        ['loan' => $loan, 'operator' => $operator, 'asset' => $asset] = $this->approvedBooking();

        // Tanpa baris kebijakan pun: alur bawaan menahan serah terima sampai
        // pembayaran lunas.
        $this->assertDatabaseCount('organization_policies', 0);

        $this->actingAs($operator)
            ->postJson('/api/loans/'.$loan->getKey().'/checkout', $this->items($asset))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items']);
    }

    public function test_customer_sees_payment_status_in_history(): void
    {
        ['loan' => $loan, 'customer' => $customer, 'provider' => $provider] = $this->approvedBooking();

        $this->actingAs($customer)->getJson('/api/bookings')
            ->assertOk()
            ->assertJsonPath('data.0.payment', null)
            ->assertJsonPath('data.0.can_pay', true);

        $this->actingAs($customer)
            ->postJson('/api/providers/'.$provider->slug.'/bookings/'.$loan->getKey().'/payment', [
                'payment_method' => Payment::METHOD_WALLET,
            ])
            ->assertCreated();

        $this->actingAs($customer)->getJson('/api/bookings')
            ->assertOk()
            ->assertJsonPath('data.0.payment.status', Payment::STATUS_PAID)
            ->assertJsonPath('data.0.payment.is_verified', false)
            ->assertJsonPath('data.0.can_pay', false);
    }

    public function test_provider_sees_payment_status_on_the_loan(): void
    {
        ['loan' => $loan, 'customer' => $customer, 'operator' => $operator, 'provider' => $provider] = $this->approvedBooking();

        $this->actingAs($customer)
            ->postJson('/api/providers/'.$provider->slug.'/bookings/'.$loan->getKey().'/payment', [
                'payment_method' => Payment::METHOD_WALLET,
            ])
            ->assertCreated();

        $this->actingAs($operator)
            ->getJson('/api/loans/'.$loan->getKey())
            ->assertOk()
            ->assertJsonPath('data.payment_status', Payment::STATUS_PAID)
            ->assertJsonPath('data.is_paid', true)
            ->assertJsonPath('data.is_payment_verified', false);
    }

    public function test_wallet_payment_deducts_the_balance_and_marks_the_booking_paid(): void
    {
        Http::fake();
        ['loan' => $loan, 'customer' => $customer, 'provider' => $provider] = $this->approvedBooking();

        $this->actingAs($customer)
            ->postJson(
                '/api/providers/'.$provider->slug.'/bookings/'.$loan->getKey().'/payment',
                ['payment_method' => Payment::METHOD_WALLET],
            )
            ->assertCreated()
            ->assertJsonPath('data.status', Payment::STATUS_PAID)
            ->assertJsonPath('data.payment_method', Payment::METHOD_WALLET);

        // 200000 saldo dikurangi subtotal snapshot 150000.
        $this->assertSame('50000.00', $customer->fresh()->wallet_balance);
        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $customer->getKey(),
            'amount' => '150000.00',
            'type' => 'deduction',
            'status' => 'success',
        ]);

        $payment = Payment::query()->withoutOrganizationScope()->firstOrFail();
        $this->assertNotNull($payment->wallet_transaction_id);
        $this->assertSame('150000.00', $payment->amount);
        $this->assertNull($payment->verified_at);

        // Saldo lokal, jadi tidak ada panggilan ke Midtrans.
        Http::assertNothingSent();
    }

    public function test_wallet_payment_is_refused_when_the_balance_is_not_enough(): void
    {
        Http::fake();
        ['loan' => $loan, 'customer' => $customer, 'provider' => $provider] = $this->approvedBooking('150000.00', 10000);

        $this->actingAs($customer)
            ->postJson(
                '/api/providers/'.$provider->slug.'/bookings/'.$loan->getKey().'/payment',
                ['payment_method' => Payment::METHOD_WALLET],
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payment']);

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->assertSame('10000.00', $customer->fresh()->wallet_balance);
    }

    public function test_cancelling_a_wallet_paid_booking_returns_the_balance_once(): void
    {
        Http::fake();
        ['loan' => $loan, 'customer' => $customer, 'provider' => $provider] = $this->approvedBooking();

        $this->actingAs($customer)
            ->postJson(
                '/api/providers/'.$provider->slug.'/bookings/'.$loan->getKey().'/payment',
                ['payment_method' => Payment::METHOD_WALLET],
            )
            ->assertCreated();

        $this->assertSame('50000.00', $customer->fresh()->wallet_balance);

        $admin = $this->userIn($provider, Membership::ROLE_ADMIN);

        $this->actingAs($admin)
            ->postJson('/api/loans/'.$loan->getKey().'/cancel', ['reason' => 'Unit rusak'])
            ->assertOk();

        // Saldo kembali penuh, dan pengembaliannya tercatat di buku besar.
        $this->assertSame('200000.00', $customer->fresh()->wallet_balance);
        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $customer->getKey(),
            'type' => 'refund',
            'status' => 'success',
        ]);

        // Pembatalan kedua ditolak kebijakan (loan sudah `cancelled`):
        // pengembalian tidak berulang.
        $this->actingAs($admin)
            ->postJson('/api/loans/'.$loan->getKey().'/cancel', ['reason' => 'Lagi'])
            ->assertForbidden();

        $this->assertSame('200000.00', $customer->fresh()->wallet_balance);
        $this->assertDatabaseCount('wallet_transactions', 2);
    }

    public function test_unknown_payment_method_is_rejected(): void
    {
        ['loan' => $loan, 'customer' => $customer, 'provider' => $provider] = $this->approvedBooking();

        $this->actingAs($customer)
            ->postJson(
                '/api/providers/'.$provider->slug.'/bookings/'.$loan->getKey().'/payment',
                ['payment_method' => 'bitcoin'],
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payment_method']);

        $this->assertDatabaseCount('payments', 0);
    }

    /**
     * @return array{items: list<array{asset_id: int, condition: string}>}
     */
    private function items(Asset $asset): array
    {
        return ['items' => [['asset_id' => $asset->getKey(), 'condition' => Asset::CONDITION_GOOD]]];
    }
}
