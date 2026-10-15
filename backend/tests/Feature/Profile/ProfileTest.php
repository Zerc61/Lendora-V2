<?php

namespace Tests\Feature\Profile;

use App\Models\Membership;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TenantTestCase;

/**
 * Foto profil tersimpan di disk publik, identitas lengkap borrower bisa
 * diperbarui dengan validasi, dan ganti password wajib password lama.
 */
class ProfileTest extends TenantTestCase
{
    public function test_guest_cannot_upload_avatar(): void
    {
        $this->postJson('/api/profile/avatar')->assertUnauthorized();
    }

    public function test_user_can_upload_avatar(): void
    {
        Storage::fake('public');

        $user = $this->member(Membership::ROLE_BORROWER);
        $file = UploadedFile::fake()->image('avatar.png');

        $payload = $this->actingAs($user)
            ->postJson('/api/profile/avatar', ['avatar' => $file])
            ->assertOk()
            ->json();

        $user->refresh();

        $this->assertNotNull($payload['data']['avatar_url']);
        $this->assertNotNull($user->avatar_path);
        Storage::disk('public')->assertExists($user->avatar_path);
    }

    public function test_upload_replaces_previous_avatar(): void
    {
        Storage::fake('public');

        $user = $this->member(Membership::ROLE_BORROWER);

        $this->actingAs($user)
            ->postJson('/api/profile/avatar', ['avatar' => UploadedFile::fake()->image('old.png')])
            ->assertOk();

        $oldPath = $user->fresh()->avatar_path;

        $this->actingAs($user)
            ->postJson('/api/profile/avatar', ['avatar' => UploadedFile::fake()->image('new.jpg')])
            ->assertOk();

        $this->assertNotSame($oldPath, $user->fresh()->avatar_path);
        Storage::disk('public')->assertMissing($oldPath);
    }

    public function test_upload_rejects_non_image(): void
    {
        Storage::fake('public');

        $user = $this->member(Membership::ROLE_BORROWER);

        $this->actingAs($user)
            ->postJson('/api/profile/avatar', [
                'avatar' => UploadedFile::fake()->create('notes.pdf', 10),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('avatar');

        $this->assertNull($user->fresh()->avatar_path);
    }

    public function test_guest_cannot_update_identity(): void
    {
        $this->patchJson('/api/profile')->assertUnauthorized();
    }

    public function test_user_can_update_full_identity(): void
    {
        $user = $this->member(Membership::ROLE_BORROWER);

        $payload = $this->actingAs($user)
            ->patchJson('/api/profile', [
                'name' => 'Borrower Lengkap',
                'phone' => '081234567890',
                'student_id' => '2024100001',
                'city' => 'Yogyakarta',
                'education_status' => User::EDUCATION_MAHASISWA,
                'address' => 'Jl. Kaliurang KM 5, Gg. Melati No. 12',
                'birth_place' => 'Sleman',
                'birth_date' => '2005-05-12',
                'emergency_contact_name' => 'Orang Tua',
                'emergency_contact_phone' => '081200001111',
            ])
            ->assertOk()
            ->json();

        $user->refresh();

        $this->assertSame('Borrower Lengkap', $user->name);
        $this->assertSame('2024100001', $user->student_id);
        $this->assertSame('Yogyakarta', $user->city);
        $this->assertSame(User::EDUCATION_MAHASISWA, $user->education_status);
        $this->assertSame('Jl. Kaliurang KM 5, Gg. Melati No. 12', $user->address);
        $this->assertSame('081200001111', $user->emergency_contact_phone);
        $this->assertSame('Mahasiswa', $payload['data']['education_status_label']);
        $this->assertSame('2005-05-12', $payload['data']['birth_date']);
    }

    public function test_identity_rejects_unknown_education_status(): void
    {
        $user = $this->member(Membership::ROLE_BORROWER);

        $this->actingAs($user)
            ->patchJson('/api/profile', ['name' => 'X', 'education_status' => 'doktor'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('education_status');
    }

    public function test_identity_rejects_birth_date_in_the_future(): void
    {
        $user = $this->member(Membership::ROLE_BORROWER);

        $this->actingAs($user)
            ->patchJson('/api/profile', ['name' => 'X', 'birth_date' => now()->addDay()->toDateString()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('birth_date');
    }

    public function test_password_requires_correct_current_password(): void
    {
        $user = $this->member(Membership::ROLE_BORROWER);

        $this->actingAs($user)
            ->postJson('/api/profile/password', [
                'current_password' => 'salah-satu',
                'password' => 'kata-baru-yang-panjang',
                'password_confirmation' => 'kata-baru-yang-panjang',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');
    }

    public function test_password_confirmation_must_match(): void
    {
        $user = $this->member(Membership::ROLE_BORROWER);

        $this->actingAs($user)
            ->postJson('/api/profile/password', [
                'current_password' => 'password',
                'password' => 'kata-baru-yang-panjang',
                'password_confirmation' => 'beda-total',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_user_can_change_password(): void
    {
        $user = $this->member(Membership::ROLE_BORROWER);

        $this->actingAs($user)
            ->postJson('/api/profile/password', [
                'current_password' => 'password',
                'password' => 'kata-baru-yang-panjang',
                'password_confirmation' => 'kata-baru-yang-panjang',
            ])
            ->assertOk();

        $user->refresh();

        $this->assertTrue(Hash::check('kata-baru-yang-panjang', $user->password));
        $this->assertFalse(Hash::check('password', $user->password));
    }
}
