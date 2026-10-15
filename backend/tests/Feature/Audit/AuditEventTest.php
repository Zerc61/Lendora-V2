<?php

namespace Tests\Feature\Audit;

use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_recorder_writes_event_with_context_and_request_id(): void
    {
        $organization = Organization::factory()->create();
        app(OrganizationContext::class)->set($organization);

        $event = app(AuditRecorder::class)->record('probe.event', context: ['foo' => 'bar']);

        $this->assertDatabaseHas('audit_events', [
            'id' => $event->getKey(),
            'action' => 'probe.event',
            'organization_id' => $organization->getKey(),
        ]);
        $this->assertSame(['foo' => 'bar'], $event->fresh()->context);
    }

    public function test_audit_events_are_append_only_on_update(): void
    {
        $event = AuditEvent::create(['action' => 'probe.event']);

        try {
            DB::transaction(function () use ($event): void {
                DB::table('audit_events')->where('id', $event->getKey())->update(['action' => 'changed']);
            });

            $this->fail('Update audit seharusnya ditolak database.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }

        $this->assertDatabaseHas('audit_events', ['id' => $event->getKey(), 'action' => 'probe.event']);
    }

    public function test_audit_events_are_append_only_on_delete(): void
    {
        $event = AuditEvent::create(['action' => 'probe.event']);

        try {
            DB::transaction(function () use ($event): void {
                DB::table('audit_events')->where('id', $event->getKey())->delete();
            });

            $this->fail('Hapus audit seharusnya ditolak database.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }

        $this->assertDatabaseHas('audit_events', ['id' => $event->getKey()]);
    }

    public function test_admin_can_list_audit_events(): void
    {
        [$admin, $organization] = $this->adminWithOrg();

        AuditEvent::create([
            'organization_id' => $organization->getKey(),
            'actor_id' => $admin->getKey(),
            'action' => 'membership.updated',
        ]);
        AuditEvent::create([
            'organization_id' => $organization->getKey(),
            'action' => 'loan.approved',
        ]);

        $payload = $this->actingAs($admin)
            ->getJson('/api/audit-events')
            ->assertOk()
            ->json();

        $this->assertCount(2, $payload['data']);
        $this->assertSame('loan.approved', $payload['data'][0]['action']);
        $this->assertSame('membership.updated', $payload['data'][1]['action']);
        $this->assertSame('Admin', $payload['data'][1]['actor']['name']);
    }

    public function test_audit_list_is_scoped_to_own_organization(): void
    {
        [$admin, $organization] = $this->adminWithOrg();
        $otherOrganization = Organization::factory()->create();

        AuditEvent::create(['organization_id' => $organization->getKey(), 'action' => 'mine.event']);
        AuditEvent::create([
            'organization_id' => $otherOrganization->getKey(),
            'action' => 'foreign.event',
        ]);

        $payload = $this->actingAs($admin)->getJson('/api/audit-events')->assertOk()->json();

        $this->assertCount(1, $payload['data']);
        $this->assertSame('mine.event', $payload['data'][0]['action']);
    }

    public function test_audit_list_supports_action_filter(): void
    {
        [$admin, $organization] = $this->adminWithOrg();

        AuditEvent::create(['organization_id' => $organization->getKey(), 'action' => 'loan.approved']);
        AuditEvent::create(['organization_id' => $organization->getKey(), 'action' => 'asset.retired']);

        $payload = $this->actingAs($admin)
            ->getJson('/api/audit-events?action=loan.approved')
            ->assertOk()
            ->json();

        $this->assertCount(1, $payload['data']);
        $this->assertSame('loan.approved', $payload['data'][0]['action']);
    }

    public function test_operator_cannot_list_audit_events(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create();
        Membership::factory()->role(Membership::ROLE_OPERATOR)->create([
            'organization_id' => $organization->getKey(),
            'user_id' => $user->getKey(),
        ]);

        $this->actingAs($user)->getJson('/api/audit-events')->assertForbidden();
    }

    /**
     * @return array{0: User, 1: Organization}
     */
    private function adminWithOrg(): array
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['name' => 'Admin']);
        Membership::factory()->role(Membership::ROLE_ADMIN)->create([
            'organization_id' => $organization->getKey(),
            'user_id' => $user->getKey(),
        ]);

        return [$user, $organization];
    }
}
