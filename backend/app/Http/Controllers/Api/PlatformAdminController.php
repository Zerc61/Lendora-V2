<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlatformProviderResource;
use App\Http\Resources\PlatformUserResource;
use App\Models\Organization;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Administrasi tingkat platform. Peran dari `users.platform_role`, terpisah dari
 * peran membership, sehingga bekerja lintas organisasi.
 *
 * Dua tindakan: aktivasi akun publik (customer mendaftar tanpa organisasi dan
 * harus diaktifkan) dan verifikasi provider (hanya provider terverifikasi yang
 * tampil di katalog publik).
 */
class PlatformAdminController extends Controller
{
    public function users(Request $request): AnonymousResourceCollection
    {
        $this->assertPlatformAdmin($request);

        $query = User::query();

        $status = $request->query('status');

        if (is_string($status) && in_array($status, User::STATUSES, true)) {
            $query->where('status', $status);
        }

        $term = trim((string) $request->query('q'));

        if ($term !== '') {
            $query->where(function ($query) use ($term): void {
                $query->where('name', 'ilike', '%'.$term.'%')
                    ->orWhere('email', 'ilike', '%'.$term.'%');
            });
        }

        return PlatformUserResource::collection(
            $query->orderByDesc('id')->paginate(24)->withQueryString()
        );
    }

    public function updateUserStatus(Request $request, int $user, AuditRecorder $audit): PlatformUserResource
    {
        $this->assertPlatformAdmin($request);

        $data = $request->validate([
            'status' => ['required', Rule::in([User::STATUS_ACTIVE, User::STATUS_INACTIVE])],
        ]);

        $model = User::query()->findOrFail($user);

        return DB::transaction(function () use ($model, $data, $audit): PlatformUserResource {
            $before = ['status' => $model->status];

            $model->status = $data['status'];
            $model->save();

            // Menonaktifkan akun harus langsung memutus sesi berjalan, sama seperti
            // menonaktifkan membership.
            if ($model->status === User::STATUS_INACTIVE) {
                DB::table('sessions')->where('user_id', $model->getKey())->delete();
            }

            $audit->record(
                action: 'platform.user_status_updated',
                subject: $model,
                before: $before,
                after: ['status' => $model->status],
            );

            return new PlatformUserResource($model);
        });
    }

    public function providers(Request $request): AnonymousResourceCollection
    {
        $this->assertPlatformAdmin($request);

        $query = Organization::query();

        $status = $request->query('verification_status');

        if (is_string($status) && in_array($status, Organization::VERIFICATION_STATUSES, true)) {
            $query->where('verification_status', $status);
        }

        $term = trim((string) $request->query('q'));

        if ($term !== '') {
            $query->where(function ($query) use ($term): void {
                $query->where('name', 'ilike', '%'.$term.'%')
                    ->orWhere('slug', 'ilike', '%'.$term.'%');
            });
        }

        return PlatformProviderResource::collection(
            $query->orderByDesc('id')->paginate(24)->withQueryString()
        );
    }

    public function updateProviderVerification(Request $request, int $organization, AuditRecorder $audit): PlatformProviderResource
    {
        $this->assertPlatformAdmin($request);

        $data = $request->validate([
            'verification_status' => ['required', Rule::in(Organization::VERIFICATION_STATUSES)],
        ]);

        $model = Organization::query()->findOrFail($organization);

        return DB::transaction(function () use ($model, $data, $audit): PlatformProviderResource {
            $before = ['verification_status' => $model->verification_status];

            $model->verification_status = $data['verification_status'];
            $model->save();

            $audit->record(
                action: 'platform.provider_verification_updated',
                subject: $model,
                before: $before,
                after: ['verification_status' => $model->verification_status],
                organization: $model,
            );

            return new PlatformProviderResource($model);
        });
    }

    private function assertPlatformAdmin(Request $request): void
    {
        abort_unless($request->user()?->isPlatformAdmin() === true, 403);
    }
}
