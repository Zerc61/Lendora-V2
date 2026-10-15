<?php

namespace Tests\Feature\Borrower;

use App\Models\Loan;
use App\Models\Membership;
use App\Models\User;
use Tests\Support\TenantTestCase;

/**
 * Endpoint dashboard Borrower: angka, daftar, kalender, dan aktivitas harus
 * selalu terbatas pada loan milik user yang login, dengan kriteria status yang
 * sama dengan GET /loans.
 */
class BorrowerDashboardTest extends TenantTestCase
{
    private function loanFor(User $borrower, array $attributes = []): Loan
    {
        return Loan::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'borrower_id' => $borrower->getKey(),
        ] + $attributes);
    }

    public function test_guest_cannot_read_borrower_overview(): void
    {
        $this->getJson('/api/borrower/overview')->assertUnauthorized();
    }

    public function test_overview_counts_only_loans_of_the_logged_in_borrower(): void
    {
        $borrower = $this->member(Membership::ROLE_BORROWER);
        $other = $this->member(Membership::ROLE_BORROWER);

        $this->loanFor($borrower, [
            'status' => Loan::STATUS_CHECKED_OUT,
            'approved_at' => now()->subDay(),
            'checked_out_at' => now(),
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->addDays(2),
            'due_at' => now()->addDays(2),
        ]);
        $this->loanFor($borrower, ['status' => Loan::STATUS_REQUESTED]);
        $this->loanFor($borrower, [
            'status' => Loan::STATUS_RETURNED,
            'starts_at' => now()->subDays(8),
            'ends_at' => now()->subDays(5),
            'due_at' => now()->subDays(4),
            'approved_at' => now()->subDays(7),
            'checked_out_at' => now()->subDays(7),
            'returned_at' => now()->subDays(2),
        ]);

        // Milik peminjam lain: tidak boleh ikut terhitung.
        $this->loanFor($other, ['status' => Loan::STATUS_REQUESTED]);
        $this->loanFor($other, [
            'status' => Loan::STATUS_CHECKED_OUT,
            'ends_at' => now()->addDay(),
            'due_at' => now()->addDay(),
        ]);

        $payload = $this->actingAs($borrower)
            ->getJson('/api/borrower/overview')
            ->assertOk()
            ->json();

        $this->assertSame(
            ['active' => 1, 'pending' => 1, 'due_soon' => 1, 'completed' => 1],
            $payload['counts'],
        );
        $this->assertCount(1, $payload['active']);
        $this->assertCount(3, $payload['requests']);
    }

    public function test_overview_does_not_count_rejected_cancelled_or_expired_as_active(): void
    {
        $borrower = $this->member(Membership::ROLE_BORROWER);

        $this->loanFor($borrower, ['status' => Loan::STATUS_REJECTED, 'rejection_reason' => 'Alat rusak']);
        $this->loanFor($borrower, ['status' => Loan::STATUS_CANCELLED]);
        $this->loanFor($borrower, [
            'status' => Loan::STATUS_EXPIRED,
            'approved_at' => now()->subWeek(),
            'starts_at' => now()->subDays(3),
            'ends_at' => now()->subDay(),
        ]);

        $payload = $this->actingAs($borrower)
            ->getJson('/api/borrower/overview')
            ->assertOk()
            ->json();

        $this->assertSame(0, $payload['counts']['active']);
        $this->assertSame(0, $payload['counts']['due_soon']);
        $this->assertSame(0, $payload['counts']['completed']);
        $this->assertSame([], $payload['active']);
    }

    public function test_overview_activity_derives_from_own_loan_milestones_only(): void
    {
        $borrower = $this->member(Membership::ROLE_BORROWER);
        $other = $this->member(Membership::ROLE_BORROWER);

        $this->loanFor($borrower, ['status' => Loan::STATUS_REQUESTED]);
        $this->loanFor($other, ['status' => Loan::STATUS_REQUESTED]);

        $payload = $this->actingAs($borrower)
            ->getJson('/api/borrower/overview')
            ->assertOk()
            ->json();

        $this->assertNotEmpty($payload['activity']);

        foreach ($payload['activity'] as $entry) {
            $this->assertSame('loan.requested', $entry['key']);
        }
    }

    public function test_calendar_returns_pickup_period_and_due_events(): void
    {
        $borrower = $this->member(Membership::ROLE_BORROWER);

        $loan = $this->loanFor($borrower, [
            'status' => Loan::STATUS_APPROVED,
            'approved_at' => now(),
            'starts_at' => now()->addDay()->startOfDay(),
            'ends_at' => now()->addDays(4)->startOfDay(),
            'due_at' => now()->addDays(4)->startOfDay(),
        ]);

        $payload = $this->actingAs($borrower)
            ->getJson('/api/borrower/calendar?from='.now()->format('Y-m-d').'&to='.now()->addDays(7)->format('Y-m-d'))
            ->assertOk()
            ->json();

        $kinds = array_column($payload['events'], 'kind');
        $this->assertEqualsCanonicalizing(['pickup', 'period', 'due'], $kinds);

        foreach ($payload['events'] as $event) {
            $this->assertSame($loan->getKey(), $event['loan_id']);
            $this->assertFalse($event['tentative']);
        }

        $due = $this->eventByKind($payload['events'], 'due');
        $this->assertSame($loan->due_at->format('Y-m-d'), $due['date']);
        $this->assertFalse($due['done']);
    }

    public function test_calendar_marks_requested_loans_as_tentative_and_skips_rejected(): void
    {
        $borrower = $this->member(Membership::ROLE_BORROWER);

        $requested = $this->loanFor($borrower, ['status' => Loan::STATUS_REQUESTED]);
        $this->loanFor($borrower, ['status' => Loan::STATUS_REJECTED, 'rejection_reason' => 'Kebijakan']);
        $this->loanFor($borrower, ['status' => Loan::STATUS_CANCELLED]);

        $payload = $this->actingAs($borrower)
            ->getJson('/api/borrower/calendar?from='.now()->format('Y-m-d').'&to='.now()->addDays(7)->format('Y-m-d'))
            ->assertOk()
            ->json();

        $this->assertNotEmpty($payload['events']);

        foreach ($payload['events'] as $event) {
            $this->assertSame($requested->getKey(), $event['loan_id']);
            $this->assertTrue($event['tentative']);
            $this->assertNotSame('due', $event['kind']);
        }
    }

    public function test_calendar_never_returns_another_borrowers_schedule(): void
    {
        $borrower = $this->member(Membership::ROLE_BORROWER);
        $other = $this->member(Membership::ROLE_BORROWER);

        $foreign = $this->loanFor($other, [
            'status' => Loan::STATUS_CHECKED_OUT,
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->addDay(),
            'due_at' => now()->addDay(),
            'approved_at' => now()->subDay(),
            'checked_out_at' => now()->subDay(),
        ]);

        $payload = $this->actingAs($borrower)
            ->getJson('/api/borrower/calendar?from='.now()->subDays(7)->format('Y-m-d').'&to='.now()->addDays(7)->format('Y-m-d'))
            ->assertOk()
            ->json();

        $this->assertSame([], $payload['events']);
        $this->assertNotContains($foreign->getKey(), array_column($payload['events'], 'loan_id'));
    }

    public function test_calendar_rejects_a_range_longer_than_sixty_two_days(): void
    {
        $borrower = $this->member(Membership::ROLE_BORROWER);

        $this->actingAs($borrower)
            ->getJson('/api/borrower/calendar?from='.now()->format('Y-m-d').'&to='.now()->addDays(90)->format('Y-m-d'))
            ->assertStatus(422);
    }

    public function test_calendar_rejects_malformed_dates(): void
    {
        $borrower = $this->member(Membership::ROLE_BORROWER);

        $this->actingAs($borrower)
            ->getJson('/api/borrower/calendar?from=14-10-2026&to=2026-11-01')
            ->assertStatus(422);
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return array<string, mixed>
     */
    private function eventByKind(array $events, string $kind): array
    {
        foreach ($events as $event) {
            if ($event['kind'] === $kind) {
                return $event;
            }
        }

        $this->fail('Event '.$kind.' tidak ditemukan.');
    }
}
