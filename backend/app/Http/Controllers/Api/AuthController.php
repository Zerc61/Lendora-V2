<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Requests\Api\RegisterRequest;
use App\Http\Resources\OrganizationResource;
use App\Http\Resources\UserResource;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Autentikasi manual berbasis session. Tanpa Breeze, Jetstream, atau starter kit.
 * Registrasi publik ada, tetapi akun tidak aktif sampai admin menyetujui (OD-29).
 */
class AuthController extends Controller
{
    public const ACTIVE_ORGANIZATION_KEY = 'active_organization_id';

    public function register(RegisterRequest $request, AuditRecorder $audit): JsonResponse
    {
        $data = $request->validated();

        // Kode organisasi bersifat opsional: tanpa kode, akun dibuat pending
        // tanpa membership; admin mengaitkan ke organisasi lewat "Tambah anggota".
        $slug = $data['organization_slug'] ?? null;

        $organization = $slug === null
            ? null
            : Organization::query()
                ->where('slug', $slug)
                ->where('status', Organization::STATUS_ACTIVE)
                ->firstOrFail();

        $user = DB::transaction(function () use ($data, $organization, $audit): User {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'status' => User::STATUS_PENDING,
            ]);

            if ($organization !== null) {
                Membership::create([
                    'organization_id' => $organization->getKey(),
                    'user_id' => $user->getKey(),
                    'role' => Membership::ROLE_BORROWER,
                    'status' => Membership::STATUS_INVITED,
                ]);
            }

            $audit->record(
                action: 'auth.registered',
                subject: $user,
                after: ['email' => $user->email, 'status' => $user->status],
                context: ['organization_id' => $organization?->getKey()],
                organization: $organization,
            );

            return $user;
        });

        return response()->json([
            'message' => 'Pendaftaran berhasil. Akun menunggu aktivasi admin.',
            'data' => new UserResource($user),
        ], 201);
    }

    public function login(LoginRequest $request, AuditRecorder $audit): JsonResponse
    {
        $credentials = $request->validated();

        $user = User::query()->where('email', $credentials['email'])->first();

        if ($user === null || ! Hash::check($credentials['password'], $user->password)) {
            $audit->record('auth.login_failed', context: ['email' => $credentials['email']]);

            throw ValidationException::withMessages([
                'email' => 'Email atau kata sandi salah.',
            ]);
        }

        if (! $user->isActive()) {
            $audit->record('auth.login_blocked', $user, context: ['status' => $user->status], actor: $user);

            abort(403, $user->status === User::STATUS_PENDING
                ? 'Akun menunggu aktivasi admin.'
                : 'Akun tidak aktif.');
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->save();

        $memberships = $user->activeMemberships()->with('organization')->orderBy('id')->get();
        $membership = $memberships->first();

        if ($membership !== null) {
            $request->session()->put(self::ACTIVE_ORGANIZATION_KEY, $membership->organization_id);
        }

        $audit->record('auth.login_succeeded', $user, organization: $membership?->organization);

        return response()->json([
            'data' => new UserResource($user),
            'organizations' => OrganizationResource::collection(
                $memberships->pluck('organization')->filter()->values()
            ),
            'active_organization_id' => $membership?->organization_id,
            'role' => $membership?->role,
        ]);
    }

    public function logout(Request $request, AuditRecorder $audit): JsonResponse
    {
        $user = $request->user();

        if ($user !== null) {
            $audit->record('auth.logout', $user);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Berhasil keluar.']);
    }

    public function me(Request $request, OrganizationContext $context): JsonResponse
    {
        $user = $request->user();

        $memberships = $user->activeMemberships()->with('organization')->get();

        return response()->json([
            'data' => new UserResource($user),
            'organizations' => OrganizationResource::collection(
                $memberships->pluck('organization')->filter()->values()
            ),
            'active_organization_id' => $context->id(),
            'role' => $context->role(),
        ]);
    }

    public function switchOrganization(Request $request): JsonResponse
    {
        $data = $request->validate([
            'organization_id' => ['required', 'integer'],
        ]);

        $membership = $request->user()
            ->activeMemberships()
            ->where('organization_id', $data['organization_id'])
            ->first();

        if ($membership === null) {
            // Bukan anggota: 404, keberadaan organisasi tidak dikonfirmasi.
            abort(404);
        }

        $request->session()->put(self::ACTIVE_ORGANIZATION_KEY, $membership->organization_id);

        return response()->json([
            'message' => 'Organisasi aktif diganti.',
            'active_organization_id' => $membership->organization_id,
            'role' => $membership->role,
        ]);
    }
}
