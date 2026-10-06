<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * Plain-text email written by an agent or by a person in the email client,
 * with its files.
 */
class AgentMessage extends Mailable
{
    /**
     * @param  list<string>  $toAddresses
     * @param  list<string>  $ccAddresses
     * @param  list<string>  $bccAddresses
     * @param  list<array{disk: string, path: string, name: string, mime: string|null}>  $files
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
        public array $bccAddresses = [],
        public array $files = [],
        public ?string $messageId = null,
    ) {}

    public function headers(): Headers
    {
        $references = array_values(array_filter(preg_split('/\s+/', (string) $this->references) ?: []));

        return new Headers(
            messageId: $this->messageId !== null ? trim($this->messageId, '<>') : null,
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
            bcc: $this->bccAddresses,
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(text: 'mail.agent-message', with: ['body' => $this->body]);
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return array_map(
            fn (array $file) => Attachment::fromStorageDisk($file['disk'], $file['path'])->as($file['name'])->withMime($file['mime'] ?? 'application/octet-stream'),
            $this->files,
        );
    }
}
