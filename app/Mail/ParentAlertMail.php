<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Phase pilote (hp-v2nf) — e-mail au parent consentant. Il ne porte AUCUNE donnée
 * sur la situation (ni prénom, ni type, ni résumé) : un e-mail circule mal. Le résumé
 * ne se lit qu'une fois connecté à l'espace parent.
 */
class ParentAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly int $alertId) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'CareNest : une alerte importante concerne votre enfant');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.parent-alert');
    }
}
