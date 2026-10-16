<?php

use App\Email\Imap\ImapGateway;
use App\Email\MailboxSync;
use App\Enums\EmailStatus;
use App\Enums\Permission;
use App\Events\MailboxUpdated;
use App\Jobs\FetchMailbox;
use App\Jobs\ProcessInboundEmail;
use App\Jobs\PushDraftToServer;
use App\Mail\AgentMessage;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\EmailAttachment;
use App\Models\EmailFolder;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Models\Mailbox;
use App\Models\MailboxOwner;
use App\Models\MailboxReader;
use App\Models\MessageAttachment;
use App\Models\TaskMessage;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeImap;

// The email client (docs/DECISOES.md, "Email: um cliente de email na plataforma").

function rawEmail(string $id, string $subject, string $from = 'cliente@fora.test', string $to = 'ana@empresa.test', string $extra = ''): string
{
    return "Message-ID: <{$id}>\r\nFrom: Cliente <{$from}>\r\nTo: {$to}\r\nSubject: {$subject}\r\nDate: Tue, 06 Oct 2026 10:00:00 +0200\r\n{$extra}Content-Type: text/plain; charset=utf-8\r\n\r\nTexto de {$subject}.\r\n";
}

beforeEach(function () {
    Queue::fake([ProcessInboundEmail::class, PushDraftToServer::class]);
    Mail::fake();
    Storage::fake('local');

    $this->imap = new FakeImap;
    $this->imap->addFolder('Archive', ['\\Archive']);
    $this->imap->addFolder('Clientes');
    app()->instance(ImapGateway::class, $this->imap);

    $this->tenant = Tenant::factory()->create();

    [$this->ana, $this->owner, $this->mailbox] = asTenant($this->tenant, function () {
        $ana = User::factory()->create(['role' => 'member']);
        $mailbox = Mailbox::factory()->personal()->create(['address' => 'ana@empresa.test', 'imap_host' => 'imap.empresa.test', 'imap_encryption' => 'ssl']);
        MailboxOwner::factory()->create(['mailbox_id' => $mailbox->id, 'user_id' => $ana->id]);

        return [$ana, User::factory()->owner()->create(), $mailbox];
    });
});

function syncAna(object $test): void
{
    asTenant($test->tenant, fn () => app(MailboxSync::class)->run($test->mailbox->fresh()));
}

it('mirrors the server folders, files each message in its folder, and hands only new INBOX email to the agents', function () {
    $this->imap->deliver('INBOX', rawEmail('a@fora', 'Pedido de cotação'));
    $this->imap->deliver('INBOX', rawEmail('b@fora', 'Já lido'), ['\\Seen', '\\Flagged']);
    $this->imap->deliver('Sent', rawEmail('c@empresa', 'Proposta enviada', 'ana@empresa.test', 'cliente@fora.test'), ['\\Seen']);
    $this->imap->deliver('Clientes', rawEmail('d@fora', 'Contrato'));

    syncAna($this);

    asTenant($this->tenant, function () {
        $folders = EmailFolder::query()->where('mailbox_id', $this->mailbox->id)->pluck('role', 'path');
        expect($folders->all())->toMatchArray(['INBOX' => 'inbox', 'Sent' => 'sent', 'Drafts' => 'drafts', 'Trash' => 'trash', 'Junk' => 'junk', 'Archive' => 'archive', 'Clientes' => null]);

        $sent = EmailMessage::query()->where('subject', 'Proposta enviada')->sole();
        $read = EmailMessage::query()->where('subject', 'Já lido')->sole();

        expect($sent->direction)->toBe('outbound')->and($sent->status)->toBe(EmailStatus::Sent)
            ->and($sent->folder->role)->toBe('sent')
            ->and($read->read_at)->not->toBeNull()->and($read->is_flagged)->toBeTrue()
            ->and(EmailMessage::query()->where('subject', 'Contrato')->sole()->folder->path)->toBe('Clientes')
            ->and(EmailFolder::query()->where('path', 'INBOX')->sole()->unread)->toBe(1);
    });

    // Only the INBOX goes to the agents.
    Queue::assertPushed(ProcessInboundEmail::class, 2);
});

