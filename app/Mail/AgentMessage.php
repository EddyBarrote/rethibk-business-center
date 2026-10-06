<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * Plain-text email written by an agent.
 */
class AgentMessage extends Mailable
{
    /**
     * @param  list<string>  $toAddresses
     * @param  list<string>  $ccAddresses
     */
    public function __construct(
        public string $fromAddress,
        public string $fromName,
        public array $toAddresses,
        public array $ccAddresses,
        public string $subjectLine,
        public string $body,
        public ?string $inReplyTo = null,
        public ?string $references = null,
    ) {}

    public function headers(): Headers
    {
        $references = array_values(array_filter(preg_split('/\s+/', (string) $this->references) ?: []));

        return new Headers(
            references: array_map(fn (string $id) => trim($id, '<>'), $references),
            text: $this->inReplyTo !== null ? ['In-Reply-To' => $this->inReplyTo] : [],
        );
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->fromAddress, $this->fromName),
            to: $this->toAddresses,
            cc: $this->ccAddresses,
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(text: 'mail.agent-message', with: ['body' => $this->body]);
    }
}
