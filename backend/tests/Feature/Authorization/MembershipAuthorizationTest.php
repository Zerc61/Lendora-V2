<?php

namespace Tests\Feature\Authorization;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MembershipAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
    }

    public function test_borrower_cannot_list_members(): void
    {
        [$borrower] = $this->memberWithRole(Membership::ROLE_BORROWER);

        $this->actingAs($borrower)->getJson('/api/memberships')->assertForbidden();
    }

    public function test_operator_can_list_members(): void
    {
        [$operator] = $this->memberWithRole(Membership::ROLE_OPERATOR);

        $this->actingAs($operator)->getJson('/api/memberships')->assertOk();
    }

    public function test_admin_can_list_members(): void
    {
        [$admin] = $this->memberWithRole(Membership::ROLE_ADMIN);

        $this->actingAs($admin)->getJson('/api/memberships')->assertOk();
    }

    public function test_borrower_cannot_update_another_membership(): void
    {
        [$borrower] = $this->memberWithRole(Membership::ROLE_BORROWER);
        $target = Membership::factory()->create(['organization_id' => $this->organization->getKey()]);

        $this->actingAs($borrower)
            ->patchJson('/api/memberships/'.$target->getKey(), ['role' => Membership::ROLE_ADMIN])
            ->assertForbidden();
    }

    public function test_admin_can_change_role_and_change_is_audited(): void
    {
        [$admin] = $this->memberWithRole(Membership::ROLE_ADMIN);
        $target = Membership::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'role' => Membership::ROLE_BORROWER,
        ]);

        $this->actingAs($admin)
            ->patchJson('/api/memberships/'.$target->getKey(), ['role' => Membership::ROLE_OPERATOR])
            ->assertOk()
            ->assertJsonPath('data.role', Membership::ROLE_OPERATOR);

        $event = DB::table('audit_events')->where('action', 'membership.updated')->latest('id')->first();

        $this->assertNotNull($event);
        $this->assertSame(Membership::ROLE_BORROWER, json_decode($event->before, true)['role']);
        $this->assertSame(Membership::ROLE_OPERATOR, json_decode($event->after, true)['role']);
    }

    public function test_activating_invited_membership_activates_pending_user(): void
    {
        [$admin] = $this->memberWithRole(Membership::ROLE_ADMIN);

        $pending = User::factory()->pending()->create();
        $membership = Membership::factory()->invited()->create([
            'organization_id' => $this->organization->getKey(),
            'user_id' => $pending->getKey(),
        ]);

        $this->actingAs($admin)
            ->patchJson('/api/memberships/'.$membership->getKey(), ['status' => Membership::STATUS_ACTIVE])
            ->assertOk();

        $this->assertSame(User::STATUS_ACTIVE, $pending->fresh()->status);
        $this->assertNotNull($membership->fresh()->joined_at);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'user.activated',
            'subject_id' => $pending->getKey(),
        ]);
    }

    public function test_deactivating_membership_revokes_sessions(): void
    {
        [$admin] = $this->memberWithRole(Membership::ROLE_ADMIN);
        $target = User::factory()->create();
        $membership = Membership::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'user_id' => $target->getKey(),
        ]);

        DB::table('sessions')->insert([
            'id' => 'session-id',
            'user_id' => $target->getKey(),
            'payload' => 'payload',
            'last_activity' => time(),
        ]);

        $this->actingAs($admin)
            ->patchJson('/api/memberships/'.$membership->getKey(), ['status' => Membership::STATUS_INACTIVE])
            ->assertOk();

        $this->assertDatabaseMissing('sessions', ['user_id' => $target->getKey()]);
    }

    public function test_list_supports_role_status_and_search_filters(): void
    {
        [$admin] = $this->memberWithRole(Membership::ROLE_ADMIN);

        $other = User::factory()->create(['name' => 'Budi Santoso', 'email' => 'budi@example.org']);
        Membership::factory()->invited()->create([
            'organization_id' => $this->organization->getKey(),
            'user_id' => $other->getKey(),
        ]);

        $payload = $this->actingAs($admin)
            ->getJson('/api/memberships?status=invited&role=borrower&q=budi')
            ->assertOk()
            ->json();

        $this->assertCount(1, $payload['data']);
        $this->assertSame('budi@example.org', $payload['data'][0]['user']['email']);

        $empty = $this->actingAs($admin)
            ->getJson('/api/memberships?status=invited&q=tidak-ada')
            ->assertOk()
            ->json();

        $this->assertCount(0, $empty['data']);
    }

    public function test_admin_can_add_existing_user_by_email(): void
    {
        [$admin] = $this->memberWithRole(Membership::ROLE_ADMIN);
        $user = User::factory()->create(['email' => 'tamu@example.org']);

        $this->actingAs($admin)
            ->postJson('/api/memberships', ['email' => 'tamu@example.org', 'role' => Membership::ROLE_BORROWER])
            ->assertCreated()
            ->assertJsonPath('data.role', Membership::ROLE_BORROWER)
            ->assertJsonPath('data.status', Membership::STATUS_ACTIVE);

        $this->assertDatabaseHas('audit_events', ['action' => 'membership.created']);
    }

    public function test_adding_pending_user_activates_the_account(): void
    {
        [$admin] = $this->memberWithRole(Membership::ROLE_ADMIN);
        $pending = User::factory()->pending()->create(['email' => 'pending@example.org']);

        $this->actingAs($admin)
            ->postJson('/api/memberships', ['email' => 'pending@example.org', 'role' => Membership::ROLE_BORROWER])
            ->assertCreated();

        $this->assertSame(User::STATUS_ACTIVE, $pending->fresh()->status);
    }

    public function test_adding_unknown_email_returns_validation_error(): void
    {
        [$admin] = $this->memberWithRole(Membership::ROLE_ADMIN);

        $this->actingAs($admin)
            ->postJson('/api/memberships', ['email' => 'belum-daftar@example.org', 'role' => 'borrower'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_adding_existing_member_returns_validation_error(): void
    {
        [$admin] = $this->memberWithRole(Membership::ROLE_ADMIN);

        $this->actingAs($admin)
            ->postJson('/api/memberships', [
                'email' => $admin->email,
                'role' => Membership::ROLE_OPERATOR,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_borrower_cannot_add_member(): void
    {
        [$borrower] = $this->memberWithRole(Membership::ROLE_BORROWER);

        $this->actingAs($borrower)
            ->postJson('/api/memberships', ['email' => 'x@example.org', 'role' => 'borrower'])
            ->assertForbidden();
    }

    public function test_admin_can_remove_member(): void
    {
        [$admin] = $this->memberWithRole(Membership::ROLE_ADMIN);
        $target = User::factory()->create();
        $membership = Membership::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'user_id' => $target->getKey(),
        ]);

        DB::table('sessions')->insert([
            'id' => 'session-to-kill',
            'user_id' => $target->getKey(),
            'payload' => 'payload',
            'last_activity' => time(),
        ]);

        $this->actingAs($admin)
            ->deleteJson('/api/memberships/'.$membership->getKey())
            ->assertOk();

        $this->assertDatabaseMissing('memberships', ['id' => $membership->getKey()]);
        $this->assertDatabaseMissing('sessions', ['user_id' => $target->getKey()]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'membership.removed',
            'subject_id' => $membership->getKey(),
        ]);
    }

    public function test_cannot_remove_last_active_admin(): void
    {
        [$admin, $membership] = $this->memberWithRole(Membership::ROLE_ADMIN);

        $this->actingAs($admin)
            ->deleteJson('/api/memberships/'.$membership->getKey())
            ->assertStatus(422);

        $this->assertDatabaseHas('memberships', ['id' => $membership->getKey()]);
    }

    public function test_borrower_cannot_remove_member(): void
    {
        [$borrower] = $this->memberWithRole(Membership::ROLE_BORROWER);
        $target = Membership::factory()->create(['organization_id' => $this->organization->getKey()]);

        $this->actingAs($borrower)
            ->deleteJson('/api/memberships/'.$target->getKey())
            ->assertForbidden();
    }

    public function test_admin_can_reset_password(): void
    {
        [$admin] = $this->memberWithRole(Membership::ROLE_ADMIN);
        $target = User::factory()->create();
        $membership = Membership::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'user_id' => $target->getKey(),
        ]);

        DB::table('sessions')->insert([
            'id' => 'session-reset',
            'user_id' => $target->getKey(),
            'payload' => 'payload',
            'last_activity' => time(),
        ]);

        $payload = $this->actingAs($admin)
            ->postJson('/api/memberships/'.$membership->getKey().'/reset-password')
            ->assertOk()
            ->json();

        $this->assertNotEmpty($payload['password']);
        $this->assertTrue(Hash::check($payload['password'], $target->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['user_id' => $target->getKey()]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'user.password_reset',
            'subject_id' => $target->getKey(),
        ]);
    }

    public function test_operator_cannot_reset_password(): void
    {
        [$operator] = $this->memberWithRole(Membership::ROLE_OPERATOR);
        $membership = Membership::factory()->create(['organization_id' => $this->organization->getKey()]);

        $this->actingAs($operator)
            ->postJson('/api/memberships/'.$membership->getKey().'/reset-password')
            ->assertForbidden();
    }

    /**
     * @return array{0: User, 1: Membership}
     */
    private function memberWithRole(string $role): array
    {
        $user = User::factory()->create();
        $membership = Membership::factory()->role($role)->create([
            'organization_id' => $this->organization->getKey(),
            'user_id' => $user->getKey(),
        ]);

        return [$user, $membership];
    }
}
