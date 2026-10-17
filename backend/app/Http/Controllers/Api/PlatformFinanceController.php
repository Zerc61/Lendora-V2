<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\LoanResource;
use App\Http\Resources\PaymentResource;
use App\Models\Loan;
use App\Models\Payment;
use App\Models\WalletTransaction;
use Illuminate\Http\Request;

/**
 * Buku besar uang dari konsol platform (menu Keuangan).
 *
 * Membaca lintas organisasi: pembayaran sewa yang masuk, sewa yang disetujui
 * tapi belum dibayar, dan pengembalian dana ke dompet. Semuanya read-only —
 * pengembalian dana hanya dijalankan oleh pembatalan booking (LoanService),
 * bukan tombol di sini.
 */
class PlatformFinanceController extends Controller
{
    public function index(Request $request): mixed
    {
        abort_unless($request->user()?->isPlatformAdmin() === true, 403);

        $summary = [
            'revenue_total' => (float) Payment::query()->withoutOrganizationScope()->where('status', Payment::STATUS_PAID)->sum('amount'),
            'revenue_count' => Payment::query()->withoutOrganizationScope()->where('status', Payment::STATUS_PAID)->count(),
            'refund_total' => (float) WalletTransaction::query()
                ->where('type', WalletTransaction::TYPE_REFUND)
                ->where('status', WalletTransaction::STATUS_SUCCESS)
                ->sum('amount'),
            'unpaid_total' => (float) $this->unpaidQuery()->sum('subtotal'),
            'unpaid_count' => (int) $this->unpaidQuery()->count(),
        ];

        $paymentsPage = Payment::query()
            ->withoutOrganizationScope()
            ->with(['loan' => fn ($q) => $q->withoutGlobalScope('organization'), 'loan.borrower', 'loan.organization'])
            ->orderByDesc('id')
            ->paginate(24, ['*'], 'page', (int) $request->query('page', 1));

        $unpaidPage = $this->unpaidQuery()
            ->with(['borrower', 'organization', 'latestPayment'])
            ->orderByDesc('id')
            ->paginate(24, ['*'], 'unpaid_page', (int) $request->query('unpaid_page', 1));

        return response()->json([
            'summary' => $summary,
            'payments' => [
                'data' => PaymentResource::collection($paymentsPage->getCollection())->resolve(),
                'meta' => $this->pageMeta($paymentsPage),
            ],
            'unpaid' => [
                'data' => LoanResource::collection($unpaidPage->getCollection())->resolve(),
                'meta' => $this->pageMeta($unpaidPage),
            ],
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function pageMeta($page): array
    {
        return [
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
            'from' => $page->firstItem(),
            'to' => $page->lastItem(),
        ];
    }

    /**
     * Sewa yang disetujui tetapi belum punya pembayaran lunas.
     */
    private function unpaidQuery()
    {
        return Loan::query()
            ->withoutOrganizationScope()->where('status', Loan::STATUS_APPROVED)
            ->where('subtotal', '>', 0)
            ->whereRaw('not exists (select 1 from payments where payments.loan_id = loans.id and payments.status = ?)', [Payment::STATUS_PAID]);
    }
}
