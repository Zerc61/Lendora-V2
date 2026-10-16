<?php

namespace Tests\Feature\Payments;

use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Payments\WalletTransactionService;
use Illuminate\Support\Facades\Http;
use Tests\Support\TenantTestCase;

class WalletReconcileTest extends TenantTestCase
{
    private const SERVER_KEY = 'SB-Mid-server-test';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.midtrans.server_key' => self::SERVER_KEY,
            'services.midtrans.is_production' => false,
        ]);
    }

    public function test_reconcile_updates_pending_to_success(): void
    {
        $user = User::factory()->create(['wallet_balance' => 0]);
        $transaction = WalletTransaction::create([
            'user_id' => $user->id,
            'amount' => 50000,
            'type' => 'topup',
            'reference_id' => 'WLLET-reconcile-test',
            'status' => WalletTransaction::STATUS_PENDING,
        ]);

        Http::fake([
            '*/v2/*' => Http::response([
                'transaction_status' => 'settlement',
                'gross_amount' => '50000',
                'order_id' => 'WLLET-reconcile-test',
            ]),
        ]);

        $this->artisan('wallet:reconcile')->assertExitCode(0);

        $transaction->refresh();
        $this->assertSame(WalletTransaction::STATUS_SUCCESS, $transaction->status);
        $user->refresh();
        $this->assertEquals(50000, $user->wallet_balance);
    }

    public function test_reconcile_marks_unknown_order_as_warning(): void
    {
        $user = User::factory()->create();
        WalletTransaction::create([
            'user_id' => $user->id,
            'amount' => 50000,
            'type' => 'topup',
            'reference_id' => 'WLLET-not-found',
            'status' => WalletTransaction::STATUS_PENDING,
        ]);

        Http::fake([
            '*/v2/*' => Http::response([], 404),
        ]);

        $this->artisan('wallet:reconcile')->assertExitCode(0);

        $this->assertSame(WalletTransaction::STATUS_PENDING, $user->walletTransactions()->first()->status);
    }

    public function test_settlement_applied_twice_credits_the_balance_once(): void
    {
        $user = User::factory()->create(['wallet_balance' => 0]);
        $transaction = WalletTransaction::create([
            'user_id' => $user->id,
            'amount' => 50000,
            'type' => WalletTransaction::TYPE_TOPUP,
            'reference_id' => 'WLLET-idempoten',
            'status' => WalletTransaction::STATUS_PENDING,
        ]);

        $service = app(WalletTransactionService::class);

        $service->applyStatus($transaction, 'settlement');
        $service->applyStatus($transaction->fresh(), 'settlement');

        $this->assertEquals(50000, $user->fresh()->wallet_balance);
    }

    public function test_reconcile_ignores_settlement_with_a_different_amount(): void
    {
        $user = User::factory()->create(['wallet_balance' => 0]);
        $transaction = WalletTransaction::create([
            'user_id' => $user->id,
            'amount' => 50000,
            'type' => WalletTransaction::TYPE_TOPUP,
            'reference_id' => 'WLLET-nominal-beda',
            'status' => WalletTransaction::STATUS_PENDING,
        ]);

        Http::fake([
            '*/v2/*' => Http::response([
                'transaction_status' => 'settlement',
                'gross_amount' => '75000',
                'order_id' => $transaction->reference_id,
            ]),
        ]);

        $this->artisan('wallet:reconcile')->assertExitCode(0);

        $this->assertSame(WalletTransaction::STATUS_PENDING, $transaction->fresh()->status);
        $this->assertEquals(0, $user->fresh()->wallet_balance);
    }
}
