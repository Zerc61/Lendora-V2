<?php

namespace Tests\Feature\Notification;

use App\Models\Loan;
use App\Models\Membership;
use App\Notifications\LoanDueSoon;
use App\Notifications\LoanOverdue;
use App\Notifications\LoanPickupSoon;
use Tests\Support\TenantTestCase;

/**
 * Pengingat terjadwal: peristiwa waktu diambil dari status loan, dan tidak
 * pernah dobel meski pekerjaan dijalankan berulang.
 */
class SendLoanRemindersTest extends TenantTestCase
{
    private function checkedOutDueIn(int $hours): Loan
    {
        $borrower = $this->member(Membership::ROLE_BORROWER);

        return Loan::factory()->checkedOut()->create([
            'organization_id' => $this->organization->getKey(),
            'borrower_id' => $borrower->getKey(),
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->addHours($hours),
            'due_at' => now()->addHours($hours),
        ]);
    }

    public function test_sends_due_soon_reminder_once(): void
    {
        $loan = $this->checkedOutDueIn(12);

        $this->artisan('loans:send-reminders')->assertSuccessful();

        $this->assertSame(1, $loan->borrower->notifications()->where('type', LoanDueSoon::class)->count());

        $this->artisan('loans:send-reminders')->assertSuccessful();

        $this->assertSame(1, $loan->borrower->notifications()->where('type', LoanDueSoon::class)->count());
    }

    public function test_does_not_send_due_soon_reminder_for_a_far_deadline(): void
    {
        $loan = $this->checkedOutDueIn(24 * 10);

        $this->artisan('loans:send-reminders')->assertSuccessful();

        $this->assertSame(0, $loan->borrower->notifications()->where('type', LoanDueSoon::class)->count());
    }

    public function test_sends_overdue_reminder_once_per_day(): void
    {
        $loan = $this->checkedOutDueIn(-6);

        $this->artisan('loans:send-reminders')->assertSuccessful();
        $this->artisan('loans:send-reminders')->assertSuccessful();

        $this->assertSame(1, $loan->borrower->notifications()->where('type', LoanOverdue::class)->count());
    }

    public function test_sends_pickup_reminder_only_inside_the_window(): void
    {
        $inside = $this->approvedStartingIn(6);
        $outside = $this->approvedStartingIn(24 * 5);

        $this->artisan('loans:send-reminders')->assertSuccessful();

        $this->assertSame(1, $inside->borrower->notifications()->where('type', LoanPickupSoon::class)->count());
        $this->assertSame(0, $outside->borrower->notifications()->where('type', LoanPickupSoon::class)->count());

        $this->artisan('loans:send-reminders')->assertSuccessful();

        $this->assertSame(1, $inside->borrower->notifications()->where('type', LoanPickupSoon::class)->count());
    }

    public function test_overdue_reminder_is_not_sent_for_returned_loans(): void
    {
        $borrower = $this->member(Membership::ROLE_BORROWER);

        $returned = Loan::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'borrower_id' => $borrower->getKey(),
            'status' => Loan::STATUS_RETURNED,
            'starts_at' => now()->subDays(7),
            'ends_at' => now()->subDays(5),
            'due_at' => now()->subDays(4),
            'approved_at' => now()->subDays(6),
            'checked_out_at' => now()->subDays(6),
            'returned_at' => now()->subDays(3),
        ]);

        $this->artisan('loans:send-reminders')->assertSuccessful();

        $this->assertSame(0, $returned->borrower->notifications()->where('type', LoanOverdue::class)->count());
    }

    private function approvedStartingIn(int $hours): Loan
    {
        $borrower = $this->member(Membership::ROLE_BORROWER);

        return Loan::factory()->approved()->create([
            'organization_id' => $this->organization->getKey(),
            'borrower_id' => $borrower->getKey(),
            'starts_at' => now()->addHours($hours),
            'ends_at' => now()->addHours($hours)->addDays(3),
            'due_at' => now()->addHours($hours)->addDays(3),
        ]);
    }
}
