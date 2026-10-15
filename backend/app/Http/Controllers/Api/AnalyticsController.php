<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ExportReportRequest;
use App\Http\Requests\Api\ReportFilterRequest;
use App\Models\Membership;
use App\Services\Analytics\AnalyticsService;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Laporan analitik (Fase 9): utilisasi aset, kondisi aset bermasalah, rekap
 * peminjaman, dan ekspor CSV. Semua endpoint admin-only.
 */
class AnalyticsController extends Controller
{
    public function utilization(ReportFilterRequest $request, AnalyticsService $analytics): JsonResponse
    {
        $this->authorizeAdmin();

        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'period' => $request->period(),
            'data' => $analytics->utilization($request->from(), $request->to(), $request->limit()),
        ]);
    }

    public function problems(ReportFilterRequest $request, AnalyticsService $analytics): JsonResponse
    {
        $this->authorizeAdmin();

        return response()->json([
            'generated_at' => now()->toIso8601String(),
            // Laporan kondisi adalah snapshot keadaan sekarang, bukan periode.
            'snapshot' => true,
            'data' => $analytics->problematicAssets($request->limit()),
        ]);
    }

    public function loans(ReportFilterRequest $request, AnalyticsService $analytics): JsonResponse
    {
        $this->authorizeAdmin();

        return response()->json(array_merge(
            [
                'generated_at' => now()->toIso8601String(),
                'period' => $request->period(),
            ],
            $analytics->loanReport($request->from(), $request->to()),
        ));
    }

    public function export(ExportReportRequest $request, AnalyticsService $analytics): StreamedResponse
    {
        $this->authorizeAdmin();

        [$headers, $rows, $filename] = $this->exportPayload($request, $analytics);

        return response()->streamDownload(function () use ($headers, $rows): void {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, $headers);

            foreach ($rows as $row) {
                fputcsv($handle, array_map(
                    fn ($value): string => $value === null ? '' : (string) $value,
                    $row,
                ));
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    private function authorizeAdmin(): void
    {
        abort_unless(
            app(OrganizationContext::class)->hasRole(Membership::ROLE_ADMIN),
            403,
        );
    }

    /**
     * @return array{0: list<string>, 1: list<list<scalar>>, 2: string}
     */
    private function exportPayload(ExportReportRequest $request, AnalyticsService $analytics): array
    {
        $limit = AnalyticsService::EXPORT_LIMIT;
        $from = $request->from();
        $to = $request->to();

        return match ($request->validated('report')) {
            'utilization' => [
                ['rank', 'asset_id', 'code', 'name', 'loan_count', 'last_borrowed_at'],
                array_map(fn (array $row): array => [
                    $row['rank'],
                    $row['asset_id'],
                    $row['code'],
                    $row['name'],
                    $row['loan_count'],
                    $row['last_borrowed_at'],
                ], $analytics->utilization($from, $to, $limit)),
                'utilisasi-aset.csv',
            ],
            'problems' => [
                ['asset_id', 'code', 'name', 'condition', 'lifecycle_status', 'open_tickets', 'total_tickets', 'open_issues', 'total_issues'],
                array_map(fn (array $row): array => [
                    $row['asset_id'],
                    $row['code'],
                    $row['name'],
                    $row['condition'],
                    $row['lifecycle_status'],
                    $row['open_tickets'],
                    $row['total_tickets'],
                    $row['open_issues'],
                    $row['total_issues'],
                ], $analytics->problematicAssets($limit)),
                'aset-bermasalah.csv',
            ],
            'loans' => [
                ['status', 'count'],
                $this->loanExportRows($analytics->loanReport($from, $to)),
                'rekap-peminjaman.csv',
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<list<scalar>>
     */
    private function loanExportRows(array $report): array
    {
        $rows = [];

        foreach ($report['by_status'] as $status => $count) {
            $rows[] = [$status, (int) $count];
        }

        $rows[] = ['total', (int) $report['total']];
        $rows[] = ['overdue', (int) $report['overdue']];

        return $rows;
    }
}
