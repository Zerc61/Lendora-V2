<?php

namespace Tests\Feature\Payments;

use App\Models\Loan;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\Support\TenantTestCase;

/**
 * Rekonsiliasi pembayaran booking pending (`payments:reconcile`).
 *
 * Jalur ini menutup celah notifikasi yang tidak sampai: status ditanyakan
 * langsung ke Midtrans, lalu diterapkan lewat jalur yang sama dengan webhook.
 */
class PaymentReconcileTest extends TenantTestCase
{
    private const SERVER_KEY = 'SB-Mid-server-uji';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.midtrans.server_key' => self::SERVER_KEY,
            'services.midtrans.is_production' => false,
        ]);
    }

    private function pendingPayment(): Payment
    {
        $provider = Organization::factory()->verified()->create();
        $this->asset($provider, ['daily_rate' => '150000.00']);

        $loan = Loan::factory()->approved()->create([
            'organization_id' => $provider->getKey(),
            'borrower_id' => User::factory(),
            'subtotal' => '150000.00',
            'days' => 1,
        ]);

        return Payment::factory()->create([
            'organization_id' => $provider->getKey(),
            'loan_id' => $loan->getKey(),
            'amount' => '150000.00',
            'status' => Payment::STATUS_PENDING,
        ]);
    }

    public function test_reconcile_marks_a_settled_payment_as_paid(): void
    {
        $payment = $this->pendingPayment();

        Http::fake([
            '*/v2/*' => Http::response([
                'order_id' => $payment->order_id,
                'transaction_status' => 'settlement',
                'status_code' => '200',
                'gross_amount' => '150000.00',
            ]),
        ]);

        $this->artisan('payments:reconcile')->assertExitCode(0);

        $this->assertTrue($payment->refresh()->isPaid());
        $this->assertNotNull($payment->paid_at);
    }

    public function test_reconcile_marks_an_expired_payment_as_expired(): void
    {
        $payment = $this->pendingPayment();

        Http::fake([
            '*/v2/*' => Http::response([
                'order_id' => $payment->order_id,
                'transaction_status' => 'expire',
                'status_code' => '407',
                'gross_amount' => '150000.00',
            ]),
        ]);

        $this->artisan('payments:reconcile')->assertExitCode(0);

        $this->assertSame(Payment::STATUS_EXPIRED, $payment->refresh()->status);
    }

    public function test_reconcile_leaves_a_payment_alone_when_the_amount_differs(): void
    {
        $payment = $this->pendingPayment();

        Http::fake([
            '*/v2/*' => Http::response([
                'order_id' => $payment->order_id,
                'transaction_status' => 'settlement',
                'status_code' => '200',
                'gross_amount' => '250000.00',
            ]),
        ]);

        $this->artisan('payments:reconcile')->assertExitCode(0);

        $this->assertSame(Payment::STATUS_PENDING, $payment->refresh()->status);
    }

    public function test_reconcile_closes_a_local_order_that_midtrans_does_not_know(): void
    {
        $payment = $this->pendingPayment();
        $payment->update(['expires_at' => now()->subHour()]);

        Http::fake(['*/v2/*' => Http::response(['status_message' => 'not found'], 404)]);

        $this->artisan('payments:reconcile')->assertExitCode(0);

        $this->assertSame(Payment::STATUS_EXPIRED, $payment->refresh()->status);
    }

    public function test_reconcile_reports_when_midtrans_is_not_configured(): void
    {
        $this->pendingPayment();
        config(['services.midtrans.server_key' => null]);

        Http::fake();

        $this->artisan('payments:reconcile')->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_reconcile_does_nothing_without_pending_rows(): void
    {
        Http::fake();

        $this->artisan('payments:reconcile')
            ->expectsOutputToContain('Tidak ada pembayaran pending')
            ->assertExitCode(0);

        Http::assertNothingSent();
    }
}
