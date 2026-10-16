<?php

namespace Tests\Feature\Authorization;

use App\Models\Organization;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Administrasi platform. Peran dari `users.platform_role`, terpisah dari
 * membership, dan bekerja lintas organisasi.
 */
class PlatformAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_platform_user_cannot_list_users(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/platform/users')
            ->assertForbidden();
    }

    public function test_non_platform_user_cannot_verify_provider(): void
    {
        $organization = Organization::factory()->create();

        $this->actingAs(User::factory()->create())
            ->patchJson('/api/platform/providers/'.$organization->getKey().'/verification', [
                'verification_status' => Organization::VERIFICATION_VERIFIED,
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('organizations', [
            'id' => $organization->getKey(),
            'verification_status' => Organization::VERIFICATION_PENDING,
        ]);
    }

    public function test_admin_lists_users_filtered_by_status(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        User::factory()->create(['status' => User::STATUS_PENDING]);
        $activated = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $this->actingAs($admin)
            ->getJson('/api/platform/users?status='.User::STATUS_ACTIVE)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $activated->getKey());
    }

    public function test_admin_activates_pending_user_and_it_is_audited(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $pending = User::factory()->create(['status' => User::STATUS_PENDING]);

        $this->actingAs($admin)
            ->patchJson('/api/platform/users/'.$pending->getKey().'/status', [
                'status' => User::STATUS_ACTIVE,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', User::STATUS_ACTIVE);

        $this->assertSame(User::STATUS_ACTIVE, $pending->fresh()->status);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'platform.user_status_updated',
            'subject_id' => $pending->getKey(),
        ]);
    }

    public function test_admin_deactivation_revokes_sessions(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $target = User::factory()->create();

        DB::table('sessions')->insert([
            'id' => 'session-id',
            'user_id' => $target->getKey(),
            'payload' => 'payload',
            'last_activity' => time(),
        ]);

        $this->actingAs($admin)
            ->patchJson('/api/platform/users/'.$target->getKey().'/status', [
                'status' => User::STATUS_INACTIVE,
            ])
            ->assertOk();

        $this->assertSame(User::STATUS_INACTIVE, $target->fresh()->status);
        $this->assertDatabaseMissing('sessions', ['user_id' => $target->getKey()]);
    }

    public function test_unknown_user_status_is_rejected(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $target = User::factory()->create();

        $this->actingAs($admin)
            ->patchJson('/api/platform/users/'.$target->getKey().'/status', ['status' => 'dihapus'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_admin_verifies_provider_and_it_is_audited(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $organization = Organization::factory()->create();

        $this->actingAs($admin)
            ->patchJson('/api/platform/providers/'.$organization->getKey().'/verification', [
                'verification_status' => Organization::VERIFICATION_VERIFIED,
            ])
            ->assertOk();

        $this->assertSame(Organization::VERIFICATION_VERIFIED, $organization->fresh()->verification_status);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'platform.provider_verification_updated',
            'organization_id' => $organization->getKey(),
        ]);
    }

    public function test_unknown_verification_status_is_rejected(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $organization = Organization::factory()->create();

        $this->actingAs($admin)
            ->patchJson('/api/platform/providers/'.$organization->getKey().'/verification', [
                'verification_status' => 'entah',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['verification_status']);
    }

    public function test_non_platform_user_cannot_list_providers(): void
    {
        Organization::factory()->create();

        $this->actingAs(User::factory()->create())
            ->getJson('/api/platform/providers')
            ->assertForbidden();
    }

    public function test_admin_lists_providers_including_unverified_ones(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $verified = Organization::factory()->verified()->create();
        $pending = Organization::factory()->unverified()->create();

        $response = $this->actingAs($admin)
            ->getJson('/api/platform/providers')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $rows = collect($response->json('data'))->keyBy('id');

        // Daftar konsol memang harus memuat yang belum terverifikasi; justru itu
        // yang menunggu tindakan admin.
        $this->assertSame(Organization::VERIFICATION_VERIFIED, $rows[$verified->getKey()]['verification_status']);
        $this->assertTrue($rows[$verified->getKey()]['is_publicly_visible']);
        $this->assertSame(Organization::VERIFICATION_PENDING, $rows[$pending->getKey()]['verification_status']);
        $this->assertFalse($rows[$pending->getKey()]['is_publicly_visible']);
    }

    public function test_admin_filters_providers_by_verification_status(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        Organization::factory()->verified()->create();
        $pending = Organization::factory()->unverified()->create();

        $this->actingAs($admin)
            ->getJson('/api/platform/providers?verification_status='.Organization::VERIFICATION_PENDING)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $pending->getKey())
            ->assertJsonPath('data.0.verification_status_label', 'Menunggu verifikasi');
    }

    public function test_admin_searches_providers_by_name(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $target = Organization::factory()->create(['name' => 'Studio Kamera Nusantara']);
        Organization::factory()->create(['name' => 'Penyedia Lain']);

        $this->actingAs($admin)
            ->getJson('/api/platform/providers?q=Kamera')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $target->getKey());
    }

    public function test_verification_update_returns_the_new_status(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $organization = Organization::factory()->unverified()->create();

        $this->actingAs($admin)
            ->patchJson('/api/platform/providers/'.$organization->getKey().'/verification', [
                'verification_status' => Organization::VERIFICATION_VERIFIED,
            ])
            ->assertOk()
            ->assertJsonPath('data.verification_status', Organization::VERIFICATION_VERIFIED)
            ->assertJsonPath('data.verification_status_label', 'Terverifikasi')
            ->assertJsonPath('data.is_publicly_visible', true);
    }

    public function test_non_platform_user_cannot_list_wallet_transactions(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/platform/wallet-transactions')
            ->assertForbidden();
    }

    public function test_admin_sees_wallet_transactions_with_owner_and_summary(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $owner = User::factory()->create(['name' => 'Penyewa Aktif', 'wallet_balance' => '30000.00']);

        WalletTransaction::create([
            'user_id' => $owner->getKey(),
            'amount' => 50000,
            'type' => WalletTransaction::TYPE_TOPUP,
            'reference_id' => 'WLLET-platform-1',
            'status' => WalletTransaction::STATUS_SUCCESS,
        ]);

        WalletTransaction::create([
            'user_id' => $owner->getKey(),
            'amount' => 20000,
            'type' => WalletTransaction::TYPE_DEDUCTION,
            'reference_id' => 'WLLET-platform-2',
            'status' => WalletTransaction::STATUS_SUCCESS,
        ]);

        $this->actingAs($admin)
            ->getJson('/api/platform/wallet-transactions')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.user.id', $owner->getKey())
            ->assertJsonPath('data.0.direction', 'out')
            // Ringkasan tidak ikut filter: saldo beredar seluruh akun.
            ->assertJsonPath('summary.total_balance', 30000)
            ->assertJsonPath('summary.total_topped_up', 50000)
            ->assertJsonPath('summary.total_spent', 20000);
    }

    public function test_admin_filters_wallet_transactions_by_type_and_owner(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $owner = User::factory()->create(['name' => 'Penyewa Dicari']);
        $other = User::factory()->create(['name' => 'Penyewa Lain']);

        foreach ([$owner, $other] as $index => $user) {
            WalletTransaction::create([
                'user_id' => $user->getKey(),
                'amount' => 10000,
                'type' => WalletTransaction::TYPE_TOPUP,
                'reference_id' => 'WLLET-filter-'.$index,
                'status' => WalletTransaction::STATUS_PENDING,
            ]);
        }

        WalletTransaction::create([
            'user_id' => $owner->getKey(),
            'amount' => 5000,
            'type' => WalletTransaction::TYPE_DEDUCTION,
            'reference_id' => 'WLLET-filter-deduction',
            'status' => WalletTransaction::STATUS_SUCCESS,
        ]);

        $this->actingAs($admin)
            ->getJson('/api/platform/wallet-transactions?type=topup&q=Dicari')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference_id', 'WLLET-filter-0');
    }

    public function test_admin_user_list_includes_the_wallet_balance(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $target = User::factory()->create(['wallet_balance' => '75000.00']);

        $this->actingAs($admin)
            ->getJson('/api/platform/users?q='.urlencode($target->email))
            ->assertOk()
            ->assertJsonPath('data.0.wallet_balance', 75000);
    }
}
