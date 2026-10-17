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
    }
}
