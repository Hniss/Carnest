<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Lot 2 §2 — notification technique H&Y (étape 2 d'escalade), sans donnée nominative. */
class OpsEscalationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly int $alertId,
        public readonly int $schoolId,
        public readonly int $minutesElapsed,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'CareNest : escalade sans accusé (école #' . $this->schoolId . ')');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.ops-escalation');
    }
}