it('follows what another client did: read, moved and deleted', function () {
    $uid = $this->imap->deliver('INBOX', rawEmail('a@fora', 'Lido no telemóvel'));
    $this->imap->deliver('INBOX', rawEmail('b@fora', 'Apagado no Outlook'));
    $moved = $this->imap->deliver('INBOX', rawEmail('c@fora', 'Movido no Outlook'));
    syncAna($this);

    $this->imap->select('INBOX');
    $this->imap->addFlags([$uid], ['\\Seen']);
    $this->imap->delete([2]);
    $this->imap->move([$moved], 'Clientes');
    syncAna($this);

    asTenant($this->tenant, function () {
        expect(EmailMessage::query()->where('subject', 'Lido no telemóvel')->sole()->read_at)->not->toBeNull()
            ->and(EmailMessage::query()->where('subject', 'Apagado no Outlook')->sole()->removed_at)->not->toBeNull()
            ->and(EmailMessage::query()->where('subject', 'Movido no Outlook')->sole()->folder->path)->toBe('Clientes')
            ->and(EmailMessage::query()->count())->toBe(3);
    });
});

it('reads, flags, archives, moves and deletes on the server first', function () {
    $this->imap->deliver('INBOX', rawEmail('a@fora', 'Para arquivar'));
    syncAna($this);
    $message = asTenant($this->tenant, fn () => EmailMessage::query()->sole());

    $this->actingAs($this->ana)->get(tenantUrl($this->tenant, "inbox/{$message->id}"))->assertOk()
        ->assertInertia(fn ($page) => $page->component('Email/Index')->where('open.id', $message->id)->where('open.can_reply', true));
    expect($this->imap->in('INBOX')[1]['flags'])->toContain('\\Seen');

    $this->actingAs($this->ana)->post(tenantUrl($this->tenant, "inbox/{$message->id}/flag"), ['flagged' => true])->assertRedirect();
    $this->actingAs($this->ana)->post(tenantUrl($this->tenant, "inbox/{$message->id}/archive"))->assertSessionHas('success');

    expect($this->imap->in('INBOX'))->toBeEmpty()
        ->and(collect($this->imap->in('Archive'))->first()['flags'])->toContain('\\Flagged');

    $clientes = asTenant($this->tenant, fn () => EmailFolder::query()->where('path', 'Clientes')->sole());
    $this->actingAs($this->ana)->post(tenantUrl($this->tenant, "inbox/{$message->id}/move"), ['folder_id' => $clientes->id])->assertSessionHas('success');
    expect($this->imap->in('Clientes'))->toHaveCount(1);

    // To the Lixo, then deleted for good from there.
    $this->actingAs($this->ana)->delete(tenantUrl($this->tenant, "inbox/{$message->id}"))->assertSessionHas('success', 'Movido para o Lixo.');
    expect($this->imap->in('Trash'))->toHaveCount(1);
    $this->actingAs($this->ana)->delete(tenantUrl($this->tenant, "inbox/{$message->id}"))->assertSessionHas('success', 'Apagado.');

    expect($this->imap->in('Trash'))->toBeEmpty()
        ->and(asTenant($this->tenant, fn () => $message->fresh()->removed_at))->not->toBeNull();
});

