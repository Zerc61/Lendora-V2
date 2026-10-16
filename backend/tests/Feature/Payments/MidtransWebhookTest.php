<?php

namespace Tests\Feature\Payments;

use App\Models\Loan;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\User;
use Tests\Support\TenantTestCase;

/**
 * Notifikasi pembayaran Midtrans (Tahap 4).
 *
 * Rute ini tanpa autentikasi dan tanpa token CSRF, jadi seluruh penjagaannya
 * ada pada verifikasi tanda tangan sha512 dengan kunci server. Notifikasi yang
 * sama datang dua kali tidak boleh menghasilkan dua baris `paid`.
 */
class MidtransWebhookTest extends TenantTestCase
{
    private const SERVER_KEY = 'SB-Mid-server-uji';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.midtrans.server_key' => self::SERVER_KEY]);
    }

    /**
     * @return array{payment: Payment, loan: Loan}
     */
    private function pendingPayment(): array
    {
        $provider = Organization::factory()->verified()->create();
        $asset = $this->asset($provider, ['daily_rate' => '150000.00']);

        $loan = Loan::factory()->approved()->create([
            'organization_id' => $provider->getKey(),
            'borrower_id' => User::factory(),
            'subtotal' => '150000.00',
            'days' => 1,
        ]);

        $payment = Payment::factory()->create([
            'organization_id' => $provider->getKey(),
            'loan_id' => $loan->getKey(),
            'amount' => '150000.00',
            'status' => Payment::STATUS_PENDING,
        ]);

        return compact('payment', 'loan');
    }

    /**
     * Payload notifikasi Midtrans lengkap dengan tanda tangan sah.
     *
     * @return array<string, string>
     */
    private function notification(Payment $payment, string $transactionStatus = 'settlement'): array
    {
        $statusCode = '200';
        $grossAmount = '150000.00';

        return [
            'order_id' => $payment->order_id,
            'status_code' => $statusCode,
            'gross_amount' => $grossAmount,
            'transaction_status' => $transactionStatus,
            'transaction_id' => 'trx-uji',
            'signature_key' => hash('sha512', $payment->order_id.$statusCode.$grossAmount.self::SERVER_KEY),
        ];
    }

    public function test_verified_settlement_marks_the_payment_paid(): void
    {
        ['payment' => $payment, 'loan' => $loan] = $this->pendingPayment();

        // Tanpa actingAs: webhook tidak memakai sesi.
        $this->postJson('/api/payments/webhook', $this->notification($payment))
            ->assertOk()
            ->assertJsonPath('data.status', Payment::STATUS_PAID);

        $payment->refresh();

        $this->assertTrue($payment->isPaid());
        $this->assertNotNull($payment->paid_at);

        // Sumbu pembayaran terpisah: status loan tidak ikut berubah.
        $this->assertSame(Loan::STATUS_APPROVED, $loan->refresh()->status);
    }

    public function test_duplicate_notification_only_marks_one_paid_row(): void
    {
        ['payment' => $payment] = $this->pendingPayment();

        $payload = $this->notification($payment);

        $this->postJson('/api/payments/webhook', $payload)->assertOk();
        $this->postJson('/api/payments/webhook', $payload)->assertOk();
        $this->postJson('/api/payments/webhook', $payload)->assertOk();

        $this->assertSame(1, Payment::query()->withoutOrganizationScope()->where('status', Payment::STATUS_PAID)->count());
        $this->assertSame(1, Payment::query()->withoutOrganizationScope()->count());
    }

    public function test_notification_with_a_bad_signature_is_rejected(): void
    {
        ['payment' => $payment] = $this->pendingPayment();

        $payload = $this->notification($payment);
        $payload['signature_key'] = str_repeat('a', 128);

        $this->postJson('/api/payments/webhook', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['signature_key']);

        $this->assertSame(Payment::STATUS_PENDING, $payment->refresh()->status);
    }

    public function test_notification_without_a_server_key_is_rejected(): void
    {
        config(['services.midtrans.server_key' => null]);

        ['payment' => $payment] = $this->pendingPayment();

        $this->postJson('/api/payments/webhook', $this->notification($payment))->assertUnprocessable();

        $this->assertSame(Payment::STATUS_PENDING, $payment->refresh()->status);
    }

    public function test_unknown_order_is_ignored(): void
    {
        ['payment' => $payment] = $this->pendingPayment();

        $payload = $this->notification($payment);
        $payload['order_id'] = 'LN-999-tidak-dikenal';
        $payload['signature_key'] = hash(
            'sha512',
            $payload['order_id'].$payload['status_code'].$payload['gross_amount'].self::SERVER_KEY,
        );

        $this->postJson('/api/payments/webhook', $payload)
            ->assertOk()
            ->assertJsonPath('data.status', 'ignored');

        $this->assertSame(Payment::STATUS_PENDING, $payment->refresh()->status);
    }

    public function test_expired_transaction_marks_the_payment_expired(): void
    {
        ['payment' => $payment] = $this->pendingPayment();

        $this->postJson('/api/payments/webhook', $this->notification($payment, 'expire'))
            ->assertOk()
            ->assertJsonPath('data.status', Payment::STATUS_EXPIRED);

        $this->assertSame(Payment::STATUS_EXPIRED, $payment->refresh()->status);
        $this->assertNull($payment->paid_at);
    }

    public function test_second_settled_attempt_does_not_create_a_second_paid_row(): void
    {
        ['payment' => $first, 'loan' => $loan] = $this->pendingPayment();

        $second = Payment::factory()->create([
            'organization_id' => $loan->organization_id,
            'loan_id' => $loan->getKey(),
            'amount' => '150000.00',
            'status' => Payment::STATUS_PENDING,
        ]);

        $this->assertNotSame($first->order_id, $second->order_id);

        $this->postJson('/api/payments/webhook', $this->notification($first))->assertOk();
        $this->postJson('/api/payments/webhook', $this->notification($second))->assertOk();

        $this->assertTrue($first->refresh()->isPaid());
        $this->assertSame(Payment::STATUS_FAILED, $second->refresh()->status);

        $this->assertSame(
            1,
            Payment::query()->withoutOrganizationScope()
                ->where('loan_id', $loan->getKey())
                ->where('status', Payment::STATUS_PAID)
                ->count(),
        );
    }

    public function test_refunded_payment_is_marked_refunded_not_failed(): void
    {
        ['payment' => $payment, 'loan' => $loan] = $this->pendingPayment();

        $this->postJson('/api/payments/webhook', $this->notification($payment))->assertOk();
        $this->assertTrue($payment->refresh()->isPaid());

        $this->postJson('/api/payments/webhook', $this->notification($payment, 'refund'))
            ->assertOk()
            ->assertJsonPath('data.status', Payment::STATUS_REFUNDED);

        $this->assertSame(Payment::STATUS_REFUNDED, $payment->refresh()->status);

        // Slot `paid` bebas lagi: booking yang dananya dikembalikan bisa dibayar ulang.
        $this->assertSame(
            0,
            Payment::query()->withoutOrganizationScope()
                ->where('loan_id', $loan->getKey())
                ->where('status', Payment::STATUS_PAID)
                ->count(),
        );
    }

    public function test_capture_under_fraud_review_is_not_paid(): void
    {
        ['payment' => $payment] = $this->pendingPayment();

        $notification = $this->notification($payment, 'capture');
        $notification['fraud_status'] = 'challenge';

        $this->postJson('/api/payments/webhook', $notification)
            ->assertOk()
            ->assertJsonPath('data.status', Payment::STATUS_PENDING);

        $this->assertFalse($payment->refresh()->isPaid());
        $this->assertNull($payment->paid_at);
    }
}
