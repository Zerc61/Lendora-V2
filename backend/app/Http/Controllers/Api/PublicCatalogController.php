<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicEquipmentResource;
use App\Http\Resources\PublicProviderResource;
use App\Models\Asset;
use App\Models\Organization;
use App\Services\Lending\AvailabilityService;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

/**
 * Katalog publik. Baca saja, tanpa autentikasi. Konteks tenant diisi middleware
 * ResolveProviderContext, sehingga global scope membatasi setiap kueri ke provider
 * yang diminta dan tidak ada data provider lain yang bisa terbaca.
 *
 * Data yang tampil dibatasi resource daftar-putih. Ketersediaan hanya
 * mengembalikan status bisa/tidak, tanpa identitas peminjam.
 */
class PublicCatalogController extends Controller
{
    public function __construct(private OrganizationContext $context) {}

    public function providers(Request $request): AnonymousResourceCollection
    {
        $query = Organization::query()->publiclyVisible();

        $term = trim((string) $request->query('q'));

        if ($term !== '') {
            $query->where('name', 'ilike', '%'.$term.'%');
        }

        return PublicProviderResource::collection(
            $query->orderBy('name')->paginate(24)->withQueryString()
        );
    }

    public function provider(): PublicProviderResource
    {
        return new PublicProviderResource($this->context->organization());
    }

    public function equipment(Request $request): AnonymousResourceCollection
    {
        $query = Asset::query()
            ->where('lifecycle_status', Asset::LIFECYCLE_ACTIVE)
            ->with('category');

        if ($categoryId = $request->integer('category_id')) {
            $query->where('category_id', $categoryId);
        }

        $term = trim((string) $request->query('q'));

        if ($term !== '') {
            $query->search($term);
        }

        return PublicEquipmentResource::collection(
            $query->orderBy('name')->paginate(24)->withQueryString()
        );
    }

    public function equipmentShow(Request $request): PublicEquipmentResource
    {
        $model = Asset::query()
            ->where('lifecycle_status', Asset::LIFECYCLE_ACTIVE)
            ->with('category')
            ->findOrFail($this->assetId($request));

        return new PublicEquipmentResource($model);
    }

    public function availability(Request $request, AvailabilityService $availability): JsonResponse
    {
        $data = $request->validate([
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
        ]);

        $model = Asset::query()->findOrFail($this->assetId($request));

        $result = $availability->evaluate(
            [$model->id],
            Carbon::parse($data['starts_at']),
            Carbon::parse($data['ends_at']),
        );

        return response()->json([
            'data' => [
                'requestable' => $result['requestable'],
                'reasons' => $result['reasons'],
                'assets' => $result['assets'],
            ],
        ]);
    }

    /**
     * Route memuat dua parameter (`provider` dan `asset`), sedangkan penyelesaian
     * argumen controller bersifat posisional, sehingga type-hint `int` pada
     * parameter kedua akan menerima slug. Id dibaca eksplisit dari route.
     */
    private function assetId(Request $request): int
    {
        return (int) $request->route('asset');
    }
}
