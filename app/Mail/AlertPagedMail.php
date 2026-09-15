<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Lot 2 §2 — e-mail de paging au référent (et relance). Texte SANS donnée nominative :
 * ni prénom, ni type, ni résumé. Seul l'identifiant technique de l'alerte est transmis.
 */
class AlertPagedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly int $alertId,
        public readonly int $step = 0,
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->step === 0
            ? 'CareNest : une alerte attend votre accusé'
            : 'CareNest : rappel, une alerte attend toujours votre accusé';

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.alert-paged');
    }
}
