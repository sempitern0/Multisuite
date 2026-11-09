<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PortalInvitation extends Notification
{
    public function __construct(public string $url) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Tu invitación a Multisuite')
            ->line('Te han invitado al portal privado Multisuite. El enlace caduca en 7 días y solo se puede usar una vez.')
            ->action('Aceptar invitación', $this->url)
            ->line('No compartas este enlace. Si no esperabas la invitación, puedes ignorarla.');
    }
}
