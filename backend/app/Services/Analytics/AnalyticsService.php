<?php

namespace App\Services\Analytics;

use App\Models\Asset;
use App\Models\Issue;
use App\Models\Loan;
use App\Models\MaintenanceTicket;
use App\Models\Membership;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Query agregat Fase 9 (Analytics): ringkasan admin, laporan utilisasi, laporan
 * kondisi, dan rekap peminjaman.
 *
 * Sebagian query memakai query builder mentah (untuk join dan agregat) sehingga
 * tidak melewati global scope tenant. Semua query tersebut memfilter
 * organization_id secara eksplisit; isolasi tenant diverifikasi lewat test.
 */
class AnalyticsService
{
    /** Jumlah baris default pada respons JSON. */
    public const DEFAULT_LIMIT = 20;

    /** Batas baris ekspor supaya satu request tidak menarik seluruh tabel. */
    public const EXPORT_LIMIT = 1000;

    /** Loan dianggap "dipinjam" kalau sudah disetujui atau lebih jauh. */
    public const BORROWED_STATUSES = [
        Loan::STATUS_APPROVED,
        Loan::STATUS_CHECKED_OUT,
        Loan::STATUS_RETURNED,
    ];

    /** Lifecycle yang menandakan aset butuh perhatian. */
    public const PROBLEM_LIFECYCLES = [
        Asset::LIFECYCLE_AWAITING_INSPECTION,
        Asset::LIFECYCLE_UNDER_MAINTENANCE,
    ];

    /** Kondisi yang menandakan aset butuh perhatian. */
    public const PROBLEM_CONDITIONS = [
        Asset::CONDITION_POOR,
        Asset::CONDITION_UNSERVICEABLE,
    ];

    /** Status issue yang masih terbuka. */
    public const OPEN_ISSUE_STATUSES = [
        Issue::STATUS_OPEN,
        Issue::STATUS_REVIEWING,
    ];

    public function __construct(private readonly OrganizationContext $context) {}

    /**
     * Ringkasan dashboard admin.
     *
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $byLifecycle = $this->countsBy(Asset::query(), 'lifecycle_status');
        $byCondition = $this->countsBy(Asset::query(), 'condition');

        $assetsByLifecycle = [];
        foreach (Asset::LIFECYCLE_STATUSES as $status) {
            $assetsByLifecycle[$status] = $byLifecycle[$status] ?? 0;
        }

        $assetsByCondition = [];
        foreach (Asset::CONDITIONS as $condition) {
            $assetsByCondition[$condition] = $byCondition[$condition] ?? 0;
        }

        $loanCounts = $this->countsBy(
            Loan::query()->whereIn('status', [
                Loan::STATUS_REQUESTED,
                Loan::STATUS_APPROVED,
                Loan::STATUS_CHECKED_OUT,
            ]),
            'status',
        );

        $memberCounts = $this->countsBy(
            Membership::query()->where('status', Membership::STATUS_ACTIVE),
            'role',
        );

        return [
            'generated_at' => now()->toIso8601String(),
            'assets' => [
                'total' => Asset::query()->count(),
                'by_lifecycle' => $assetsByLifecycle,
                'by_condition' => $assetsByCondition,
                'problematic' => $this->problematicQuery()->count(),
            ],
            'loans' => [
                'requested' => $loanCounts[Loan::STATUS_REQUESTED] ?? 0,
                'approved' => $loanCounts[Loan::STATUS_APPROVED] ?? 0,
                'checked_out' => $loanCounts[Loan::STATUS_CHECKED_OUT] ?? 0,
                'overdue' => Loan::query()
                    ->where('status', Loan::STATUS_CHECKED_OUT)
                    ->where('due_at', '<', now())
                    ->count(),
            ],
            'maintenance' => [
                'open_issues' => Issue::query()->whereIn('status', self::OPEN_ISSUE_STATUSES)->count(),
                'open_tickets' => MaintenanceTicket::query()
                    ->whereIn('status', MaintenanceTicket::BLOCKING_STATUSES)
                    ->count(),
            ],
            'members' => [
                'borrower' => $memberCounts[Membership::ROLE_BORROWER] ?? 0,
                'operator' => $memberCounts[Membership::ROLE_OPERATOR] ?? 0,
                'admin' => $memberCounts[Membership::ROLE_ADMIN] ?? 0,
                'active_total' => array_sum($memberCounts),
            ],
            'problematic_assets' => $this->problematicAssets(10),
        ];
    }

    /**
     * Aset paling sering dipinjam dalam periode opsional.
     *
     * @return list<array<string, mixed>>
     */
    public function utilization(?Carbon $from = null, ?Carbon $to = null, int $limit = self::DEFAULT_LIMIT): array
    {
        $rows = DB::table('loan_items')
            ->join('loans', 'loans.id', '=', 'loan_items.loan_id')
            ->join('assets', 'assets.id', '=', 'loan_items.asset_id')
            ->where('loans.organization_id', $this->context->id())
            ->whereIn('loans.status', self::BORROWED_STATUSES)
            ->when($from !== null, fn ($query) => $query->where('loans.created_at', '>=', $from))
            ->when($to !== null, fn ($query) => $query->where('loans.created_at', '<=', $to))
            ->groupBy('assets.id', 'assets.code', 'assets.name')
            ->select([
                'assets.id as asset_id',
                'assets.code',
                'assets.name',
                DB::raw('count(*) as loan_count'),
                DB::raw('max(loans.created_at) as last_borrowed_at'),
            ])
            ->orderByDesc('loan_count')
            ->orderBy('assets.name')
            ->limit($limit)
            ->get();

        return $rows
            ->values()
            ->map(fn (object $row, int $index): array => [
                'rank' => $index + 1,
                'asset_id' => (int) $row->asset_id,
                'code' => $row->code,
                'name' => $row->name,
                'loan_count' => (int) $row->loan_count,
                'last_borrowed_at' => $row->last_borrowed_at !== null
                    ? Carbon::parse($row->last_borrowed_at)->toIso8601String()
                    : null,
            ])
            ->all();
    }

