<?php

namespace App\Notifications;

/**
 * Pengajuan disetujui; peminjam diberi tahu lewat in-app dan email.
 */
class LoanApproved extends AppNotification
{
    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }
}
