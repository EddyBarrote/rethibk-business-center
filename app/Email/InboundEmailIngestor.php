<?php

namespace App\Email;

use App\Enums\EmailStatus;
use App\Jobs\ProcessInboundEmail;
use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use App\Models\Mailbox;
use finfo;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;
use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\IMessage;
use ZBateson\MailMimeParser\Message;

/**
 * The inbound pipeline of section 9.2 from step 2 on (IMAP replaces the
 * webhook, docs/DECISOES.md): deduplicate, keep the raw message, record the
 * message and its attachments, thread it, and queue the processing.
 */
final class InboundEmailIngestor
{
    public function __construct(private readonly ThreadResolver $threads) {}

    /**
     * @return EmailMessage|null null when the message was already ingested
     */
    public function ingest(Mailbox $mailbox, string $raw, ?string $providerId = null): ?EmailMessage
    {
        $parsed = Message::from($raw, false);
        $messageId = $this->messageId($parsed, $raw);
        $hash = hash('sha256', $messageId.'|'.$mailbox->id);

        if (EmailMessage::query()->where('mailbox_id', $mailbox->id)->where('dedup_hash', $hash)->exists()) {
            return null;
        }

        $disk = (string) config('mail_ingest.disk');
        $base = "tenants/{$mailbox->tenant_id}/mail/".now()->format('Y/m')."/{$hash}";
        $tooBig = strlen($raw) > (int) config('mail_ingest.max_message_bytes');

        // Step 3: the raw message is on disk before anything else is trusted.
        Storage::disk($disk)->put("{$base}.eml", $raw);

        $from = $parsed->getHeader('From');
        $to = $this->addresses($parsed, 'To');
        $cc = $this->addresses($parsed, 'Cc');
        $subject = Str::limit((string) $parsed->getSubject(), 250, '');
        $references = array_values(array_filter(preg_split('/\s+/', (string) $parsed->getHeaderValue('References')) ?: []));
        $inReplyTo = $this->bracketed($parsed->getHeaderValue('In-Reply-To'));
        $fromAddress = $from instanceof AddressHeader ? Str::lower((string) $from->getEmail()) : null;

        try {
            $message = DB::transaction(function () use ($mailbox, $parsed, $providerId, $messageId, $hash, $base, $from, $fromAddress, $to, $cc, $subject, $references, $inReplyTo, $disk, $tooBig) {
                $thread = $this->threads->resolve($mailbox, $inReplyTo, array_map(fn ($r) => (string) $this->bracketed($r), $references), $subject, array_values(array_filter([$fromAddress, ...$to, ...$cc])));

                $message = EmailMessage::query()->create([
                    'mailbox_id' => $mailbox->id,
                    'direction' => 'inbound',
                    'provider_message_id' => $providerId,
                    'message_id_header' => $messageId,
                    'in_reply_to' => $inReplyTo,
                    'references' => implode(' ', $references) ?: null,
                    'thread_id' => $thread->id,
                    'from_address' => $fromAddress,
                    'from_name' => $from instanceof AddressHeader ? $from->getPersonName() : null,
                    'to' => $to,
                    'cc' => $cc,
                    'reply_to' => $this->addresses($parsed, 'Reply-To')[0] ?? null,
                    'subject' => $subject,
                    'text_body' => $tooBig ? null : $this->limit($parsed->getTextContent()),
                    'html_body' => $tooBig ? null : $this->limit($parsed->getHtmlContent()),
                    'raw_path' => "{$base}.eml",
                    'status' => EmailStatus::Received,
                    'received_at' => $this->date($parsed),
                    'dedup_hash' => $hash,
                    'flags' => $tooBig ? ['oversized'] : null,
                ]);

                $this->storeAttachments($message, $parsed, $disk, $base, $tooBig);

                $thread->forceFill([
                    'message_count' => $thread->message_count + 1,
                    'last_message_at' => $message->received_at,
                ])->save();

                return $message;
            });
        } catch (UniqueConstraintViolationException) {
            // Another worker took it first.
            return null;
        }

        $mailbox->forceFill(['last_inbound_at' => now()])->save();

        ProcessInboundEmail::dispatch($message->tenant_id, $message->id)->onQueue('email');

        return $message;
    }

    private function storeAttachments(EmailMessage $message, IMessage $parsed, string $disk, string $base, bool $tooBig): void
    {
        $maxBytes = (int) config('mail_ingest.max_attachment_bytes');

        foreach ($parsed->getAllAttachmentParts() as $index => $part) {
            $filename = Str::limit(basename((string) ($part->getFilename() ?: "anexo-{$index}")), 200, '');
            $binary = (string) $part->getBinaryContentStream()?->getContents();
            $size = strlen($binary);

            if ($tooBig || $size > $maxBytes) {
                EmailAttachment::query()->create([
                    'email_message_id' => $message->id,
                    'filename' => $filename,
                    'mime_type' => $part->getContentType(),
                    'size_bytes' => $size,
                    'ocr_status' => 'discarded',
                ]);

                continue;
            }

            $safeName = Str::slug(pathinfo($filename, PATHINFO_FILENAME)) ?: 'anexo';
            $extension = Str::lower(preg_replace('/[^a-z0-9]/i', '', pathinfo($filename, PATHINFO_EXTENSION)) ?? '');
            $path = "{$base}/{$index}-{$safeName}".($extension !== '' ? ".{$extension}" : '');
            Storage::disk($disk)->put($path, $binary);

            // MIME from the bytes, never from the sender's header (section 9.4).
            $realMime = (new finfo(FILEINFO_MIME_TYPE))->buffer($binary) ?: 'application/octet-stream';

            EmailAttachment::query()->create([
                'email_message_id' => $message->id,
                'filename' => $filename,
                'mime_type' => $realMime,
                'size_bytes' => $size,
                'disk' => $disk,
                'path' => $path,
                'ocr_status' => 'pending',
            ]);
        }
    }

    private function messageId(IMessage $parsed, string $raw): string
    {
        $id = $this->bracketed($parsed->getMessageId() ? '<'.trim((string) $parsed->getMessageId(), '<>').'>' : null);

        // Without a Message-ID the content itself identifies the message.
        return $id ?? '<sha256-'.hash('sha256', $raw).'@sem-message-id>';
    }

    private function bracketed(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return '<'.trim($value, '<> ').'>';
    }

    /**
     * @return list<string>
     */
    private function addresses(IMessage $parsed, string $header): array
    {
        $value = $parsed->getHeader($header);

        if (! $value instanceof AddressHeader) {
            return [];
        }

        return array_values(array_unique(array_map(fn ($a) => Str::lower($a->getEmail()), $value->getAddresses())));
    }

    private function date(IMessage $parsed): Carbon
    {
        try {
            $date = $parsed->getHeaderValue('Date');

            return $date ? Carbon::parse($date)->utc() : now();
        } catch (Throwable) {
            return now();
        }
    }

    private function limit(?string $text): ?string
    {
        return $text === null ? null : mb_substr(mb_convert_encoding($text, 'UTF-8', 'UTF-8'), 0, 500_000);
    }
}
