<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\LoanResource;
use App\Models\Loan;
use App\Models\OrganizationPolicy;
use App\Models\Payment;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Dashboard Borrower. Semua kueri dibatasi `borrower_id = user login`, sehingga
 * angka, daftar, kalender, dan aktivitas hanya milik peminjam tersebut.
 *
 * Kriteria status mengikuti state machine Loan (ADR-001) dan group `active`
 * yang sudah dipakai GET /loans, supaya angka dashboard dan daftar peminjaman
 * tidak pernah berbeda definisi:
 *   - active      : checked_out, atau approved yang masih dalam jendela ambil
 *   - pending     : requested (menunggu persetujuan pengelola)
 *   - due_soon    : checked_out dengan due_at sudah lewat atau maksimal 3 hari
 *                   ke depan (satu loan hanya dihitung sekali)
 *   - completed   : returned
 *
 * Transaksi `rejected`, `cancelled`, dan `expired` tidak pernah masuk active.
 */
class BorrowerDashboardController extends Controller
{
    /** Batas "mendekati tenggat" untuk statistik dan penanda kalender. */
    private const DUE_SOON_DAYS = 3;

    public function overview(Request $request): JsonResponse
    {
        $user = $request->user();
        $policy = OrganizationPolicy::resolve(app(OrganizationContext::class)->id());
        $cutoff = now()->subHours($policy->pickup_window_hours);
        $dueLimit = now()->addDays(self::DUE_SOON_DAYS)->endOfDay();

        $scope = fn () => Loan::query()->where('borrower_id', $user->getKey());

        $activeQuery = $scope()->where(function ($query) use ($cutoff): void {
            $query->where('status', Loan::STATUS_CHECKED_OUT)
                ->orWhere(fn ($query) => $query
                    ->where('status', Loan::STATUS_APPROVED)
                    ->where('approved_at', '>=', $cutoff));
        });

        // Sewa yang sudah disetujui tetapi belum dibayar: di sinilah penyewa
        // diberi tahu bahwa tagihan sudah menunggu (serah terima ditahan
        // sampai lunas).
        $unpaidQuery = fn () => $scope()
            ->where('status', Loan::STATUS_APPROVED)
            ->whereRaw('not exists (select 1 from payments where payments.loan_id = loans.id and payments.status = ?)', [Payment::STATUS_PAID]);

        $counts = [
            'active' => (clone $activeQuery)->count(),
            'pending' => $scope()->where('status', Loan::STATUS_REQUESTED)->count(),
            'unpaid' => $unpaidQuery()->count(),
            'due_soon' => $scope()
                ->where('status', Loan::STATUS_CHECKED_OUT)
                ->whereNotNull('due_at')
                ->where('due_at', '<=', $dueLimit)
                ->count(),
            'completed' => $scope()->where('status', Loan::STATUS_RETURNED)->count(),
        ];

        $active = (clone $activeQuery)
            ->with(['items.asset', 'borrower', 'latestPayment'])
            ->orderByRaw('due_at asc nulls last')
            ->orderBy('starts_at')
            ->limit(5)
            ->get();

        $requests = $scope()
            ->with(['items.asset', 'borrower', 'latestPayment'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        $duePayments = $unpaidQuery()
            ->with(['items.asset', 'latestPayment'])
            ->orderBy('starts_at')
            ->limit(5)
            ->get();

        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'due_soon_days' => self::DUE_SOON_DAYS,
            'counts' => $counts,
            'active' => LoanResource::collection($active)->resolve(),
            'requests' => LoanResource::collection($requests)->resolve(),
            'due_payments' => LoanResource::collection($duePayments)->resolve(),
            'activity' => $this->activity($scope),
        ]);
    }

    /**
     * Kalender jadwal. Rentang dijepit oleh `from`/`to` (YYYY-MM-DD), maksimum
     * 62 hari, sehingga satu permintaan bulanan menutup tampilan bulan.
     */
    public function calendar(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $from = isset($validated['from'])
            ? Carbon::createFromFormat('Y-m-d', $validated['from'])->startOfDay()
            : now()->startOfMonth();
        $to = isset($validated['to'])
            ? Carbon::createFromFormat('Y-m-d', $validated['to'])->endOfDay()
            : now()->endOfMonth();

        if ($to->lessThan($from)) {
            abort(422, 'Rentang tanggal tidak valid.');
        }

        if ($from->diffInDays($to) > 62) {
            abort(422, 'Rentang kalender maksimal 62 hari.');
        }

        $loans = Loan::query()
            ->where('borrower_id', $request->user()->getKey())
            ->whereNotIn('status', [Loan::STATUS_REJECTED, Loan::STATUS_CANCELLED])
            ->where('starts_at', '<=', $to)
            ->where(function ($query) use ($from): void {
                $query->where('ends_at', '>=', $from)
                    ->orWhere('due_at', '>=', $from)
                    ->orWhere('starts_at', '>=', $from);
            })
            ->with('items.asset')
            ->get();

        return response()->json([
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'due_soon_days' => self::DUE_SOON_DAYS,
            'events' => $loans
                ->flatMap(fn (Loan $loan): array => $this->events($loan))
                ->sortBy('date')
                ->values()
                ->all(),
        ]);
    }

