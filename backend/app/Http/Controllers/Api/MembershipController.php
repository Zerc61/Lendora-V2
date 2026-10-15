<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpdateMembershipRequest;
use App\Http\Resources\MembershipResource;
use App\Models\Membership;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Resource Membership. Isolasi lintas organisasi dijamin global scope pada model,
 * sehingga ID milik organisasi lain tidak ditemukan (404).
 *
 * Binding model tidak dipakai karena SubstituteBindings berjalan sebelum konteks
 * organisasi terbentuk; findOrFail di dalam controller berjalan setelah middleware
 * tenant, saat scope sudah fail-closed pada organisasi aktif.
 */
class MembershipController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Membership::class);

        $data = $request->validate([
            'role' => ['nullable', Rule::in(array_keys(Membership::ROLE_LABELS))],
            'status' => ['nullable', Rule::in(array_keys(Membership::STATUS_LABELS))],
            'q' => ['nullable', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $memberships = Membership::query()
            ->with('user')
            ->when($data['role'] ?? null, fn ($query, $role) => $query->where('role', $role))
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['q'] ?? null, fn ($query, $search) => $query->whereHas(
                'user',
                fn ($user) => $user
                    ->where('name', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%"),
            ))
            ->orderBy('id')
            ->paginate($data['limit'] ?? 25);

        return MembershipResource::collection($memberships);
    }

    /**
     * Tambah anggota: admin mendaftarkan user yang sudah punya akun, langsung
     * aktif. Pengguna baru tanpa akun diminta mendaftar mandiri dulu.
     */
    public function store(Request $request, AuditRecorder $audit): MembershipResource
    {
        $this->authorize('create', Membership::class);

        $data = $request->validate([
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255'],
            'role' => ['required', Rule::in(array_keys(Membership::ROLE_LABELS))],
        ]);

        $user = User::query()->where('email', $data['email'])->first();

        if ($user === null) {
            throw ValidationException::withMessages([
                'email' => 'Belum ada akun dengan email ini. Minta ia mendaftar sendiri, lalu tambahkan lagi.',
            ]);
        }

        $context = app(OrganizationContext::class);

        $existing = Membership::query()->where('user_id', $user->getKey())->first();

        if ($existing !== null) {
            throw ValidationException::withMessages([
                'email' => 'User ini sudah menjadi anggota organisasi.',
            ]);
        }

        $membership = Membership::create([
            'organization_id' => $context->id(),
            'user_id' => $user->getKey(),
            'role' => $data['role'],
            'status' => Membership::STATUS_ACTIVE,
            'joined_at' => now(),
            'invited_by' => $request->user()->getKey(),
        ]);

        // Akunnya masih pending (OD-29): penambahan eksplisit oleh admin
        // sekaligus mengaktifkan akunnya.
        $this->activatePendingUser($membership, $audit);

        $audit->record(
            action: 'membership.created',
            subject: $membership,
            before: [],
            after: $membership->only(['role', 'status']),
            organization: $membership->organization,
        );

        return new MembershipResource($membership->load('user'));
    }

    public function show(int $membership): MembershipResource
    {
        $model = Membership::query()->findOrFail($membership);

        $this->authorize('view', $model);

        return new MembershipResource($model->load('user'));
    }

    public function update(
        UpdateMembershipRequest $request,
        int $membership,
        AuditRecorder $audit,
    ): MembershipResource {
        $model = Membership::query()->findOrFail($membership);

        $this->authorize('update', $model);

        $data = $request->validated();
        $before = $model->only(['role', 'status']);

        DB::transaction(function () use ($model, $data, $audit, $before): void {
            if (array_key_exists('role', $data)) {
                $model->role = $data['role'];
            }

            if (array_key_exists('status', $data)) {
                $model->status = $data['status'];

                if ($data['status'] === Membership::STATUS_ACTIVE && $model->joined_at === null) {
                    $model->joined_at = now();
                }
            }

            $model->save();

            if ($model->status === Membership::STATUS_ACTIVE) {
                $this->activatePendingUser($model, $audit);
            } elseif ($model->status === Membership::STATUS_INACTIVE) {
                // Keanggotaan nonaktif: sesi user tersebut dihentikan. ADR-004 aturan 6.
                DB::table('sessions')->where('user_id', $model->user_id)->delete();
            }

            $audit->record(
                action: 'membership.updated',
                subject: $model,
                before: $before,
                after: $model->only(['role', 'status']),
                organization: $model->organization,
            );
        });

        return new MembershipResource($model->load('user'));
    }

    /**
     * Lepas anggota dari organisasi. Sesi user dihentikan; akunnya tetap ada
     * dan bisa ditambahkan kembali ke organisasi lain.
     */
    public function destroy(int $membership, AuditRecorder $audit): JsonResponse
    {
        $model = Membership::query()->findOrFail($membership);

        $this->authorize('delete', $model);

        // Organisasi wajib menyisakan setidaknya satu admin aktif.
        if ($model->hasRole(Membership::ROLE_ADMIN) && $model->isActive()) {
            $remainingAdmins = Membership::query()
                ->where('role', Membership::ROLE_ADMIN)
                ->where('status', Membership::STATUS_ACTIVE)
                ->whereKeyNot($model->getKey())
                ->count();

            abort_if($remainingAdmins === 0, 422, 'Admin terakhir tidak bisa dihapus.');
        }

        DB::table('sessions')->where('user_id', $model->user_id)->delete();

        $before = $model->only(['role', 'status']);
        $model->delete();

        $audit->record(
            action: 'membership.removed',
            subject: $model,
            before: $before,
            after: [],
            organization: $model->organization,
        );

        return response()->json([
            'message' => 'Anggota dihapus dari organisasi.',
        ]);
    }

    /**
     * Reset password oleh admin: nilai baru hanya dikirim sekali di respons
     * (tidak dikirim ke mana pun), sesi user dihentikan agar login ulang.
     */
    public function resetPassword(int $membership, AuditRecorder $audit): JsonResponse
    {
        $model = Membership::query()->findOrFail($membership);

        $this->authorize('update', $model);

        $user = $model->user ?? User::query()->findOrFail($model->user_id);
        $password = Str::password(16);

        $user->update(['password' => $password]);
        DB::table('sessions')->where('user_id', $user->getKey())->delete();

        $audit->record(
            action: 'user.password_reset',
            subject: $user,
            before: [],
            after: [],
            organization: $model->organization,
        );

        return response()->json([
            'message' => 'Password baru dibuat. Sampaikan langsung ke pengguna; nilai hanya tampil sekali ini.',
            'password' => $password,
        ]);
    }

    private function activatePendingUser(Membership $membership, AuditRecorder $audit): void
    {
        $user = $membership->user ?? User::query()->find($membership->user_id);

        if ($user !== null && $user->status === User::STATUS_PENDING) {
            $user->forceFill(['status' => User::STATUS_ACTIVE])->save();

            $audit->record(
                action: 'user.activated',
                subject: $user,
                before: ['status' => User::STATUS_PENDING],
                after: ['status' => User::STATUS_ACTIVE],
                organization: $membership->organization,
            );
        }
    }
}
