<?php

namespace Tests\Feature\Notification;

use App\Models\Membership;
use App\Notifications\LoanReturnConfirmed;
use Tests\Support\TenantTestCase;

/**
 * Pusat notifikasi: hanya milik user login, penandaan dibaca bertahan di
 * database, dan ID asing tidak pernah bocor.
 */
class NotificationTest extends TenantTestCase
{
    private function notifyBorrower(object $borrower, string $title): void
    {
        $borrower->notify(new LoanReturnConfirmed([
            'key' => 'loan.return_confirmed',
            'title' => $title,
            'body' => 'Pengembalian alat telah dikonfirmasi petugas.',
            'action_url' => '/app/loans/1',
            'action_label' => 'Lihat detail',
            'organization_id' => $this->organization->getKey(),
            'organization_name' => $this->organization->name,
            'loan_id' => 1,
        ]));
    }

    public function test_guest_cannot_list_notifications(): void
    {
        $this->getJson('/api/notifications')->assertUnauthorized();
    }

    public function test_borrower_only_sees_own_notifications(): void
    {
        $borrower = $this->member(Membership::ROLE_BORROWER);
        $other = $this->member(Membership::ROLE_BORROWER);

        $this->notifyBorrower($borrower, 'Milikku');
        $this->notifyBorrower($other, 'Milik orang lain');

        $payload = $this->actingAs($borrower)
            ->getJson('/api/notifications')
            ->assertOk()
            ->json();

        $this->assertCount(1, $payload['data']);
        $this->assertSame('Milikku', $payload['data'][0]['title']);
        $this->assertSame('loan.return_confirmed', $payload['data'][0]['key']);
        $this->assertNull($payload['data'][0]['read_at']);
    }

    public function test_unread_count_reflects_read_state(): void
    {
        $borrower = $this->member(Membership::ROLE_BORROWER);

        $this->notifyBorrower($borrower, 'Satu');
        $this->notifyBorrower($borrower, 'Dua');

        $this->actingAs($borrower)
            ->getJson('/api/notifications/unread-count')
            ->assertOk()
            ->assertJson(['count' => 2]);

        $id = $borrower->notifications()->first()->getKey();

        $response = $this->actingAs($borrower)
            ->postJson('/api/notifications/'.$id.'/read')
            ->assertOk();

        $this->assertNotNull($response->json('data.read_at'));
        $this->assertNotNull($borrower->notifications()->find($id)->read_at);

        $this->actingAs($borrower)
            ->getJson('/api/notifications/unread-count')
            ->assertOk()
            ->assertJson(['count' => 1]);
    }

    public function test_mark_all_read_persists_every_notification(): void
    {
        $borrower = $this->member(Membership::ROLE_BORROWER);

        $this->notifyBorrower($borrower, 'Satu');
        $this->notifyBorrower($borrower, 'Dua');

        $this->actingAs($borrower)
            ->postJson('/api/notifications/read-all')
            ->assertOk()
            ->assertJson(['updated' => 2, 'count' => 0]);

        $this->assertSame(0, $borrower->notifications()->whereNull('read_at')->count());
    }

    public function test_borrower_cannot_mark_another_users_notification_read(): void
    {
        $borrower = $this->member(Membership::ROLE_BORROWER);
        $other = $this->member(Membership::ROLE_BORROWER);

        $this->notifyBorrower($other, 'Milik orang lain');
        $foreignId = $other->notifications()->first()->getKey();

        $this->actingAs($borrower)
            ->postJson('/api/notifications/'.$foreignId.'/read')
            ->assertNotFound();

        $this->assertNull($other->notifications()->first()->read_at);
    }

    public function test_unread_filter_returns_only_unread(): void
    {
        $borrower = $this->member(Membership::ROLE_BORROWER);

        $this->notifyBorrower($borrower, 'Sudah dibaca');
        $this->notifyBorrower($borrower, 'Belum dibaca');

        $readId = $borrower->notifications()->first()->getKey();
        $this->actingAs($borrower)->postJson('/api/notifications/'.$readId.'/read')->assertOk();

        $payload = $this->actingAs($borrower)
            ->getJson('/api/notifications?unread=1')
            ->assertOk()
            ->json();

        $this->assertCount(1, $payload['data']);
        $this->assertSame('Belum dibaca', $payload['data'][0]['title']);
    }

    public function test_database_notification_carries_loan_reference_for_navigation(): void
    {
        $borrower = $this->member(Membership::ROLE_BORROWER);

        $this->notifyBorrower($borrower, 'Dengan tautan');

        $payload = $this->actingAs($borrower)
            ->getJson('/api/notifications')
            ->assertOk()
            ->json();

        $this->assertSame(1, $payload['data'][0]['loan_id']);
        $this->assertSame('/app/loans/1', $payload['data'][0]['action_url']);
    }
}
