<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Profil user login: foto, identitas lengkap, dan ganti password. Bersifat
 * lintas organisasi karena menyangkut identitas user, bukan data tenant.
 */
class ProfileController extends Controller
{
    public function updateAvatar(Request $request): JsonResponse
    {
        $request->validate([
            'avatar' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $user = $request->user();
        $path = $request->file('avatar')->store('avatars', 'public');

        if ($user->avatar_path !== null) {
            Storage::disk('public')->delete($user->avatar_path);
        }

        $user->update(['avatar_path' => $path]);

        return response()->json([
            'data' => ['avatar_url' => Storage::disk('public')->url($path)],
        ]);
    }

    /**
     * Update identitas lengkap. Email sengaja tidak menerima perubahan di sini
     * karena menjadi kredensial login.
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'student_id' => ['nullable', 'string', 'max:24'],
            'city' => ['nullable', 'string', 'max:80'],
            'education_status' => ['nullable', Rule::in(User::EDUCATION_STATUSES)],
            'address' => ['nullable', 'string', 'max:500'],
            'birth_place' => ['nullable', 'string', 'max:80'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'emergency_contact_name' => ['nullable', 'string', 'max:100'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:32'],
        ]);

        $request->user()->update($data);

        return response()->json([
            'message' => 'Identitas diperbarui.',
            'data' => new UserResource($request->user()->fresh()),
        ]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        // Cast 'hashed' di model yang meng-hash; pola sama dengan register.
        $request->user()->update(['password' => $data['password']]);

        return response()->json([
            'message' => 'Password berhasil diganti.',
        ]);
    }
}
