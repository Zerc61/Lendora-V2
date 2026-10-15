<?php

namespace App\Notifications;

/**
 * Pengajuan ditolak; alasan ikut dalam payload dan dikirim lewat email juga.
 */
class LoanRejected extends AppNotification
{
    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }
}
