<?php

namespace Tests\Feature\Auth;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_pending_user_and_invited_membership(): void
    {
        $organization = Organization::factory()->create();

        $response = $this->postJson('/api/register', [
            'name' => 'Rina Peminjam',
            'email' => 'Rina@Example.test',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
            'organization_slug' => $organization->slug,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', User::STATUS_PENDING)
            ->assertJsonPath('data.email', 'rina@example.test');

        $this->assertDatabaseHas('users', [
            'email' => 'rina@example.test',
            'status' => User::STATUS_PENDING,
        ]);

        $this->assertDatabaseHas('memberships', [
            'organization_id' => $organization->getKey(),
            'role' => Membership::ROLE_BORROWER,
            'status' => Membership::STATUS_INVITED,
        ]);

        $this->assertDatabaseHas('audit_events', ['action' => 'auth.registered']);
        $this->assertGuest();
    }

    public function test_registration_without_organization_code_creates_pending_user(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Dimas Mandiri',
            'email' => 'Dimas@Example.test',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', User::STATUS_PENDING);

        $this->assertDatabaseHas('users', [
            'email' => 'dimas@example.test',
            'status' => User::STATUS_PENDING,
        ]);

        // Tanpa kode organisasi: belum ada membership; admin mengaitkan
        // lewat "Tambah anggota".
        $this->assertDatabaseCount('memberships', 0);
    }

    public function test_registration_rejects_unknown_organization(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Rina',
            'email' => 'rina2@example.test',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
            'organization_slug' => 'tidak-ada',
        ])->assertStatus(422)->assertJsonValidationErrors('organization_slug');
    }

    public function test_pending_user_cannot_login(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->pending()->create(['password' => 'secret-password']);
        Membership::factory()->invited()->create([
            'organization_id' => $organization->getKey(),
            'user_id' => $user->getKey(),
        ]);

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'secret-password',
        ])->assertForbidden();

        $this->assertGuest();
        $this->assertDatabaseHas('audit_events', [
            'action' => 'auth.login_blocked',
            'actor_id' => $user->getKey(),
        ]);
    }

    public function test_login_with_wrong_password_is_rejected_and_audited(): void
    {
        $user = User::factory()->create(['password' => 'secret-password']);

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'salah',
        ])->assertStatus(422)->assertJsonValidationErrors('email');

        $this->assertGuest();
        $this->assertDatabaseHas('audit_events', ['action' => 'auth.login_failed']);
    }

    public function test_active_user_can_login_and_session_is_regenerated(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['password' => 'secret-password']);
        Membership::factory()->create([
            'organization_id' => $organization->getKey(),
            'user_id' => $user->getKey(),
            'role' => Membership::ROLE_ADMIN,
        ]);

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'secret-password',
        ])->assertOk()->assertJsonPath('active_organization_id', $organization->getKey());

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'auth.login_succeeded',
            'actor_id' => $user->getKey(),
        ]);
    }

    public function test_login_returns_active_organizations(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['password' => 'secret-password']);
        Membership::factory()->create([
            'organization_id' => $organization->getKey(),
            'user_id' => $user->getKey(),
        ]);

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'secret-password',
        ])
            ->assertOk()
            ->assertJsonPath('organizations.0.id', $organization->getKey());
    }

    public function test_login_is_rate_limited(): void
    {
        $payload = ['email' => 'bruteforce@example.test', 'password' => 'salah'];

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/login', $payload)->assertStatus(422);
        }

        $this->postJson('/api/login', $payload)->assertStatus(429);
    }

    public function test_logout_invalidates_session(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/logout')->assertOk();

        $this->assertGuest();
        $this->getJson('/api/me')->assertUnauthorized();
        $this->assertDatabaseHas('audit_events', [
            'action' => 'auth.logout',
            'actor_id' => $user->getKey(),
        ]);
    }

    public function test_guest_cannot_access_protected_endpoints(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
        $this->getJson('/api/memberships')->assertUnauthorized();
    }

    public function test_me_returns_active_organizations(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create();
        Membership::factory()->create([
            'organization_id' => $organization->getKey(),
            'user_id' => $user->getKey(),
        ]);

        $this->actingAs($user)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('active_organization_id', $organization->getKey())
            ->assertJsonPath('organizations.0.id', $organization->getKey());
    }
}
