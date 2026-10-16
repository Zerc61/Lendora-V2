<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WalletTest extends TestCase
{
    use RefreshDatabase;

    private const SERVER_KEY = 'SB-Mid-server-test';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.midtrans.server_key' => self::SERVER_KEY,
            'services.midtrans.is_production' => false,
            'services.midtrans.finish_url' => 'http://localhost:5173/wallet',
            'services.midtrans.frontend_url' => 'http://localhost:5173',
        ]);
    }

    public function test_balance_endpoint_returns_wallet_balance(): void
    {
        $user = User::factory()->create(['wallet_balance' => 50000]);

        $this->actingAs($user)
            ->getJson('/api/wallet/balance')
            ->assertOk()
            ->assertJson(['data' => ['wallet_balance' => 50000]]);
    }

    public function test_transactions_endpoint_returns_paginated_transactions(): void
    {
        $user = User::factory()->create();
        WalletTransaction::factory()->count(5)->create([
            'user_id' => $user->id,
            'amount' => 10000,
            'type' => 'topup',
            'status' => 'success',
        ]);

        $this->actingAs($user)
            ->getJson('/api/wallet/transactions')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'amount', 'type', 'status', 'reference_id'],
                ],
                'current_page',
                'last_page',
                'total',
            ]);
    }

    public function test_topup_creates_pending_transaction_and_returns_midtrans_data(): void
    {
        Http::fake([
            '*/snap/v1/transactions' => Http::response([
                'token' => 'snap-token-test',
                'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/test',
            ]),
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/wallet/topup', [
            'amount' => 50000,
        ]);

        $response->assertOk()
            ->assertJsonStructure(['data' => ['order_id', 'transaction', 'snap_token', 'redirect_url']]);

        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $user->id,
            'amount' => 50000,
            'type' => 'topup',
            'status' => 'pending',
        ]);

        // Tautan balik memakai origin SPA, bukan origin API: salah origin
        // membuat pengguna mendarat di 404 setelah membayar di Midtrans.
        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return $request->url() === 'https://app.sandbox.midtrans.com/snap/v1/transactions'
                && $body['callbacks']['finish'] === 'http://localhost:5173/app/wallet';
        });
    }

    public function test_topup_validates_amount(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/wallet/topup', [
            'amount' => 500,
        ])->assertUnprocessable();
    }

    public function test_webhook_adds_balance_on_settlement(): void
    {
        $user = User::factory()->create();
        $transaction = WalletTransaction::create([
            'user_id' => $user->id,
            'amount' => 50000,
            'type' => 'topup',
            'reference_id' => 'WLLET-test-123',
            'status' => 'pending',
        ]);

        $payload = $this->signature('settlement', $transaction->reference_id, '50000');

        $user->wallet_balance = 0;

        $this->postJson('/api/payments/webhook', $payload)->assertOk();

        $transaction->refresh();
        $this->assertSame('success', $transaction->status);
        $user->refresh();
        $this->assertEquals(50000, $user->wallet_balance);
    }

    public function test_webhook_rejects_invalid_signature(): void
    {
        $user = User::factory()->create();
        $transaction = WalletTransaction::create([
            'user_id' => $user->id,
            'amount' => 50000,
            'type' => 'topup',
            'reference_id' => 'WLLET-test-456',
            'status' => 'pending',
        ]);

        $payload = [
            'order_id' => $transaction->reference_id,
            'status_code' => '200',
            'gross_amount' => '50000',
            'transaction_status' => 'settlement',
            'signature_key' => 'invalid-signature',
        ];

        $this->postJson('/api/payments/webhook', $payload)->assertUnprocessable();

        $this->assertSame('pending', $transaction->fresh()->status);
    }

    public function test_duplicate_webhook_does_not_double_credit(): void
    {
        $user = User::factory()->create(['wallet_balance' => 0]);
        $transaction = WalletTransaction::create([
            'user_id' => $user->id,
            'amount' => 50000,
            'type' => 'topup',
            'reference_id' => 'WLLET-test-789',
            'status' => 'pending',
        ]);

        $payload = $this->signature('settlement', $transaction->reference_id, '50000');

        $this->postJson('/api/payments/webhook', $payload)->assertOk();
        $this->postJson('/api/payments/webhook', $payload)->assertOk();

        $transaction->refresh();
        $this->assertSame('success', $transaction->status);
        $user->refresh();
        $this->assertEquals(50000, $user->wallet_balance);
    }

    public function test_webhook_rejects_amount_mismatch(): void
    {
        $user = User::factory()->create();
        $transaction = WalletTransaction::create([
            'user_id' => $user->id,
            'amount' => 50000,
            'type' => 'topup',
            'reference_id' => 'WLLET-test-000',
            'status' => 'pending',
        ]);

        // signature computed with correct amount 50000, but gross_amount is 60000
        $payload = $this->signature('settlement', $transaction->reference_id, '60000');

        $this->postJson('/api/payments/webhook', $payload)->assertUnprocessable();

        $this->assertSame('pending', $transaction->fresh()->status);
        $this->assertEquals(0, $user->fresh()->wallet_balance);
    }

    public function test_topup_is_reported_when_midtrans_is_not_configured(): void
    {
        config(['services.midtrans.server_key' => null]);
        Http::fake();

        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/wallet/topup', ['amount' => 50000])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);

        $this->assertDatabaseCount('wallet_transactions', 0);
        Http::assertNothingSent();
    }

    public function test_topup_marks_the_transaction_failed_when_charge_is_rejected(): void
    {
        Http::fake([
            '*/snap/v1/transactions' => Http::response(['error_messages' => ['akses ditolak']], 401),
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/wallet/topup', ['amount' => 50000])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);

        $this->assertDatabaseCount('wallet_transactions', 1);
        $this->assertSame(WalletTransaction::STATUS_FAILED, WalletTransaction::firstOrFail()->status);
    }

    public function test_transactions_endpoint_only_returns_the_owning_users_rows(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        WalletTransaction::factory()->create([
            'user_id' => $user->id,
            'reference_id' => 'WLLET-milik-sendiri',
            'type' => 'topup',
            'status' => 'success',
        ]);
        WalletTransaction::factory()->create([
            'user_id' => $other->id,
            'reference_id' => 'WLLET-milik-orang-lain',
            'type' => 'topup',
            'status' => 'success',
        ]);

        $this->actingAs($user)
            ->getJson('/api/wallet/transactions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference_id', 'WLLET-milik-sendiri')
            ->assertJsonPath('data.0.direction', 'in')
            ->assertJsonPath('data.0.status_label', 'Berhasil');
    }

    public function test_webhook_does_not_credit_a_non_topup_row(): void
    {
        $user = User::factory()->create(['wallet_balance' => 100000]);
        $deduction = WalletTransaction::create([
            'user_id' => $user->id,
            'amount' => 25000,
            'type' => WalletTransaction::TYPE_DEDUCTION,
            'reference_id' => 'WLLET-deduction-1',
            'status' => WalletTransaction::STATUS_SUCCESS,
        ]);

        $payload = $this->signature('settlement', $deduction->reference_id, '25000');

        $this->postJson('/api/payments/webhook', $payload)->assertOk();

        $this->assertSame('success', $deduction->fresh()->status);
        $this->assertEquals(100000, $user->fresh()->wallet_balance);
    }

    public function test_refund_notification_pulls_the_balance_back(): void
    {
        $user = User::factory()->create(['wallet_balance' => 50000]);
        $transaction = WalletTransaction::create([
            'user_id' => $user->id,
            'amount' => 50000,
            'type' => WalletTransaction::TYPE_TOPUP,
            'reference_id' => 'WLLET-refund-1',
            'status' => WalletTransaction::STATUS_SUCCESS,
        ]);

        $this->postJson('/api/payments/webhook', $this->signature('refund', $transaction->reference_id, '50000'))
            ->assertOk();

        $this->assertEquals(0, $user->fresh()->wallet_balance);
        $this->assertSame(WalletTransaction::STATUS_REVERSED, $transaction->fresh()->status);
    }

    public function test_repeated_refund_notification_pulls_the_balance_once(): void
    {
        $user = User::factory()->create(['wallet_balance' => 50000]);
        $transaction = WalletTransaction::create([
            'user_id' => $user->id,
            'amount' => 50000,
            'type' => WalletTransaction::TYPE_TOPUP,
            'reference_id' => 'WLLET-refund-2',
            'status' => WalletTransaction::STATUS_SUCCESS,
        ]);

        $payload = $this->signature('refund', $transaction->reference_id, '50000');

        $this->postJson('/api/payments/webhook', $payload)->assertOk();
        $this->postJson('/api/payments/webhook', $payload)->assertOk();

        $this->assertEquals(0, $user->fresh()->wallet_balance);
    }

    public function test_partial_refund_pulls_only_the_refunded_amount(): void
    {
        $user = User::factory()->create(['wallet_balance' => 50000]);
        $transaction = WalletTransaction::create([
            'user_id' => $user->id,
            'amount' => 50000,
            'type' => WalletTransaction::TYPE_TOPUP,
            'reference_id' => 'WLLET-refund-3',
            'status' => WalletTransaction::STATUS_SUCCESS,
        ]);

        $payload = $this->signature('partial_refund', $transaction->reference_id, '50000');
        $payload['refund_amount'] = '20000.00';

        $this->postJson('/api/payments/webhook', $payload)->assertOk();

        // Sebagian: saldo berkurang 20000, barisnya tetap sukses.
        $this->assertEquals(30000, $user->fresh()->wallet_balance);
        $this->assertSame(WalletTransaction::STATUS_SUCCESS, $transaction->fresh()->status);
    }

    public function test_refund_leaves_the_balance_at_zero_when_it_was_already_spent(): void
    {
        $user = User::factory()->create(['wallet_balance' => 10000]);
        $transaction = WalletTransaction::create([
            'user_id' => $user->id,
            'amount' => 50000,
            'type' => WalletTransaction::TYPE_TOPUP,
            'reference_id' => 'WLLET-refund-4',
            'status' => WalletTransaction::STATUS_SUCCESS,
        ]);

        $this->postJson('/api/payments/webhook', $this->signature('chargeback', $transaction->reference_id, '50000'))
            ->assertOk();

        // Saldo tidak boleh negatif; sisanya dicatat untuk ditindaklanjuti manual.
        $this->assertEquals(0, $user->fresh()->wallet_balance);
        $this->assertSame(WalletTransaction::STATUS_REVERSED, $transaction->fresh()->status);
        $this->assertTrue($transaction->fresh()->metadata['reversal_deferred']);
    }

    public function test_capture_under_fraud_review_does_not_credit_the_balance(): void
    {
        $user = User::factory()->create(['wallet_balance' => 0]);
        $transaction = WalletTransaction::create([
            'user_id' => $user->id,
            'amount' => 50000,
            'type' => WalletTransaction::TYPE_TOPUP,
            'reference_id' => 'WLLET-fraud-1',
            'status' => WalletTransaction::STATUS_PENDING,
        ]);

        $payload = $this->signature('capture', $transaction->reference_id, '50000');
        $payload['fraud_status'] = 'challenge';

        $this->postJson('/api/payments/webhook', $payload)->assertOk();

        $this->assertEquals(0, $user->fresh()->wallet_balance);
        $this->assertSame(WalletTransaction::STATUS_PENDING, $transaction->fresh()->status);
    }

    public function test_capture_with_denied_fraud_marks_the_transaction_failed(): void
    {
        $user = User::factory()->create(['wallet_balance' => 0]);
        $transaction = WalletTransaction::create([
            'user_id' => $user->id,
            'amount' => 50000,
            'type' => WalletTransaction::TYPE_TOPUP,
            'reference_id' => 'WLLET-fraud-2',
            'status' => WalletTransaction::STATUS_PENDING,
        ]);

        $payload = $this->signature('capture', $transaction->reference_id, '50000');
        $payload['fraud_status'] = 'deny';

        $this->postJson('/api/payments/webhook', $payload)->assertOk();

        $this->assertEquals(0, $user->fresh()->wallet_balance);
        $this->assertSame(WalletTransaction::STATUS_FAILED, $transaction->fresh()->status);
    }

    public function test_settlement_after_a_refund_does_not_credit_again(): void
    {
        $user = User::factory()->create(['wallet_balance' => 50000]);
        $transaction = WalletTransaction::create([
            'user_id' => $user->id,
            'amount' => 50000,
            'type' => WalletTransaction::TYPE_TOPUP,
            'reference_id' => 'WLLET-refund-5',
            'status' => WalletTransaction::STATUS_SUCCESS,
        ]);

        $this->postJson('/api/payments/webhook', $this->signature('refund', $transaction->reference_id, '50000'))
            ->assertOk();

        // Notifikasi basi: saldo tidak boleh kembali naik setelah ditarik.
        $this->postJson('/api/payments/webhook', $this->signature('settlement', $transaction->reference_id, '50000'))
            ->assertOk();

        $this->assertEquals(0, $user->fresh()->wallet_balance);
        $this->assertSame(WalletTransaction::STATUS_REVERSED, $transaction->fresh()->status);
    }

    public function test_credit_creates_exactly_one_notification(): void
    {
        $user = User::factory()->create(['wallet_balance' => 0]);
        $transaction = WalletTransaction::create([
            'user_id' => $user->id,
            'amount' => 50000,
            'type' => WalletTransaction::TYPE_TOPUP,
            'reference_id' => 'WLLET-notif-1',
            'status' => WalletTransaction::STATUS_PENDING,
        ]);

        $payload = $this->signature('settlement', $transaction->reference_id, '50000');

        $this->postJson('/api/payments/webhook', $payload)->assertOk();
        $this->postJson('/api/payments/webhook', $payload)->assertOk();

        $this->assertSame(1, $user->notifications()->count());
    }

    public function test_reversal_tells_the_user_the_balance_was_deducted(): void
    {
        $user = User::factory()->create(['wallet_balance' => 50000]);
        $transaction = WalletTransaction::create([
            'user_id' => $user->id,
            'amount' => 50000,
            'type' => WalletTransaction::TYPE_TOPUP,
            'reference_id' => 'WLLET-notif-2',
            'status' => WalletTransaction::STATUS_SUCCESS,
        ]);

        $this->postJson('/api/payments/webhook', $this->signature('refund', $transaction->reference_id, '50000'))
            ->assertOk();

        $notification = $user->notifications()->first();

        $this->assertNotNull($notification);
        $this->assertSame('wallet.reversed', $notification->data['key']);
    }

    private function signature(string $status, string $orderId, string $amount): array
    {
        return [
            'order_id' => $orderId,
            'status_code' => '200',
            'gross_amount' => $amount,
            'transaction_status' => $status,
            'transaction_id' => 'trx-test',
            'signature_key' => hash('sha512', $orderId.'200'.$amount.self::SERVER_KEY),
        ];
    }
}
