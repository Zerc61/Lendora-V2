<?php

namespace Database\Seeders;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Data minimum untuk development dan pengujian Foundation.
 * Password semua akun contoh: `password`. Ganti di luar development.
 */
class FoundationSeeder extends Seeder
{
    public function run(): void
    {
        $headOffice = Organization::firstOrCreate(
            ['slug' => 'kantor-pusat'],
            ['name' => 'Kantor Pusat Nusantara', 'status' => Organization::STATUS_ACTIVE],
        );

        $library = Organization::firstOrCreate(
            ['slug' => 'perpustakaan-kota'],
            ['name' => 'Perpustakaan Kota', 'status' => Organization::STATUS_ACTIVE],
        );

        $admin = $this->user('Admin Utama', 'admin@lendora.test');
        $operator = $this->user('Operator Gudang', 'operator@lendora.test');
        $borrower = $this->user('Rina Peminjam', 'borrower@lendora.test');
        $otherAdmin = $this->user('Sari Admin Kota', 'admin.kota@lendora.test');
        $pending = $this->user('Dimas Menunggu', 'pending@lendora.test', User::STATUS_PENDING);

        $this->membership($headOffice, $admin, Membership::ROLE_ADMIN);
        $this->membership($headOffice, $operator, Membership::ROLE_OPERATOR);
        $this->membership($headOffice, $borrower, Membership::ROLE_BORROWER);
        $this->membership($library, $otherAdmin, Membership::ROLE_ADMIN);

        // Menunggu aktivasi admin. Lihat OD-29.
        Membership::firstOrCreate(
            ['organization_id' => $headOffice->getKey(), 'user_id' => $pending->getKey()],
            [
                'role' => Membership::ROLE_BORROWER,
                'status' => Membership::STATUS_INVITED,
                'joined_at' => null,
            ],
        );
    }

    private function user(string $name, string $email, string $status = User::STATUS_ACTIVE): User
    {
        return User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => 'password',
                'status' => $status,
                'email_verified_at' => now(),
            ],
        );
    }

    private function membership(Organization $organization, User $user, string $role): Membership
    {
        return Membership::firstOrCreate(
            ['organization_id' => $organization->getKey(), 'user_id' => $user->getKey()],
            [
                'role' => $role,
                'status' => Membership::STATUS_ACTIVE,
                'joined_at' => now(),
            ],
        );
    }
}
