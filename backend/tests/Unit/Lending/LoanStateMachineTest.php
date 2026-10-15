<?php

namespace Tests\Unit\Lending;

use App\Models\Loan;
use Tests\TestCase;

/**
 * State machine Loan diuji terpisah dari database: label, graf transisi, dan nilai
 * turunan (Testing Strategy C.1, C.2; ADR-003).
 */
class LoanStateMachineTest extends TestCase
{
    public function test_every_status_has_a_unique_human_label(): void
    {
        foreach (Loan::STATUSES as $status) {
            $this->assertArrayHasKey($status, Loan::STATUS_LABELS);
            $this->assertNotSame('', Loan::STATUS_LABELS[$status]);
        }

        $this->assertSame(
            count(Loan::STATUSES),
            count(array_unique(Loan::STATUS_LABELS)),
        );
    }

    public function test_only_transitions_in_the_table_are_allowed(): void
    {
        foreach (Loan::STATUSES as $from) {
            foreach (Loan::STATUSES as $to) {
                $loan = new Loan(['status' => $from]);
                $expected = in_array($to, Loan::TRANSITIONS[$from] ?? [], true);

                $this->assertSame(
                    $expected,
                    $loan->canTransitionTo($to),
                    "Transisi {$from} -> {$to} tidak sesuai tabel.",
                );
            }
        }
    }

    public function test_terminal_statuses_have_no_outgoing_transition(): void
    {
        foreach (Loan::TERMINAL_STATUSES as $status) {
            $this->assertSame([], Loan::TRANSITIONS[$status] ?? []);
            $this->assertTrue((new Loan(['status' => $status]))->isTerminal());
        }
    }

    public function test_expired_is_derived_from_pickup_window(): void
    {
        $overdue = new Loan(['status' => Loan::STATUS_APPROVED, 'approved_at' => now()->subHours(49)]);
        $this->assertTrue($overdue->isExpired(48));

        $within = new Loan(['status' => Loan::STATUS_APPROVED, 'approved_at' => now()->subHours(47)]);
        $this->assertFalse($within->isExpired(48));

        $requested = new Loan(['status' => Loan::STATUS_REQUESTED]);
        $this->assertFalse($requested->isExpired(48));
    }

    public function test_overdue_is_derived_only_while_checked_out(): void
    {
        $late = new Loan(['status' => Loan::STATUS_CHECKED_OUT, 'due_at' => now()->subDay()]);
        $this->assertTrue($late->isOverdue());

        $approved = new Loan(['status' => Loan::STATUS_APPROVED, 'due_at' => now()->subDay()]);
        $this->assertFalse($approved->isOverdue());
    }
}
