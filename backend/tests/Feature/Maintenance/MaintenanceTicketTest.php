<?php

namespace Tests\Feature\Maintenance;

use App\Models\Asset;
use App\Models\AssetConditionRecord;
use App\Models\Issue;
use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\MaintenanceLog;
use App\Models\MaintenanceTicket;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Tests\Support\TenantTestCase;

/**
 * MaintenanceTicket (Fase 8): siklus perbaikan, verifikasi sebagai gerbang
 * terakhir, state machine, invariant (M-1..M-7), otorisasi, dan isolasi tenant.
 */
class MaintenanceTicketTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Uji tiket perawatan tanpa pagar bayar di muka; gerbang pembayaran
        // diuji di PaymentTest.
        $this->policy(['require_prepayment' => false]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function ticket(Asset $asset, array $attributes = []): MaintenanceTicket
    {
        return MaintenanceTicket::factory()->create([
            'organization_id' => $asset->organization_id,
            'asset_id' => $asset->getKey(),
        ] + $attributes);
    }

    private function createTicket(Asset $asset, User|int $actor, array $overrides = []): int
    {
        $response = $this->actingAs($actor)
            ->postJson('/api/assets/'.$asset->getKey().'/maintenance-tickets', array_merge([
                'description' => 'Ganti komponen rusak.',
            ], $overrides))
            ->assertCreated();

        return (int) $response->json('data.id');
    }

    public function test_operator_creates_ticket_and_asset_goes_under_maintenance(): void
    {
        $asset = $this->asset();
        $operator = $this->member(Membership::ROLE_OPERATOR);

        $this->actingAs($operator)
            ->postJson('/api/assets/'.$asset->getKey().'/maintenance-tickets', [
                'description' => 'Ganti LCD',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', MaintenanceTicket::STATUS_OPEN);

        $this->assertSame(Asset::LIFECYCLE_UNDER_MAINTENANCE, $asset->fresh()->lifecycle_status);
        $this->assertDatabaseHas('maintenance_logs', ['action' => MaintenanceLog::ACTION_CREATED]);
        $this->assertDatabaseHas('audit_events', ['action' => 'maintenance.created']);
    }

    public function test_ticket_creation_requires_a_description(): void
    {
        $asset = $this->asset();
        $operator = $this->member(Membership::ROLE_OPERATOR);

        $this->actingAs($operator)
            ->postJson('/api/assets/'.$asset->getKey().'/maintenance-tickets', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('description');

        $this->assertDatabaseCount('maintenance_tickets', 0);
    }

    public function test_asset_cannot_have_two_blocking_tickets(): void
    {
        $asset = $this->asset();
        $operator = $this->member(Membership::ROLE_OPERATOR);

        $this->ticket($asset, ['status' => MaintenanceTicket::STATUS_IN_PROGRESS]);

        $this->actingAs($operator)
            ->postJson('/api/assets/'.$asset->getKey().'/maintenance-tickets', [
                'description' => 'Ticket kedua.',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('asset_id');

        $this->assertDatabaseCount('maintenance_tickets', 1);
    }

    public function test_ticket_cannot_be_created_for_a_retired_asset(): void
    {
        $asset = $this->asset(attributes: ['lifecycle_status' => Asset::LIFECYCLE_RETIRED]);
        $operator = $this->member(Membership::ROLE_OPERATOR);

        $this->actingAs($operator)
            ->postJson('/api/assets/'.$asset->getKey().'/maintenance-tickets', [
                'description' => 'Sudah pensiun.',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('asset_id');
    }

    public function test_ticket_can_link_to_an_issue_of_the_same_asset(): void
    {
        $asset = $this->asset();
        $operator = $this->member(Membership::ROLE_OPERATOR);
        $issue = Issue::factory()->create([
            'organization_id' => $asset->organization_id,
            'asset_id' => $asset->getKey(),
            'severity' => Issue::SEVERITY_HIGH,
            'reported_by' => $operator->getKey(),
        ]);

        $this->actingAs($operator)
            ->postJson('/api/assets/'.$asset->getKey().'/maintenance-tickets', [
                'description' => 'Perbaiki sesuai laporan.',
                'issue_id' => $issue->getKey(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.issue_id', $issue->getKey())
            ->assertJsonPath('data.priority', Issue::SEVERITY_HIGH);
    }

    public function test_ticket_rejects_an_issue_of_another_asset(): void
    {
        $asset = $this->asset();
        $other = $this->asset();
        $operator = $this->member(Membership::ROLE_OPERATOR);
        $issue = Issue::factory()->create([
            'organization_id' => $other->organization_id,
            'asset_id' => $other->getKey(),
        ]);

        $this->actingAs($operator)
            ->postJson('/api/assets/'.$asset->getKey().'/maintenance-tickets', [
                'description' => 'Salah aset.',
                'issue_id' => $issue->getKey(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('issue_id');
    }

    public function test_full_repair_cycle_returns_asset_to_active_only_after_verification(): void
    {
        $asset = $this->asset();
        $operator = $this->member(Membership::ROLE_OPERATOR);
        $technician = $this->member(Membership::ROLE_OPERATOR);

        $ticketId = $this->createTicket($asset, $operator);

        $this->actingAs($operator)
            ->postJson('/api/maintenance-tickets/'.$ticketId.'/assign', ['assigned_to' => $technician->getKey()])
            ->assertOk()
            ->assertJsonPath('data.status', MaintenanceTicket::STATUS_ASSIGNED);

        $this->actingAs($technician)
            ->postJson('/api/maintenance-tickets/'.$ticketId.'/start')
            ->assertOk()
            ->assertJsonPath('data.status', MaintenanceTicket::STATUS_IN_PROGRESS);

        $this->actingAs($technician)
            ->postJson('/api/maintenance-tickets/'.$ticketId.'/complete', ['note' => 'Komponen diganti.'])
            ->assertOk()
            ->assertJsonPath('data.status', MaintenanceTicket::STATUS_AWAITING_VERIFICATION);

        // Pekerjaan selesai, tetapi aset belum layak sampai diverifikasi.
        $this->assertSame(Asset::LIFECYCLE_AWAITING_INSPECTION, $asset->fresh()->lifecycle_status);

        $this->actingAs($operator)
            ->postJson('/api/maintenance-tickets/'.$ticketId.'/verify', ['condition' => Asset::CONDITION_GOOD])
            ->assertOk()
            ->assertJsonPath('data.status', MaintenanceTicket::STATUS_RESOLVED);

        $this->assertSame(Asset::LIFECYCLE_ACTIVE, $asset->fresh()->lifecycle_status);
        $this->assertDatabaseHas('asset_condition_records', [
            'asset_id' => $asset->getKey(),
            'source' => AssetConditionRecord::SOURCE_MAINTENANCE,
            'ticket_id' => $ticketId,
            'condition' => Asset::CONDITION_GOOD,
        ]);
        $this->assertDatabaseHas('audit_events', ['action' => 'maintenance.verified']);
    }

    public function test_cs_06_failed_verification_keeps_asset_out_of_service(): void
    {
        $asset = $this->asset();
        $operator = $this->member(Membership::ROLE_OPERATOR);
        $technician = $this->member(Membership::ROLE_OPERATOR);

        $ticketId = $this->createTicket($asset, $operator);
        $this->actingAs($operator)->postJson('/api/maintenance-tickets/'.$ticketId.'/assign', ['assigned_to' => $technician->getKey()])->assertOk();
        $this->actingAs($technician)->postJson('/api/maintenance-tickets/'.$ticketId.'/start')->assertOk();
        $this->actingAs($technician)->postJson('/api/maintenance-tickets/'.$ticketId.'/complete')->assertOk();

        $this->actingAs($operator)
            ->postJson('/api/maintenance-tickets/'.$ticketId.'/verify', [
                'condition' => Asset::CONDITION_POOR,
                'notes' => 'Masih bunyi kasar, perbaikan belum tuntas.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', MaintenanceTicket::STATUS_IN_PROGRESS);

        // Ticket kembali dikerjakan; aset tetap terkunci (bukan active).
        $this->assertSame(Asset::LIFECYCLE_UNDER_MAINTENANCE, $asset->fresh()->lifecycle_status);
        $this->assertNotSame(Asset::LIFECYCLE_ACTIVE, $asset->fresh()->lifecycle_status);
        $this->assertDatabaseHas('maintenance_logs', [
            'ticket_id' => $ticketId,
            'action' => MaintenanceLog::ACTION_VERIFICATION_FAILED,
            'note' => 'Masih bunyi kasar, perbaikan belum tuntas.',
        ]);
        $this->assertDatabaseHas('audit_events', ['action' => 'maintenance.verification_failed']);
    }

    public function test_verify_requires_notes_for_poor_condition(): void
    {
        $asset = $this->asset();
        $operator = $this->member(Membership::ROLE_OPERATOR);
        $ticket = $this->ticket($asset, ['status' => MaintenanceTicket::STATUS_AWAITING_VERIFICATION]);

        $this->actingAs($operator)
            ->postJson('/api/maintenance-tickets/'.$ticket->getKey().'/verify', [
                'condition' => Asset::CONDITION_UNSERVICEABLE,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('notes');
    }

    public function test_assignee_cannot_verify_their_own_work(): void
    {
        $asset = $this->asset();
        $operator = $this->member(Membership::ROLE_OPERATOR);
        $technician = $this->member(Membership::ROLE_OPERATOR);

        $ticketId = $this->createTicket($asset, $operator);
        $this->actingAs($operator)->postJson('/api/maintenance-tickets/'.$ticketId.'/assign', ['assigned_to' => $technician->getKey()])->assertOk();
        $this->actingAs($technician)->postJson('/api/maintenance-tickets/'.$ticketId.'/start')->assertOk();
        $this->actingAs($technician)->postJson('/api/maintenance-tickets/'.$ticketId.'/complete')->assertOk();

        $this->actingAs($technician)
            ->postJson('/api/maintenance-tickets/'.$ticketId.'/verify', ['condition' => Asset::CONDITION_GOOD])
            ->assertForbidden();
    }

    public function test_cancel_requires_a_reason(): void
    {
        $asset = $this->asset();
        $operator = $this->member(Membership::ROLE_OPERATOR);
        $ticket = $this->ticket($asset);

        $this->actingAs($operator)
            ->postJson('/api/maintenance-tickets/'.$ticket->getKey().'/cancel', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    public function test_cancelling_returns_a_healthy_asset_to_active(): void
    {
        $asset = $this->asset();
        $operator = $this->member(Membership::ROLE_OPERATOR);
        $ticket = $this->ticket($asset);

        $this->actingAs($operator)
            ->postJson('/api/maintenance-tickets/'.$ticket->getKey().'/cancel', ['reason' => 'Ternyata tidak rusak.'])
            ->assertOk()
            ->assertJsonPath('data.status', MaintenanceTicket::STATUS_CANCELLED);

        $this->assertSame(Asset::LIFECYCLE_ACTIVE, $asset->fresh()->lifecycle_status);
        $this->assertDatabaseHas('maintenance_logs', [
            'ticket_id' => $ticket->getKey(),
            'action' => MaintenanceLog::ACTION_CANCELLED,
            'note' => 'Ternyata tidak rusak.',
        ]);
    }

    public function test_cancelling_keeps_a_damaged_asset_waiting_for_inspection(): void
    {
        $asset = $this->asset(attributes: ['condition' => Asset::CONDITION_POOR]);
        $operator = $this->member(Membership::ROLE_OPERATOR);
        $ticket = $this->ticket($asset);

        $this->actingAs($operator)
            ->postJson('/api/maintenance-tickets/'.$ticket->getKey().'/cancel', ['reason' => 'Diteruskan ke vendor.'])
            ->assertOk();

        $this->assertSame(Asset::LIFECYCLE_AWAITING_INSPECTION, $asset->fresh()->lifecycle_status);
    }

    public function test_invalid_transitions_are_rejected(): void
    {
        $asset = $this->asset();
        $operator = $this->member(Membership::ROLE_OPERATOR);

        // open tidak bisa langsung dikerjakan.
        $open = $this->ticket($asset);
        $this->actingAs($operator)
            ->postJson('/api/maintenance-tickets/'.$open->getKey().'/start')
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        // assigned tidak bisa langsung menunggu verifikasi.
        $assigned = $this->ticket($asset, ['status' => MaintenanceTicket::STATUS_ASSIGNED]);
        $this->actingAs($operator)
            ->postJson('/api/maintenance-tickets/'.$assigned->getKey().'/complete')
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        // resolved bersifat terminal.
        $resolved = $this->ticket($asset, ['status' => MaintenanceTicket::STATUS_RESOLVED]);
        $this->actingAs($operator)
            ->postJson('/api/maintenance-tickets/'.$resolved->getKey().'/cancel', ['reason' => 'Ubah pikiran.'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_every_transition_writes_one_maintenance_log(): void
    {
        $asset = $this->asset();
        $operator = $this->member(Membership::ROLE_OPERATOR);
        $technician = $this->member(Membership::ROLE_OPERATOR);

        $ticketId = $this->createTicket($asset, $operator);
        $this->actingAs($operator)->postJson('/api/maintenance-tickets/'.$ticketId.'/assign', ['assigned_to' => $technician->getKey()])->assertOk();
        $this->actingAs($technician)->postJson('/api/maintenance-tickets/'.$ticketId.'/start')->assertOk();
        $this->actingAs($technician)->postJson('/api/maintenance-tickets/'.$ticketId.'/complete')->assertOk();
        $this->actingAs($operator)->postJson('/api/maintenance-tickets/'.$ticketId.'/verify', ['condition' => Asset::CONDITION_GOOD])->assertOk();

        $this->assertDatabaseCount('maintenance_logs', 5);

        foreach ([
            MaintenanceLog::ACTION_CREATED,
            MaintenanceLog::ACTION_ASSIGNED,
            MaintenanceLog::ACTION_STARTED,
            MaintenanceLog::ACTION_COMPLETED,
            MaintenanceLog::ACTION_VERIFIED,
        ] as $action) {
            $this->assertDatabaseHas('maintenance_logs', ['ticket_id' => $ticketId, 'action' => $action]);
        }
    }

    public function test_resolved_ticket_auto_closes_the_linked_issue(): void
    {
        $asset = $this->asset();
        $operator = $this->member(Membership::ROLE_OPERATOR);
        $technician = $this->member(Membership::ROLE_OPERATOR);

        $issue = Issue::factory()->create([
            'organization_id' => $asset->organization_id,
            'asset_id' => $asset->getKey(),
            'severity' => Issue::SEVERITY_HIGH,
            'reported_by' => $operator->getKey(),
            'status' => Issue::STATUS_REVIEWING,
        ]);

        $ticketId = $this->createTicket($asset, $operator, ['issue_id' => $issue->getKey()]);
        $this->actingAs($operator)->postJson('/api/maintenance-tickets/'.$ticketId.'/assign', ['assigned_to' => $technician->getKey()])->assertOk();
        $this->actingAs($technician)->postJson('/api/maintenance-tickets/'.$ticketId.'/start')->assertOk();
        $this->actingAs($technician)->postJson('/api/maintenance-tickets/'.$ticketId.'/complete')->assertOk();
        $this->actingAs($operator)->postJson('/api/maintenance-tickets/'.$ticketId.'/verify', ['condition' => Asset::CONDITION_GOOD])->assertOk();

        $this->assertSame(Issue::STATUS_RESOLVED, $issue->fresh()->status);
        $this->assertNotNull($issue->fresh()->resolution);
    }

    public function test_asset_in_maintenance_cannot_be_checked_out(): void
    {
        $asset = $this->asset();
        $borrower = $this->member(Membership::ROLE_BORROWER);
        $operator = $this->member(Membership::ROLE_OPERATOR);

        $loan = Loan::factory()->approved()->create([
            'organization_id' => $this->organization->getKey(),
            'borrower_id' => $borrower->getKey(),
        ]);
        LoanItem::query()->create([
            'loan_id' => $loan->getKey(),
            'asset_id' => $asset->getKey(),
        ]);

        // Aset masuk perbaikan setelah loan disetujui.
        $this->ticket($asset, ['status' => MaintenanceTicket::STATUS_IN_PROGRESS]);
        $asset->lifecycle_status = Asset::LIFECYCLE_UNDER_MAINTENANCE;
        $asset->save();

        $this->actingAs($operator)
            ->postJson('/api/loans/'.$loan->getKey().'/checkout', [
                'items' => [['asset_id' => $asset->getKey(), 'condition' => Asset::CONDITION_GOOD]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');

        $this->assertSame(Loan::STATUS_APPROVED, $loan->fresh()->status);
    }

    public function test_borrower_cannot_access_maintenance(): void
    {
        $asset = $this->asset();
        $borrower = $this->member(Membership::ROLE_BORROWER);

        $this->actingAs($borrower)
            ->getJson('/api/maintenance-tickets')
            ->assertForbidden();

        $this->actingAs($borrower)
            ->postJson('/api/assets/'.$asset->getKey().'/maintenance-tickets', ['description' => 'Coba buat.'])
            ->assertForbidden();
    }

    public function test_tickets_are_isolated_per_organization(): void
    {
        $asset = $this->asset();
        $operator = $this->member(Membership::ROLE_OPERATOR);
        $otherOrg = Organization::factory()->create();
        $foreignAsset = $this->asset($otherOrg);
        $foreignTicket = MaintenanceTicket::factory()->create([
            'organization_id' => $otherOrg->getKey(),
            'asset_id' => $foreignAsset->getKey(),
        ]);

        $this->actingAs($operator)
            ->getJson('/api/maintenance-tickets/'.$foreignTicket->getKey())
            ->assertNotFound();
    }

    public function test_maintenance_logs_are_append_only_on_update(): void
    {
        $asset = $this->asset();
        $log = MaintenanceLog::factory()->create(['ticket_id' => $this->ticket($asset)->getKey()]);

        $this->expectException(QueryException::class);

        $log->update(['note' => 'tampered']);
    }

    public function test_maintenance_logs_are_append_only_on_delete(): void
    {
        $asset = $this->asset();
        $log = MaintenanceLog::factory()->create(['ticket_id' => $this->ticket($asset)->getKey()]);

        $this->expectException(QueryException::class);

        $log->delete();
    }
}