it('writes a new email with a file: the draft goes to the server Rascunhos, and sending leaves only the sent one', function () {
    syncAna($this);
    $upload = asTenant($this->tenant, function () {
        Storage::disk('local')->put('uploads/orcamento.pdf', '%PDF-1.4 orçamento');

        return MessageAttachment::factory()->create(['uploaded_by_user_id' => $this->ana->id, 'task_id' => null, 'filename' => 'orcamento.pdf', 'mime_type' => 'application/pdf', 'disk' => 'local', 'path' => 'uploads/orcamento.pdf', 'status' => 'ready']);
    });

    $this->actingAs($this->ana)->post(tenantUrl($this->tenant, 'inbox/drafts'), [
        'mailbox_id' => $this->mailbox->id, 'to' => ['cliente@fora.test'], 'subject' => 'Orçamento', 'body' => 'Segue o orçamento.', 'uploads' => [$upload->id],
    ])->assertSessionHas('success', 'Rascunho guardado.');

    $draft = asTenant($this->tenant, fn () => EmailMessage::query()->where('status', EmailStatus::Draft)->sole());
    $onServer = collect($this->imap->in('Drafts'))->first();
    expect($onServer['flags'])->toContain('\\Draft')
        ->and($onServer['raw'])->toContain('orcamento.pdf')
        ->and($draft->written_by_user_id)->toBe($this->ana->id)
        ->and(asTenant($this->tenant, fn () => $draft->attachments()->count()))->toBe(1);

    $this->actingAs($this->ana)->post(tenantUrl($this->tenant, "inbox/{$draft->id}/send"), [
        'mailbox_id' => $this->mailbox->id, 'to' => ['cliente@fora.test'], 'cc' => ['chefe@fora.test'], 'subject' => 'Orçamento', 'body' => 'Segue o orçamento.',
        'keep' => asTenant($this->tenant, fn () => $draft->attachments()->pluck('id')->all()),
    ])->assertSessionHas('success', 'Email enviado.');

    Mail::assertSent(AgentMessage::class, fn (AgentMessage $mail) => $mail->hasTo('cliente@fora.test') && $mail->hasCc('chefe@fora.test') && count($mail->files) === 1);
    expect($this->imap->in('Drafts'))->toBeEmpty()
        ->and(asTenant($this->tenant, fn () => $draft->fresh()))->status->toBe(EmailStatus::Sent)
        ->and(asTenant($this->tenant, fn () => $draft->fresh()->folder->role))->toBe('sent');
});

it('replies from the mailbox of the email, threaded, and forwards with its files', function () {
    $this->imap->deliver('INBOX', rawEmail('pedido@fora', 'Pedido'));
    syncAna($this);
    $original = asTenant($this->tenant, fn () => EmailMessage::query()->sole());

    $this->actingAs($this->ana)->post(tenantUrl($this->tenant, 'inbox/send'), [
        'mailbox_id' => $this->mailbox->id, 'reply_to_id' => $original->id, 'to' => ['cliente@fora.test'], 'subject' => 'Re: Pedido', 'body' => 'Obrigada.',
    ])->assertSessionHas('success');

    Mail::assertSent(AgentMessage::class, fn (AgentMessage $mail) => $mail->inReplyTo === '<pedido@fora>');
    expect(asTenant($this->tenant, fn () => EmailMessage::query()->where('subject', 'Re: Pedido')->sole()->thread_id))->toBe($original->thread_id);
});

it('puts an agent draft in the owner Rascunhos on the server', function () {
    syncAna($this);

    asTenant($this->tenant, function () {
        $agent = Agent::factory()->create();
        MailboxReader::factory()->create(['mailbox_id' => $this->mailbox->id, 'agent_id' => $agent->id]);
        $email = EmailMessage::factory()->create(['mailbox_id' => $this->mailbox->id, 'message_id_header' => '<pedido@fora>']);

        runCapability($agent, 'email.draft_reply', ['email_id' => $email->id, 'body' => 'Proposta de resposta.']);
    });

    $draft = asTenant($this->tenant, fn () => EmailMessage::query()->where('status', EmailStatus::Draft)->sole());
    expect($draft->mailbox_id)->toBe($this->mailbox->id);
    Queue::assertPushed(PushDraftToServer::class, fn (PushDraftToServer $job) => $job->messageId === $draft->id);
    asTenant($this->tenant, fn () => app()->call([new PushDraftToServer($this->tenant->id, $draft->id), 'handle']));

    $onServer = collect($this->imap->in('Drafts'))->first();
    expect($onServer['raw'])->toContain('Proposta de resposta.')->toContain('In-Reply-To: <pedido@fora>');
});