    /**
     * Aktivitas terbaru: diturunkan dari milestone timestamp pada loan milik
     * peminjam (created_at, approved_at, checked_out_at, returned_at) plus
     * status terminal. Tidak ada sumber lain, tidak ada data yang dikarang.
     *
     * @return list<array{key: string, label: string, occurred_at: string, loan_id: int}>
     */
    private function activity(callable $scope): array
    {
        $labels = [
            'loan.requested' => 'Pengajuan dibuat',
            'loan.approved' => 'Pengajuan disetujui',
            'loan.rejected' => 'Pengajuan ditolak',
            'loan.cancelled' => 'Pengajuan dibatalkan',
            'loan.checked_out' => 'Aset diambil',
            'loan.returned' => 'Pengembalian dikonfirmasi',
        ];

        $entries = [];

        $scope()
            ->orderByDesc('updated_at')
            ->limit(30)
            ->get(['id', 'status', 'created_at', 'approved_at', 'checked_out_at', 'returned_at', 'updated_at'])
            ->each(function (Loan $loan) use (&$entries, $labels): void {
                $milestones = [
                    'loan.requested' => $loan->created_at,
                    'loan.approved' => $loan->approved_at,
                    'loan.checked_out' => $loan->checked_out_at,
                    'loan.returned' => $loan->returned_at,
                ];

                if ($loan->status === Loan::STATUS_REJECTED || $loan->status === Loan::STATUS_CANCELLED) {
                    $milestones[$loan->status === Loan::STATUS_REJECTED ? 'loan.rejected' : 'loan.cancelled'] = $loan->updated_at;
                }

                foreach ($milestones as $key => $occurredAt) {
                    if ($occurredAt === null) {
                        continue;
                    }

                    $entries[] = [
                        'key' => $key,
                        'label' => $labels[$key],
                        'occurred_at' => $occurredAt->toIso8601String(),
                        'loan_id' => (int) $loan->getKey(),
                    ];
                }
            });

        usort($entries, fn (array $a, array $b): int => strcmp($b['occurred_at'], $a['occurred_at']));

        return array_slice($entries, 0, 8);
    }

    /**
     * Satu loan menghasilkan maksimal tiga event: pengambilan, rentang
     * periode, dan tenggat. Semua tanggal memakai `Y-m-d` hasil backend
     * (dinding jam tersimpan, UTC) supaya setara antar zona waktu.
     *
     * @return list<array<string, mixed>>
     */
    private function events(Loan $loan): array
    {
        $names = $loan->items->pluck('asset.name')->filter()->values();
        $title = $names->isEmpty() ? 'Peminjaman aset' : $names->implode(', ');

        $base = [
            'loan_id' => (int) $loan->getKey(),
            'title' => $title,
            'status' => $loan->status,
            'status_label' => $loan->label(),
        ];

        $events = [];
        $status = $loan->status;

        // Periode. Pengajuan tampil sebagai jadwal tentatif; penolakan dan
        // pembatalan tidak pernah muncul (sudah difilter di kueri).
        if ($loan->starts_at !== null && $loan->ends_at !== null) {
            $events[] = $base + [
                'kind' => 'period',
                'date' => $loan->starts_at->format('Y-m-d'),
                'start_date' => $loan->starts_at->format('Y-m-d'),
                'end_date' => $loan->ends_at->format('Y-m-d'),
                'tentative' => $status === Loan::STATUS_REQUESTED,
                'done' => in_array($status, [Loan::STATUS_RETURNED, Loan::STATUS_EXPIRED], true),
            ];
        }

        $pickupAt = match ($status) {
            Loan::STATUS_CHECKED_OUT, Loan::STATUS_RETURNED => $loan->checked_out_at ?? $loan->starts_at,
            Loan::STATUS_APPROVED, Loan::STATUS_REQUESTED => $loan->starts_at,
            default => null,
        };

        if ($pickupAt !== null) {
            $events[] = $base + [
                'kind' => 'pickup',
                'date' => $pickupAt->format('Y-m-d'),
                'occurs_at' => $pickupAt->toIso8601String(),
                'tentative' => $status === Loan::STATUS_REQUESTED,
                'done' => in_array($status, [Loan::STATUS_CHECKED_OUT, Loan::STATUS_RETURNED], true),
                'pickup_deadline' => $status === Loan::STATUS_APPROVED
                    ? $loan->pickupDeadline($this->pickupWindow($loan))?->toIso8601String()
                    : null,
            ];
        }

        if ($loan->due_at !== null && $status !== Loan::STATUS_REQUESTED) {
            $events[] = $base + [
                'kind' => 'due',
                'date' => $loan->due_at->format('Y-m-d'),
                'occurs_at' => $loan->due_at->toIso8601String(),
                'tentative' => false,
                'done' => $status === Loan::STATUS_RETURNED,
                'is_overdue' => $loan->isOverdue(),
                'is_due_soon' => $status === Loan::STATUS_CHECKED_OUT
                    && $loan->due_at->lessThanOrEqualTo(now()->addDays(self::DUE_SOON_DAYS)->endOfDay()),
            ];
        }

        return $events;
    }

    private function pickupWindow(Loan $loan): int
    {
        return OrganizationPolicy::resolve($loan->organization_id)->pickup_window_hours;
    }
}