    /**
     * Snapshot aset yang sedang bermasalah (kondisi atau lifecycle atau masih
     * punya ticket/issue terbuka).
     *
     * @return list<array<string, mixed>>
     */
    public function problematicAssets(int $limit = self::DEFAULT_LIMIT): array
    {
        return $this->problematicQuery()
            ->withCount([
                'maintenanceTickets as total_tickets_count',
                'maintenanceTickets as open_tickets_count' => fn (Builder $query) => $query
                    ->whereIn('status', MaintenanceTicket::BLOCKING_STATUSES),
                'issues as total_issues_count',
                'issues as open_issues_count' => fn (Builder $query) => $query
                    ->whereIn('status', self::OPEN_ISSUE_STATUSES),
            ])
            ->orderByDesc('open_tickets_count')
            ->orderByDesc('total_tickets_count')
            ->orderByDesc('open_issues_count')
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (Asset $asset): array => [
                'asset_id' => $asset->getKey(),
                'code' => $asset->code,
                'name' => $asset->name,
                'condition' => $asset->condition,
                'condition_label' => $asset->conditionLabel(),
                'lifecycle_status' => $asset->lifecycle_status,
                'lifecycle_label' => $asset->lifecycleLabel(),
                'open_tickets' => (int) $asset->open_tickets_count,
                'total_tickets' => (int) $asset->total_tickets_count,
                'open_issues' => (int) $asset->open_issues_count,
                'total_issues' => (int) $asset->total_issues_count,
            ])
            ->all();
    }

    /**
     * Rekap peminjaman per status dalam periode opsional.
     *
     * @return array<string, mixed>
     */
    public function loanReport(?Carbon $from = null, ?Carbon $to = null): array
    {
        $query = Loan::query()
            ->when($from !== null, fn (Builder $builder) => $builder->where('created_at', '>=', $from))
            ->when($to !== null, fn (Builder $builder) => $builder->where('created_at', '<=', $to));

        $counts = (clone $query)
            ->select('status', DB::raw('count(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($value): int => (int) $value)
            ->all();

        $byStatus = [];
        foreach (Loan::STATUSES as $status) {
            $byStatus[$status] = $counts[$status] ?? 0;
        }

        return [
            'total' => (clone $query)->count(),
            'by_status' => $byStatus,
            'overdue' => Loan::query()
                ->where('status', Loan::STATUS_CHECKED_OUT)
                ->where('due_at', '<', now())
                ->count(),
        ];
    }

    /**
     * @return Builder<Asset>
     */
    private function problematicQuery(): Builder
    {
        return Asset::query()->where(function (Builder $query): void {
            $query->whereIn('lifecycle_status', self::PROBLEM_LIFECYCLES)
                ->orWhereIn('condition', self::PROBLEM_CONDITIONS)
                ->orWhereHas(
                    'maintenanceTickets',
                    fn (Builder $tickets) => $tickets->whereIn('status', MaintenanceTicket::BLOCKING_STATUSES),
                )
                ->orWhereHas(
                    'issues',
                    fn (Builder $issues) => $issues->whereIn('status', self::OPEN_ISSUE_STATUSES),
                );
        });
    }

    /**
     * @param  Builder<*>  $query
     * @return array<string, int>
     */
    private function countsBy(Builder $query, string $column): array
    {
        return $query
            ->select($column, DB::raw('count(*) as aggregate'))
            ->groupBy($column)
            ->pluck('aggregate', $column)
            ->map(fn ($value): int => (int) $value)
            ->all();
    }
}
