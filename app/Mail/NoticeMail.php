<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A notification by email, for the kinds a person chose in
 * Definições › Notificações, with a link that opens it in the console.
 */
class NoticeMail extends Mailable
{
    public function __construct(public string $title, public string $body, public ?string $sender, public ?string $link) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->title);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.notice', with: ['title' => $this->title, 'body' => $this->body, 'from' => $this->sender, 'link' => $this->link]);
    }
}
