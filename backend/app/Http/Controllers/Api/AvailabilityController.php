<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Services\Lending\AvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Menjawab "apakah aset X bisa dipakai pada periode Y?" (Availability Rules).
 * Hasil dihitung saat itu juga, tidak di-cache.
 */
class AvailabilityController extends Controller
{
    public function show(Request $request, int $asset, AvailabilityService $availability): JsonResponse
    {
        $model = Asset::query()->findOrFail($asset);

        $this->authorize('view', $model);

        $data = $request->validate([
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
        ]);

        $start = Carbon::parse($data['starts_at']);
        $end = Carbon::parse($data['ends_at']);

        $result = $availability->evaluate([$model->getKey()], $start, $end, $request->user());

        $blocking = $availability->blockingPeriods($model->getKey(), $start, $end)->map(fn ($reservation): array => [
            'starts_at' => $reservation->starts_at?->toIso8601String(),
            'ends_at' => $reservation->ends_at?->toIso8601String(),
            'status' => $reservation->status,
            'borrower' => $reservation->loan?->borrower?->name,
        ])->all();

        return response()->json([
            'data' => [
                'asset_id' => $model->getKey(),
                'requestable' => $result['requestable'],
                'reasons' => $result['reasons'],
                'asset' => $result['assets'][0] ?? null,
                'blocking' => $blocking,
            ],
        ]);
    }
}
