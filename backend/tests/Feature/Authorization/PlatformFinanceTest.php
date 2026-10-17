<?php

namespace Tests\Feature\Authorization;

use App\Models\Loan;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Menu Keuangan konsol platform: ringkasan uang sewa, buku besar pembayaran,
 * dan daftar sewa yang belum dibayar. Semuanya read-only lintas organisasi.
 */
class PlatformFinanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_platform_user_forbidden(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/platform/finance')
            ->assertForbidden();
    }

    public function test_platform_admin_sees_unpaid_loan_but_not_paid_one(): void
    {
        $provider = Organization::factory()->verified()->create();
        $customer = User::factory()->create();

        // Sudah dibayar: masuk revenue, keluar dari daftar belum dibayar.
        $paid = Loan::factory()->for($provider)->create([
            'borrower_id' => $customer->getKey(),
            'status' => Loan::STATUS_APPROVED,
            'subtotal' => '150000.00',
        ]);
        Payment::query()->create([
            'organization_id' => $provider->getKey(),
            'loan_id' => $paid->getKey(),
            'order_id' => 'WLLET-FIN-1',
            'amount' => '150000.00',
            'status' => Payment::STATUS_PAID,
            'paid_at' => now(),
            'payment_method' => Payment::METHOD_WALLET,
        ]);

        // Disetujui, belum dibayar.
        $unpaid = Loan::factory()->for($provider)->create([
            'borrower_id' => $customer->getKey(),
            'status' => Loan::STATUS_APPROVED,
            'subtotal' => '90000.00',
        ]);

        // Pengembalian dana ke dompet (jalur pembatalan booking).
        $customer->walletTransactions()->create([
            'amount' => '75000.00',
            'type' => WalletTransaction::TYPE_REFUND,
            'reference_id' => 'REFUND-FIN-1',
            'status' => WalletTransaction::STATUS_SUCCESS,
        ]);

        $response = $this->actingAs(User::factory()->platformAdmin()->create())
            ->getJson('/api/platform/finance')
            ->assertOk()
            ->assertJsonCount(1, 'payments.data')
            ->assertJsonCount(1, 'unpaid.data')
            // Konteks sewa ikut di buku besar (borrower + penyedia).
            ->assertJsonPath('payments.data.0.loan.borrower.name', $customer->name)
            ->assertJsonPath('payments.data.0.loan.organization_name', $provider->name)
            ->assertJsonPath('unpaid.data.0.id', $unpaid->getKey());

        $summary = $response->json('summary');

        $this->assertSame(150000.0, (float) $summary['revenue_total']);
        $this->assertSame(1, $summary['revenue_count']);
        $this->assertSame(90000.0, (float) $summary['unpaid_total']);
        $this->assertSame(1, $summary['unpaid_count']);
        $this->assertSame(75000.0, (float) $summary['refund_total']);
        // Pembayaran lunas itu belum diverifikasi admin → masuk antrean verifikasi.
        $this->assertSame(150000.0, (float) $summary['unverified_total']);
        $this->assertSame(1, $summary['unverified_count']);
    }

    /**
     * Pembayaran dompet untuk sewa yang sudah disetujui.
     */
    private function payment(bool $paid = true): Payment
    {
        $provider = Organization::factory()->verified()->create();
        $loan = Loan::factory()->for($provider)->create([
            'status' => Loan::STATUS_APPROVED,
            'subtotal' => '150000.00',
        ]);

        return Payment::factory()
            ->when($paid, fn ($factory) => $factory->paid())
            ->create([
                'organization_id' => $provider->getKey(),
                'loan_id' => $loan->getKey(),
                'payment_method' => Payment::METHOD_WALLET,
            ]);
    }

    public function test_non_platform_user_cannot_verify_a_payment(): void
    {
        $payment = $this->payment();

        $this->actingAs(User::factory()->create())
            ->postJson('/api/platform/finance/payments/'.$payment->getKey().'/verify')
            ->assertForbidden();

        $this->assertNull($payment->fresh()->verified_at);
    }

    public function test_unpaid_payment_cannot_be_verified(): void
    {
        $payment = $this->payment(false);

        $this->actingAs(User::factory()->platformAdmin()->create())
            ->postJson('/api/platform/finance/payments/'.$payment->getKey().'/verify')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payment']);

        $this->assertNull($payment->fresh()->verified_at);
    }

    public function test_platform_admin_verifies_the_funds_once(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $payment = $this->payment();

        $this->actingAs($admin)
            ->postJson('/api/platform/finance/payments/'.$payment->getKey().'/verify')
            ->assertOk()
            ->assertJsonPath('data.is_verified', true);

        $verified = $payment->fresh();
        $this->assertNotNull($verified->verified_at);
        $this->assertSame($admin->getKey(), $verified->verified_by);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'payment.verified',
            'actor_id' => $admin->getKey(),
            'subject_id' => $payment->getKey(),
        ]);

        // Verifikasi kedua tidak mengubah jejak maupun menambah audit.
        $this->actingAs($admin)
            ->postJson('/api/platform/finance/payments/'.$payment->getKey().'/verify')
            ->assertOk();

        $this->assertSame(
            $verified->verified_at->toIso8601String(),
            $payment->fresh()->verified_at->toIso8601String(),
        );
        $this->assertDatabaseCount('audit_events', 1);
    }
}
