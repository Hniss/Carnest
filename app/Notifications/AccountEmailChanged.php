<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Prévient l'ANCIENNE adresse qu'elle n'est plus celle du compte CareNest (audit sécurité 2026-10-05, F9). */
class AccountEmailChanged extends Notification
{
    use Queueable;

    public function __construct(private string $accountName)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('CareNest : adresse e-mail de votre compte modifiée')
            ->greeting('Bonjour ' . $this->accountName . ',')
            ->line('L\'adresse e-mail de votre compte CareNest vient d\'être remplacée par l\'administration de votre école. Cette adresse-ci n\'est plus associée au compte.')
            ->line('Si vous n\'êtes pas à l\'origine de cette demande, contactez sans attendre la direction de votre école.');
    }
}
