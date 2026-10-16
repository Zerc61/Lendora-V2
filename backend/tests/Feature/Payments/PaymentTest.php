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
 * Charge pembayaran booking (Tahap 4).
 *
 * Nominal selalu dari snapshot harga di loan, bukan dari klien. Pembayaran tidak
 * mengubah status loan; pengaruhnya hanya gerbang serah terima.
 */
class PaymentTest extends TenantTestCase
{
    private const SERVER_KEY = 'SB-Mid-server-uji';

    private function provider(): Organization
    {
        return Organization::factory()->verified()->create();
    }

    private function configureMidtrans(): void
    {
        config([
            'services.midtrans.server_key' => self::SERVER_KEY,
            'services.midtrans.is_production' => false,
            'services.midtrans.finish_url' => 'http://localhost:5173/booking',
        ]);

        Http::fake([
            '*/snap/v1/transactions' => Http::response([
                'token' => 'snap-token-uji',
                'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/snap-token-uji',
            ]),
        ]);
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
     * @return array{loan: Loan, customer: User, operator: User, asset: Asset, provider: Organization}
     */
    private function approvedBooking(string $rate = '150000.00'): array
    {
        $provider = $this->provider();
        $asset = $this->asset($provider, ['daily_rate' => $rate]);
        $customer = User::factory()->create();
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

    public function test_charge_is_created_server_side_with_snapshot_amount(): void
    {
        $this->configureMidtrans();
        ['loan' => $loan, 'customer' => $customer, 'provider' => $provider] = $this->approvedBooking();

        $this->actingAs($customer)
            ->postJson('/api/providers/'.$provider->slug.'/bookings/'.$loan->getKey().'/payment')
            ->assertCreated()
            ->assertJsonPath('data.status', Payment::STATUS_PENDING)
            ->assertJsonPath('data.status_label', 'Menunggu pembayaran')
            ->assertJsonPath('data.amount', '150000.00')
            ->assertJsonPath('data.redirect_url', 'https://app.sandbox.midtrans.com/snap/v4/redirection/snap-token-uji');

        $payment = Payment::query()->withoutOrganizationScope()->firstOrFail();

        $this->assertSame($loan->getKey(), $payment->loan_id);
        $this->assertSame($provider->getKey(), $payment->organization_id);
        $this->assertStringStartsWith('LN-'.$loan->getKey().'-', $payment->order_id);
        $this->assertSame('150000.00', $payment->amount);
        // Tenggat bayar mengikuti jendela pengambilan.
        $this->assertNotNull($payment->expires_at);
        $this->assertDatabaseHas('audit_events', ['action' => 'payment.created', 'subject_id' => $loan->getKey()]);

        // Kiriman klien tidak pernah dipakai: yang dikirim ke Midtrans nominal snapshot.
        Http::assertSent(function ($request) use ($payment): bool {
            $body = $request->data();

            return $request->url() === 'https://app.sandbox.midtrans.com/snap/v1/transactions'
                && $body['transaction_details']['order_id'] === $payment->order_id
                && $body['transaction_details']['gross_amount'] === 150000
                && $body['callbacks']['finish'] === 'http://localhost:5173/booking';
        });
    }

    public function test_client_supplied_amount_is_ignored(): void
    {
        $this->configureMidtrans();
        ['loan' => $loan, 'customer' => $customer, 'provider' => $provider] = $this->approvedBooking();

        $this->actingAs($customer)
            ->postJson(
                '/api/providers/'.$provider->slug.'/bookings/'.$loan->getKey().'/payment',
                ['amount' => 1, 'status' => Payment::STATUS_PAID],
            )
            ->assertCreated()
            ->assertJsonPath('data.amount', '150000.00')
            ->assertJsonPath('data.status', Payment::STATUS_PENDING);
    }

    public function test_payment_cannot_start_before_approval(): void
    {
        $this->configureMidtrans();

        $provider = $this->provider();
        $asset = $this->asset($provider, ['daily_rate' => '150000.00']);
        $customer = User::factory()->create();

        $loanId = $this->actingAs($customer)
            ->postJson('/api/providers/'.$provider->slug.'/bookings', $this->period() + ['assets' => [$asset->getKey()]])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($customer)
            ->postJson('/api/providers/'.$provider->slug.'/bookings/'.$loanId.'/payment')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payment']);

        $this->assertDatabaseCount('payments', 0);
        Http::assertNothingSent();
    }

    public function test_charge_is_reported_when_midtrans_is_not_configured(): void
    {
        config(['services.midtrans.server_key' => null]);
        Http::fake();

        ['loan' => $loan, 'customer' => $customer, 'provider' => $provider] = $this->approvedBooking();

        $this->actingAs($customer)
            ->postJson('/api/providers/'.$provider->slug.'/bookings/'.$loan->getKey().'/payment')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payment']);

        $this->assertDatabaseCount('payments', 0);
        Http::assertNothingSent();
    }

    public function test_customer_cannot_pay_someone_elses_booking(): void
    {
        $this->configureMidtrans();
        ['loan' => $loan, 'provider' => $provider] = $this->approvedBooking();

        $this->actingAs(User::factory()->create())
            ->postJson('/api/providers/'.$provider->slug.'/bookings/'.$loan->getKey().'/payment')
            ->assertForbidden();

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_payment_requires_authentication(): void
    {
        $this->configureMidtrans();
        ['loan' => $loan, 'provider' => $provider] = $this->approvedBooking();

        $this->app['auth']->forgetGuards();

        $this->postJson('/api/providers/'.$provider->slug.'/bookings/'.$loan->getKey().'/payment')
            ->assertUnauthorized();

        $this->assertDatabaseCount('payments', 0);
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

    public function test_checkout_proceeds_when_prepayment_is_required_and_paid(): void
    {
        ['loan' => $loan, 'operator' => $operator, 'asset' => $asset] = $this->approvedBooking();

        $this->policy(['require_prepayment' => true], Organization::query()->findOrFail($loan->organization_id));

        Payment::factory()->paid()->create([
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

        $this->actingAs($operator)
            ->postJson('/api/loans/'.$loan->getKey().'/checkout', $this->items($asset))
            ->assertOk()
            ->assertJsonPath('data.status', Loan::STATUS_CHECKED_OUT);
    }

    public function test_customer_sees_payment_status_in_history(): void
    {
        $this->configureMidtrans();
        ['loan' => $loan, 'customer' => $customer, 'provider' => $provider] = $this->approvedBooking();

        $this->actingAs($customer)->getJson('/api/bookings')
            ->assertOk()
            ->assertJsonPath('data.0.payment', null)
            ->assertJsonPath('data.0.can_pay', true);

        $this->actingAs($customer)
            ->postJson('/api/providers/'.$provider->slug.'/bookings/'.$loan->getKey().'/payment')
            ->assertCreated();

        $this->actingAs($customer)->getJson('/api/bookings')
            ->assertOk()
            ->assertJsonPath('data.0.payment.status', Payment::STATUS_PENDING)
            ->assertJsonPath('data.0.payment.status_label', 'Menunggu pembayaran')
            ->assertJsonPath('data.0.can_pay', true);
    }

    public function test_provider_sees_payment_status_on_the_loan(): void
    {
        $this->configureMidtrans();
        ['loan' => $loan, 'customer' => $customer, 'operator' => $operator, 'provider' => $provider] = $this->approvedBooking();

        $this->actingAs($customer)
            ->postJson('/api/providers/'.$provider->slug.'/bookings/'.$loan->getKey().'/payment')
            ->assertCreated();

        $this->actingAs($operator)
            ->getJson('/api/loans/'.$loan->getKey())
            ->assertOk()
            ->assertJsonPath('data.payment_status', Payment::STATUS_PENDING)
            ->assertJsonPath('data.is_paid', false);
    }

    public function test_wallet_payment_deducts_the_balance_and_marks_the_booking_paid(): void
    {
        Http::fake();
        ['loan' => $loan, 'customer' => $customer, 'provider' => $provider] = $this->approvedBooking();

        $customer->update(['wallet_balance' => 200000]);

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

        // Saldo lokal, jadi tidak ada panggilan ke Midtrans.
        Http::assertNothingSent();
    }

    public function test_wallet_payment_is_refused_when_the_balance_is_not_enough(): void
    {
        Http::fake();
        ['loan' => $loan, 'customer' => $customer, 'provider' => $provider] = $this->approvedBooking();

        $customer->update(['wallet_balance' => 10000]);

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

        $customer->update(['wallet_balance' => 200000]);

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
        $this->configureMidtrans();
        ['loan' => $loan, 'customer' => $customer, 'provider' => $provider] = $this->approvedBooking();

        $this->actingAs($customer)
            ->postJson(
                '/api/providers/'.$provider->slug.'/bookings/'.$loan->getKey().'/payment',
                ['payment_method' => 'bitcoin'],
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payment_method']);

        $this->assertDatabaseCount('payments', 0);
        Http::assertNothingSent();
    }

    /**
     * @return array{items: list<array{asset_id: int, condition: string}>}
     */
    private function items(Asset $asset): array
    {
        return ['items' => [['asset_id' => $asset->getKey(), 'condition' => Asset::CONDITION_GOOD]]];
    }
}
