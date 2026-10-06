<?php

use App\Email\Imap\ImapGateway;
use App\Email\InboundEmailIngestor;
use App\Enums\EmailStatus;
use App\Jobs\FetchMailbox;
use App\Jobs\ProcessInboundEmail;
use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use App\Models\Mailbox;
use App\Models\Tenant;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeImap;

// E03: inbound email (section 9.2): stored raw, deduplicated, threaded,
// attachments kept within limits, then handed to triage.

beforeEach(function () {
    Storage::fake((string) config('mail_ingest.disk'));
    $this->tenant = Tenant::factory()->create();
    $this->mailbox = asTenant($this->tenant, fn () => Mailbox::factory()->create(['address' => 'triagem@agentes.micomoc.test']));
});

it('stores the raw message once, parses it and queues triage', function () {
    Queue::fake();

    asTenant($this->tenant, function () {
        $ingestor = app(InboundEmailIngestor::class);
        $message = $ingestor->ingest($this->mailbox, mailFixture('lead'), 'imap:1');

        expect($message->from_address)->toBe('a.macuacua@cimentospungue.co.mz')
            ->and($message->subject)->toBe('Pedido de proposta: reabilitação do cais de descarga na Beira')
            ->and($message->status)->toBe(EmailStatus::Received)
            ->and($message->plainText())->toContain('20 de Outubro')
            ->and(Storage::disk((string) config('mail_ingest.disk'))->exists((string) $message->raw_path))->toBeTrue();

        expect($ingestor->ingest($this->mailbox, mailFixture('lead'), 'imap:1'))->toBeNull()
            ->and(EmailMessage::query()->count())->toBe(1);
    });

    Queue::assertPushed(ProcessInboundEmail::class, 1);
});

it('threads a reply with the original', function () {
    Queue::fake();

    asTenant($this->tenant, function () {
        $ingestor = app(InboundEmailIngestor::class);
        $first = $ingestor->ingest($this->mailbox, mailFixture('lead'));
        $reply = $ingestor->ingest($this->mailbox, mailFixture('lead-reply'));

        expect($reply->thread_id)->not->toBeNull()->toBe($first->thread_id)
            ->and($first->thread->message_count)->toBe(2);
    });
});

it('keeps attachments and discards the ones over the limit', function () {
    Queue::fake();
    config(['mail_ingest.max_attachment_bytes' => 300]);

    asTenant($this->tenant, function () {
        $ingestor = app(InboundEmailIngestor::class);
        $statement = $ingestor->ingest($this->mailbox, mailFixture('bank-statement'));
        $attachment = $statement->attachments()->sole();

        expect($attachment->filename)->toBe('extracto-bci-2026-09.csv')
            ->and($attachment->path)->toBeNull()
            ->and($attachment->ocr_status)->toBe('discarded');

        config(['mail_ingest.max_attachment_bytes' => 1_000_000]);
        $invoice = $ingestor->ingest($this->mailbox, mailFixture('supplier-invoice'));

        expect($invoice->attachments()->sole()->path)->not->toBeNull();
    });
});

it('extracts attachment text and flags prompt injection before triage', function () {
    asTenant($this->tenant, function () {
        $ingestor = app(InboundEmailIngestor::class);

        $invoice = $ingestor->ingest($this->mailbox, mailFixture('supplier-invoice'))->fresh();
        $attack = $ingestor->ingest($this->mailbox, mailFixture('prompt-injection'))->fresh();

        expect($invoice->attachments()->sole()->extracted_text)->toContain('FT 2026/0877')
            ->and($invoice->hasFlag('prompt_injection'))->toBeFalse()
            ->and($attack->hasFlag('prompt_injection'))->toBeTrue()
            // No agent on this mailbox: kept for people, not triaged.
            ->and($attack->status)->toBe(EmailStatus::Processed);
    });
});

it('polls IMAP and only reads what is new', function () {
    Queue::fake([ProcessInboundEmail::class]);

    $imap = new FakeImap;
    $imap->deliver('INBOX', mailFixture('lead'));
    app()->instance(ImapGateway::class, $imap);

    asTenant($this->tenant, fn () => $this->mailbox->update(['imap_host' => 'imap.example.test']));

    FetchMailbox::dispatchSync($this->tenant->id, $this->mailbox->id);
    $imap->deliver('INBOX', mailFixture('lead-reply'));
    FetchMailbox::dispatchSync($this->tenant->id, $this->mailbox->id);

    asTenant($this->tenant, function () {
        expect(EmailMessage::query()->count())->toBe(2)
            ->and($this->mailbox->fresh()->imap_last_uid)->toBe(2)
            ->and(EmailMessage::query()->pluck('imap_uid')->sort()->values()->all())->toBe([1, 2]);
    });

    Queue::assertPushed(ProcessInboundEmail::class, 2);
});

it('ingests a file from the command line and prunes raw mail past retention', function () {
    Queue::fake();
    $path = tempnam(sys_get_temp_dir(), 'eml');
    file_put_contents($path, mailFixture('supplier-invoice'));

    $this->artisan('mail:ingest', ['tenant' => $this->tenant->slug, 'mailbox' => 'triagem@agentes.micomoc.test', 'file' => $path])->assertSuccessful();
    @unlink($path);

    $this->tenant->update(['settings' => ['email_retention_days' => 30]]);
    asTenant($this->tenant, fn () => EmailMessage::query()->update(['received_at' => now()->subDays(31)]));

    $this->artisan('mail:prune')->assertSuccessful();

    asTenant($this->tenant, function () {
        $message = EmailMessage::query()->sole();

        expect($message->raw_path)->toBeNull()
            ->and(EmailAttachment::query()->sole()->path)->toBeNull()
            ->and($message->text_body)->not->toBeEmpty();
    });
});
