<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Pusat notifikasi in-app per user.
 *
 * Notifikasi dimiliki user lintas organisasi (lihat migration notifications),
 * jadi route-nya hanya butuh `auth` dan seluruh query berjalan di atas
 * `notifications milik user login` — user lain tidak pernah terbaca, termasuk
 * lewat ID asing.
 */
class NotificationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $request->user()->notifications()->latest();

        if ($request->boolean('unread')) {
            $query->whereNull('read_at');
        }

        return NotificationResource::collection($query->paginate(10)->withQueryString());
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'count' => $request->user()->notifications()->whereNull('read_at')->count(),
        ]);
    }

    public function markRead(Request $request, string $notification): JsonResponse
    {
        $model = $this->findOwned($request, $notification);

        if ($model->read_at === null) {
            $model->markAsRead();
        }

        return (new NotificationResource($model))->response();
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $updated = $request->user()
            ->notifications()
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'updated' => $updated,
            'count' => 0,
        ]);
    }

    /**
     * Cari notifikasi milik user login saja; ID asing berujung 404, bukan 403,
     * supaya keberadaan notifikasi orang lain tidak bocor.
     */
    private function findOwned(Request $request, string $id)
    {
        return $request->user()->notifications()->findOrFail($id);
    }
}