it('never opens or writes from someone else mailbox, not even for an administrator', function () {
    $this->imap->deliver('INBOX', rawEmail('a@fora', 'Privado'));
    syncAna($this);
    [$message, $folder] = asTenant($this->tenant, fn () => [EmailMessage::query()->sole(), EmailFolder::query()->where('path', 'INBOX')->sole()]);

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, 'inbox'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('mailboxes', fn ($boxes) => collect($boxes)->doesntContain('id', $this->mailbox->id)));
    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, "inbox/{$message->id}"))->assertForbidden();
    $this->actingAs($this->owner)->post(tenantUrl($this->tenant, 'inbox/sync'), ['mailbox_id' => $this->mailbox->id])->assertForbidden();
    $this->actingAs($this->owner)->post(tenantUrl($this->tenant, "inbox/folders/{$folder->id}/older"))->assertForbidden();
    $this->actingAs($this->owner)->post(tenantUrl($this->tenant, "inbox/{$message->id}/archive"))->assertForbidden();
    $this->actingAs($this->owner)->post(tenantUrl($this->tenant, 'inbox/send'), ['mailbox_id' => $this->mailbox->id, 'to' => ['x@fora.test'], 'subject' => 'Olá', 'body' => 'x'])->assertForbidden();
    $this->actingAs($this->owner)->postJson(tenantUrl($this->tenant, 'files'), ['filename' => 'a.pdf', 'size' => 10, 'mailbox_id' => $this->mailbox->id])->assertForbidden();

    Mail::assertNothingSent();
});

it('shows the triage mailbox only to who may read triage, and lets them answer from it', function () {
    $triage = asTenant($this->tenant, fn () => Mailbox::factory()->create(['address' => 'geral@empresa.test']));
    $email = asTenant($this->tenant, fn () => EmailMessage::factory()->create(['mailbox_id' => $triage->id, 'subject' => 'Pedido geral']));

    $this->actingAs($this->ana)->get(tenantUrl($this->tenant, "inbox?mailbox={$triage->id}"))
        ->assertInertia(fn ($page) => $page->where('mailboxes', fn ($boxes) => collect($boxes)->pluck('id')->all() === [$this->mailbox->id]));

    $this->ana->forceFill(['permission_overrides' => [Permission::ReadTriageEmails->value => true]])->save();

    $this->actingAs($this->ana->fresh())->get(tenantUrl($this->tenant, "inbox?mailbox={$triage->id}"))
        ->assertInertia(fn ($page) => $page->where('current.triage', true)->where('messages.data.0.subject', 'Pedido geral'));
    $this->actingAs($this->ana->fresh())->post(tenantUrl($this->tenant, 'inbox/send'), [
        'mailbox_id' => $triage->id, 'reply_to_id' => $email->id, 'to' => ['cliente@fora.test'], 'subject' => 'Re: Pedido geral', 'body' => 'Tratado.',
    ])->assertSessionHas('success');

    Mail::assertSent(AgentMessage::class, fn (AgentMessage $mail) => $mail->fromAddress === 'geral@empresa.test');
});

it('keeps polling every folder from the scheduled job and records a server error', function () {
    $this->imap->deliver('Clientes', rawEmail('x@fora', 'Na pasta Clientes'));
    FetchMailbox::dispatchSync($this->tenant->id, $this->mailbox->id);

    expect(asTenant($this->tenant, fn () => EmailMessage::query()->where('subject', 'Na pasta Clientes')->exists()))->toBeTrue();

    $this->imap->down = true;
    FetchMailbox::dispatchSync($this->tenant->id, $this->mailbox->id);

    expect(asTenant($this->tenant, fn () => $this->mailbox->fresh()->errorMessage()))->toContain('recusou o utilizador');
});

it('opens the mailbox the person chose last time, with each mailbox\'s unread count for the menu', function () {
    $this->imap->deliver('INBOX', rawEmail('a@fora', 'Por ler'));
    syncAna($this);
    $triage = asTenant($this->tenant, fn () => Mailbox::factory()->create(['address' => 'geral@empresa.test']));
    $this->ana->forceFill(['permission_overrides' => [Permission::ReadTriageEmails->value => true]])->save();

    $this->actingAs($this->ana->fresh())->get(tenantUrl($this->tenant, 'inbox'))
        ->assertInertia(fn ($page) => $page->where('current.mailbox', $this->mailbox->id)
            ->where('mailboxes', fn ($boxes) => collect($boxes)->firstWhere('id', $this->mailbox->id)['unread'] === 1));

    $this->actingAs($this->ana->fresh())->get(tenantUrl($this->tenant, "inbox?mailbox={$triage->id}"))->assertOk();
    expect($this->ana->fresh()->last_mailbox_id)->toBe($triage->id);

    $this->actingAs($this->ana->fresh())->get(tenantUrl($this->tenant, 'inbox'))
        ->assertInertia(fn ($page) => $page->where('current.mailbox', $triage->id)->where('current.triage', true));
});

