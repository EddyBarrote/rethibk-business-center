<?php

namespace App\Mail;

use App\Models\Briefing;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The briefing delivered by email, with a link to open it in the console.
 */
class BriefingMail extends Mailable
{
    public function __construct(public Briefing $briefing, public string $baseUrl) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->briefing->title);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.briefing', with: ['briefing' => $this->briefing, 'base' => rtrim($this->baseUrl, '/')]);
    }
}
