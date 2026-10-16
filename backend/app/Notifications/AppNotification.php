<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Dasar notifikasi Lendora.
 *
 * Semua peristiwa memakai satu bentuk payload agar resource API dan UI membaca
 * skema yang sama. Kanal in-app (`database`) selalu aktif; peristiwa yang juga
 * dikirim lewat email menambahkan `mail` di `via()`.
 */
abstract class AppNotification extends Notification
{
    /**
     * @param  array{
     *     key: string,
     *     title: string,
     *     body: string,
     *     action_url: string,
     *     action_label: string,
     *     organization_id?: int,
     *     organization_name?: string,
     *     loan_id?: int|null
     * }  $payload
     */
    public function __construct(public readonly array $payload) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return $this->payload;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->payload['title'])
            ->line($this->payload['body'])
            ->action($this->payload['action_label'], url($this->payload['action_url']));
    }
}