it('counts the person\'s unread email for the sidebar and tells the screens when it changes', function () {
    Event::fake([MailboxUpdated::class]);
    $this->imap->deliver('INBOX', rawEmail('a@fora', 'Por ler'));
    $this->imap->deliver('INBOX', rawEmail('b@fora', 'Também por ler'));
    syncAna($this);

    Event::assertDispatched(MailboxUpdated::class, fn (MailboxUpdated $e) => $e->mailboxId === $this->mailbox->id);

    $this->actingAs($this->ana->fresh())->get(tenantUrl($this->tenant, 'inbox'))
        ->assertInertia(fn ($page) => $page->where('auth.unread_emails', 2));

    // Nobody counts someone else's personal mailbox, not even the owner of the company.
    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, 'tasks'))
        ->assertInertia(fn ($page) => $page->where('auth.unread_emails', 0));

    // Reading one takes it off the count, and says so.
    Event::fake([MailboxUpdated::class]);
    $message = asTenant($this->tenant, fn () => EmailMessage::query()->where('subject', 'Por ler')->sole());
    $this->actingAs($this->ana->fresh())->get(tenantUrl($this->tenant, "inbox/{$message->id}"))
        ->assertInertia(fn ($page) => $page->where('auth.unread_emails', 1));

    Event::assertDispatched(MailboxUpdated::class);
});

it('loads email images on their own, except in Spam or for whoever asked to be asked', function () {
    $this->imap->addFolder('Junk', ['\\Junk']);
    $this->imap->deliver('INBOX', rawEmail('boa@fora', 'Boa'));
    $this->imap->deliver('Junk', rawEmail('spam@fora', 'Spam'));
    syncAna($this);
    [$inbox, $junk] = asTenant($this->tenant, fn () => [
        EmailMessage::query()->where('subject', 'Boa')->sole(),
        EmailMessage::query()->where('subject', 'Spam')->sole(),
    ]);

    $this->actingAs($this->ana->fresh())->get(tenantUrl($this->tenant, "inbox/{$inbox->id}"))
        ->assertInertia(fn ($page) => $page->where('images_ask', false)->where('open.messages.0.junk', false));
    $this->actingAs($this->ana->fresh())->get(tenantUrl($this->tenant, "inbox/{$junk->id}"))
        ->assertInertia(fn ($page) => $page->where('open.messages.0.junk', true));

    $this->ana->forceFill(['email_images_ask' => true])->save();
    $this->actingAs($this->ana->fresh())->get(tenantUrl($this->tenant, "inbox/{$inbox->id}"))
        ->assertInertia(fn ($page) => $page->where('images_ask', true));
});

