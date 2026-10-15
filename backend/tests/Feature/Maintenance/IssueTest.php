<?php

namespace Tests\Feature\Maintenance;

use App\Models\Asset;
use App\Models\Issue;
use App\Models\Membership;
use App\Models\Organization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TenantTestCase;

/**
 * Issue (Fase 8): pelaporan, efek severity ke lifecycle aset, state machine,
 * otorisasi, dan isolasi tenant. OD-33 diimplementasikan sebagai default
 * terdokumentasi: semua anggota boleh melaporkan aset apa pun.
 */
class IssueTest extends TenantTestCase
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    private function issue(Asset $asset, array $attributes = []): Issue
    {
        return Issue::factory()->create([
            'organization_id' => $asset->organization_id,
            'asset_id' => $asset->getKey(),
        ] + $attributes);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Asset $asset, array $overrides = []): array
    {
        return array_merge([
            'asset_id' => $asset->getKey(),
            'type' => Issue::TYPE_DAMAGE,
            'severity' => Issue::SEVERITY_MEDIUM,
            'description' => 'Layar retak di sudut kanan.',
        ], $overrides);
    }

    public function test_borrower_can_report_an_issue_on_any_asset(): void
    {
        $asset = $this->asset();
        $borrower = $this->member(Membership::ROLE_BORROWER);

        $this->actingAs($borrower)
            ->postJson('/api/issues', $this->payload($asset))
            ->assertCreated()
            ->assertJsonPath('data.status', Issue::STATUS_OPEN)
            ->assertJsonPath('data.severity', Issue::SEVERITY_MEDIUM)
            ->assertJsonPath('data.severity_label', 'Sedang');

        $this->assertDatabaseHas('issues', [
            'asset_id' => $asset->getKey(),
            'reported_by' => $borrower->getKey(),
            'status' => Issue::STATUS_OPEN,
        ]);
        // medium hanya menuntut inspeksi, bukan perbaikan.
        $this->assertSame(Asset::LIFECYCLE_AWAITING_INSPECTION, $asset->fresh()->lifecycle_status);
        $this->assertDatabaseHas('audit_events', ['action' => 'issue.created']);
    }

    public function test_low_severity_does_not_change_asset_lifecycle(): void
    {
        $asset = $this->asset();
        $borrower = $this->member(Membership::ROLE_BORROWER);

        $this->actingAs($borrower)
            ->postJson('/api/issues', $this->payload($asset, ['severity' => Issue::SEVERITY_LOW]))
            ->assertCreated();

        $this->assertSame(Asset::LIFECYCLE_ACTIVE, $asset->fresh()->lifecycle_status);
    }

    public function test_high_severity_locks_asset_under_maintenance(): void
    {
        $asset = $this->asset();
        $borrower = $this->member(Membership::ROLE_BORROWER);

        $this->actingAs($borrower)
            ->postJson('/api/issues', $this->payload($asset, ['severity' => Issue::SEVERITY_HIGH]))
            ->assertCreated();

        $this->assertSame(Asset::LIFECYCLE_UNDER_MAINTENANCE, $asset->fresh()->lifecycle_status);
    }

    public function test_critical_issue_requires_a_photo(): void
    {
        $asset = $this->asset();
        $borrower = $this->member(Membership::ROLE_BORROWER);

        $this->actingAs($borrower)
            ->postJson('/api/issues', $this->payload($asset, ['severity' => Issue::SEVERITY_CRITICAL]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('photos');

        $this->assertDatabaseCount('issues', 0);
        $this->assertSame(Asset::LIFECYCLE_ACTIVE, $asset->fresh()->lifecycle_status);
    }

    public function test_critical_issue_with_photo_locks_asset_and_stores_evidence(): void
    {
        Storage::fake();

        $asset = $this->asset();
        $borrower = $this->member(Membership::ROLE_BORROWER);

        $this->actingAs($borrower)
            ->postJson('/api/issues', $this->payload($asset, [
                'severity' => Issue::SEVERITY_CRITICAL,
                'photos' => [UploadedFile::fake()->image('bukti.jpg')],
            ]))
            ->assertCreated();

        $this->assertSame(Asset::LIFECYCLE_UNDER_MAINTENANCE, $asset->fresh()->lifecycle_status);
        $this->assertDatabaseHas('attachments', [
            'attachable_type' => Issue::class,
            'type' => 'photo',
        ]);
    }

    public function test_severity_does_not_downgrade_an_asset_already_under_maintenance(): void
    {
        $asset = $this->asset(attributes: ['lifecycle_status' => Asset::LIFECYCLE_UNDER_MAINTENANCE]);
        $borrower = $this->member(Membership::ROLE_BORROWER);

        $this->actingAs($borrower)
            ->postJson('/api/issues', $this->payload($asset, ['severity' => Issue::SEVERITY_MEDIUM]))
            ->assertCreated();

        $this->assertSame(Asset::LIFECYCLE_UNDER_MAINTENANCE, $asset->fresh()->lifecycle_status);
    }

    public function test_issue_cannot_be_reported_on_a_retired_asset(): void
    {
        $asset = $this->asset(attributes: ['lifecycle_status' => Asset::LIFECYCLE_RETIRED]);
        $borrower = $this->member(Membership::ROLE_BORROWER);

        $this->actingAs($borrower)
            ->postJson('/api/issues', $this->payload($asset))
            ->assertStatus(422)
            ->assertJsonValidationErrors('asset_id');

        $this->assertDatabaseCount('issues', 0);
    }

    public function test_borrower_only_sees_the_issues_they_reported(): void
    {
        $asset = $this->asset();
        $mine = $this->member(Membership::ROLE_BORROWER);
        $other = $this->member(Membership::ROLE_BORROWER);

        $this->issue($asset, ['reported_by' => $mine->getKey()]);
        $this->issue($asset, ['reported_by' => $other->getKey()]);

        $response = $this->actingAs($mine)->getJson('/api/issues')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame($mine->getKey(), $response->json('data.0.reporter.id'));
    }

    public function test_operator_can_review_then_resolve_an_issue(): void
    {
        $asset = $this->asset();
        $operator = $this->member(Membership::ROLE_OPERATOR);
        $issue = $this->issue($asset);

        $this->actingAs($operator)
            ->postJson('/api/issues/'.$issue->getKey().'/review')
            ->assertOk()
            ->assertJsonPath('data.status', Issue::STATUS_REVIEWING)
            ->assertJsonPath('data.assignee.id', $operator->getKey());

        $this->actingAs($operator)
            ->postJson('/api/issues/'.$issue->getKey().'/resolve', ['resolution' => 'Sudah diperbaiki.'])
            ->assertOk()
            ->assertJsonPath('data.status', Issue::STATUS_RESOLVED);

        $this->assertDatabaseHas('audit_events', ['action' => 'issue.reviewing']);
        $this->assertDatabaseHas('audit_events', ['action' => 'issue.resolved']);
    }

    public function test_resolve_requires_a_resolution_note(): void
    {
        $asset = $this->asset();
        $operator = $this->member(Membership::ROLE_OPERATOR);
        $issue = $this->issue($asset, ['status' => Issue::STATUS_REVIEWING]);

        $this->actingAs($operator)
            ->postJson('/api/issues/'.$issue->getKey().'/resolve', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('resolution');
    }

    public function test_open_issue_cannot_be_resolved_without_review(): void
    {
        $asset = $this->asset();
        $operator = $this->member(Membership::ROLE_OPERATOR);
        $issue = $this->issue($asset, ['status' => Issue::STATUS_OPEN]);

        $this->actingAs($operator)
            ->postJson('/api/issues/'.$issue->getKey().'/resolve', ['resolution' => 'Cepat selesai.'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertSame(Issue::STATUS_OPEN, $issue->fresh()->status);
    }

    public function test_dismiss_requires_a_reason(): void
    {
        $asset = $this->asset();
        $operator = $this->member(Membership::ROLE_OPERATOR);
        $issue = $this->issue($asset);

        $this->actingAs($operator)
            ->postJson('/api/issues/'.$issue->getKey().'/dismiss', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->actingAs($operator)
            ->postJson('/api/issues/'.$issue->getKey().'/dismiss', ['reason' => 'Bukan kerusakan.'])
            ->assertOk()
            ->assertJsonPath('data.status', Issue::STATUS_DISMISSED);
    }

    public function test_borrower_cannot_review_or_resolve_an_issue(): void
    {
        $asset = $this->asset();
        $borrower = $this->member(Membership::ROLE_BORROWER);
        $issue = $this->issue($asset, ['reported_by' => $borrower->getKey()]);

        $this->actingAs($borrower)
            ->postJson('/api/issues/'.$issue->getKey().'/review')
            ->assertForbidden();

        $this->actingAs($borrower)
            ->postJson('/api/issues/'.$issue->getKey().'/resolve', ['resolution' => 'Coba tutup.'])
            ->assertForbidden();
    }

    public function test_issue_is_not_visible_from_another_organization(): void
    {
        $asset = $this->asset();
        $issue = $this->issue($asset);
        $outsider = $this->member(Membership::ROLE_OPERATOR);
        $otherOrg = Organization::factory()->create();
        $foreignOperator = $this->userIn($otherOrg, Membership::ROLE_OPERATOR);
        $foreignAsset = $this->asset($otherOrg);
        $foreignIssue = Issue::factory()->create([
            'organization_id' => $otherOrg->getKey(),
            'asset_id' => $foreignAsset->getKey(),
        ]);

        // Operator org lain tidak bisa membuka issue milik organisasi kita (404).
        $this->actingAs($outsider)
            ->getJson('/api/issues/'.$foreignIssue->getKey())
            ->assertNotFound();

        $this->actingAs($foreignOperator)
            ->getJson('/api/issues/'.$issue->getKey())
            ->assertNotFound();
    }
}