it('shows the images an email embeds from its own files, not as attachments', function () {
    $png = base64_encode(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
    $raw = "Message-ID: <logo@fora>\r\nFrom: Cliente <cliente@fora.test>\r\nTo: ana@empresa.test\r\nSubject: Com logotipo\r\nDate: Tue, 06 Oct 2026 10:00:00 +0200\r\n"
        ."MIME-Version: 1.0\r\nContent-Type: multipart/related; boundary=\"b1\"\r\n\r\n"
        ."--b1\r\nContent-Type: text/html; charset=utf-8\r\n\r\n<p><img src=\"cid:logo123@fora\"> Olá</p>\r\n"
        ."--b1\r\nContent-Type: image/png; name=\"logo.png\"\r\nContent-Transfer-Encoding: base64\r\nContent-ID: <logo123@fora>\r\nContent-Disposition: inline; filename=\"logo.png\"\r\n\r\n{$png}\r\n--b1--\r\n";
    $this->imap->deliver('INBOX', $raw);
    syncAna($this);
    $message = asTenant($this->tenant, fn () => EmailMessage::query()->where('subject', 'Com logotipo')->sole());
    $file = asTenant($this->tenant, fn () => $message->attachments()->sole());

    expect($file->content_id)->toBe('logo123@fora');
    $this->actingAs($this->ana->fresh())->get(tenantUrl($this->tenant, "inbox/{$message->id}"))
        ->assertInertia(fn ($page) => $page->where('open.messages.0.html', fn ($html) => str_contains($html, "src=\"/attachments/{$file->id}\""))
            ->where('open.messages.0.attachments', fn ($files) => count($files) === 0));
});

it('searches the open folder by subject, sender or text, and every folder when asked', function () {
    $this->imap->deliver('INBOX', rawEmail('a@fora', 'Cotação de cimento', 'joao@fornecedor.test'));
    $this->imap->deliver('INBOX', rawEmail('b@fora', 'Reunião de sexta'));
    $this->imap->deliver('Clientes', rawEmail('c@fora', 'Cotação antiga'));
    syncAna($this);
    asTenant($this->tenant, fn () => app(MailboxSync::class)->run($this->mailbox->fresh(), EmailFolder::query()->where('path', 'Clientes')->first()));

    $subjects = fn (string $query) => collect($this->actingAs($this->ana)->get(tenantUrl($this->tenant, "inbox?mailbox={$this->mailbox->id}&{$query}"))
        ->assertOk()->viewData('page')['props']['messages']['data'])->pluck('subject')->sort()->values()->all();

    expect($subjects('q=cotação'))->toBe(['Cotação de cimento'])
        ->and($subjects('q=fornecedor'))->toBe(['Cotação de cimento'])
        ->and($subjects('q='.urlencode('Texto de Reunião')))->toBe(['Reunião de sexta'])
        ->and($subjects('q=cotação&em=todas'))->toBe(['Cotação antiga', 'Cotação de cimento']);
});

it('filters the list by reading, files, star, dates and triage state, and keeps them in the address', function () {
    syncAna($this);
    [$read, $files, $starred, $old, $failed] = asTenant($this->tenant, function () {
        $inbox = EmailFolder::query()->where('mailbox_id', $this->mailbox->id)->where('role', EmailFolder::INBOX)->firstOrFail();
        $make = fn (string $subject, array $extra = []) => EmailMessage::factory()->create([
            'mailbox_id' => $this->mailbox->id, 'email_folder_id' => $inbox->id, 'subject' => $subject, 'read_at' => null, ...$extra,
        ]);

        $files = $make('Com ficheiro');
        EmailAttachment::factory()->create(['email_message_id' => $files->id, 'filename' => 'fatura.pdf', 'path' => 'x/fatura.pdf']);

        return [
            $make('Já lido', ['read_at' => now()]),
            $files,
            $make('Com estrela', ['is_flagged' => true]),
            $make('Antigo', ['received_at' => now()->subDays(40)]),
            $make('Falhou', ['status' => EmailStatus::Failed]),
        ];
    });

    $list = fn (string $query) => $this->actingAs($this->ana)->get(tenantUrl($this->tenant, "inbox?mailbox={$this->mailbox->id}&{$query}"))->assertOk()->viewData('page')['props'];
    $ids = fn (string $query) => collect($list($query)['messages']['data'])->pluck('id')->sort()->values()->all();

    expect($ids('leitura=lidos'))->toBe([$read->id])
        ->and($ids('leitura=nao_lidos'))->not->toContain($read->id)
        ->and($ids('unread=1'))->not->toContain($read->id)
        ->and($ids('anexos=1'))->toBe([$files->id])
        ->and($ids('estrela=1'))->toBe([$starred->id])
        ->and($ids('periodo=30d'))->not->toContain($old->id)
        ->and($ids('de='.now()->subDays(45)->format('Y-m-d').'&ate='.now()->subDays(35)->format('Y-m-d')))->toBe([$old->id])
        // A personal mailbox that does not send to triage has no triage filter.
        ->and($ids('triagem=falhou'))->toHaveCount(5);

    // One that sends its email to triage has it.
    $this->mailbox->forceFill(['sends_to_triage' => true])->save();
    $props = $list('triagem=falhou&anexos=0');

    expect(collect($props['messages']['data'])->pluck('id')->all())->toBe([$failed->id])
        ->and($props['current']['triagem'])->toBe('falhou')
        ->and($props['triage_states'])->not->toBeEmpty();

    $this->actingAs($this->ana)->get(tenantUrl($this->tenant, "inbox?mailbox={$this->mailbox->id}&periodo=ontem"))->assertSessionHasErrors('periodo');
});

it('names the agent that wrote each draft, with its face, from the run that created it', function () {
    [$email, $agent] = asTenant($this->tenant, function () {
        $agent = Agent::factory()->create(['name' => 'Sofia']);
        $thread = EmailThread::factory()->create();
        $email = EmailMessage::factory()->create(['mailbox_id' => $this->mailbox->id, 'thread_id' => $thread->id, 'subject' => 'Pedido de cotação']);
        $run = AgentRun::factory()->create(['agent_id' => $agent->id]);
        EmailMessage::factory()->create([
            'mailbox_id' => $this->mailbox->id, 'thread_id' => $email->thread_id, 'direction' => 'outbound', 'status' => EmailStatus::Draft,
            'agent_run_id' => $run->id, 'written_by_user_id' => null, 'to' => ['cliente@fora.test'],
        ]);

        return [$email, $agent];
    });

    $this->actingAs($this->ana->fresh())->get(tenantUrl($this->tenant, "inbox/{$email->id}"))
        ->assertInertia(fn ($page) => $page->where('open.messages', fn ($messages) => collect($messages)->contains(
            fn ($m) => $m['status'] === 'draft' && $m['agent'] === ['name' => 'Sofia', 'avatar_url' => $agent->avatarUrl()]
        ))->where('open.messages', fn ($messages) => collect($messages)->where('status', '!=', 'draft')->every(fn ($m) => $m['agent'] === null)));
});

it('takes the open email to a conversation with an agent, which can then read it', function () {
    Queue::fake();
    [$email, $agent, $other] = asTenant($this->tenant, function () {
        $email = EmailMessage::factory()->create(['mailbox_id' => $this->mailbox->id, 'subject' => 'Pedido de cotação', 'text_body' => 'Peço uma cotação de software para loja de calçados.']);

        return [$email, Agent::factory()->create(['name' => 'Gestor de Clientes']), Agent::factory()->create()];
    });
    $this->ana->forceFill(['role' => 'owner'])->save();

    asTenant($this->tenant, fn () => expect(EmailMessage::query()->readableBy($agent)->whereKey($email->id)->exists())->toBeFalse());

    $this->actingAs($this->ana->fresh())->post(tenantUrl($this->tenant, "inbox/{$email->id}/agent"), ['agent_id' => $agent->id, 'message' => 'Prepara uma proposta.'])
        ->assertRedirect();

    asTenant($this->tenant, function () use ($email, $agent, $other) {
        $message = TaskMessage::query()->where('email_message_id', $email->id)->sole();
        expect($message->body)->toBe('Prepara uma proposta.')
            ->and($message->task->assignee_agent_id)->toBe($agent->id)
            ->and(EmailMessage::query()->readableBy($agent)->whereKey($email->id)->exists())->toBeTrue()
            ->and(EmailMessage::query()->readableBy($other)->whereKey($email->id)->exists())->toBeFalse();

        $run = AgentRun::query()->where('agent_id', $agent->id)->latest('id')->first();
        expect($run?->input)->toContain('Prepara uma proposta.')->toContain("#{$email->id}")->toContain('loja de calçados');
    });

    $this->actingAs($this->ana->fresh())->get(tenantUrl($this->tenant, 'tasks/'.asTenant($this->tenant, fn () => TaskMessage::query()->where('email_message_id', $email->id)->value('task_id'))))
        ->assertInertia(fn ($page) => $page->where('messages.0.email.subject', 'Pedido de cotação'));
});
