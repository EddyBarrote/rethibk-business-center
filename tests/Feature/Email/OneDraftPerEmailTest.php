<?php

use App\Email\MailClient;
use App\Enums\EmailStatus;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\EmailMessage;
use App\Models\Mailbox;
use App\Models\MailboxOwner;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

// One open draft per email (docs/DECISOES.md, "Um só rascunho por email").

beforeEach(function () {
    Queue::fake();
    $this->tenant = Tenant::factory()->create();

    [$this->edy, $this->clients, $this->mailbox, $this->email] = asTenant($this->tenant, function () {
        $edy = User::factory()->create(['name' => 'Edy']);
        $clients = Agent::factory()->create(['key' => 'client_manager', 'name' => 'Gestor de Clientes']);
        $mailbox = Mailbox::factory()->personal()->create(['address' => 'geral@rethink.test', 'imap_host' => null]);
        MailboxOwner::factory()->create(['mailbox_id' => $mailbox->id, 'user_id' => $edy->id]);
        $email = EmailMessage::factory()->create([
            'mailbox_id' => $mailbox->id, 'direction' => 'inbound', 'status' => EmailStatus::Processed,
            'from_address' => 'cliente@fora.test', 'subject' => 'Pedido de cotação',
            'message_id_header' => '<pedido-1@fora.test>', 'sent_to_triage_at' => now(),
        ]);

        return [$edy, $clients, $mailbox, $email];
    });
});

it('rewrites the open draft when an agent drafts the reply again (a retry, a second task, a revision)', function () {
    asTenant($this->tenant, function () {
        runCapability($this->clients, 'email.draft_reply', ['email_id' => $this->email->id, 'body' => 'Primeira versão']);
        $result = runCapability($this->clients, 'email.draft_reply', ['email_id' => $this->email->id, 'body' => 'Segunda versão']);
        $draft = EmailMessage::query()->where('status', EmailStatus::Draft)->sole();

        expect($result->ok)->toBeTrue()
            ->and($result->content)->toContain('actualizei esse')
            ->and($draft->text_body)->toBe('Segunda versão')
            ->and($draft->agent_run_id)->toBe(AgentRun::query()->latest('id')->value('id'));
    });
});

it('never rewrites a reply draft a person changed, and adds no other', function () {
    asTenant($this->tenant, function () {
        runCapability($this->clients, 'email.draft_reply', ['email_id' => $this->email->id, 'body' => 'Versão do agente']);
        $draft = EmailMessage::query()->where('status', EmailStatus::Draft)->sole();

        // Opening and saving without changes is not an edit.
        app(MailClient::class)->saveDraft($this->mailbox, $this->edy, ['to' => $draft->to, 'cc' => [], 'bcc' => [], 'subject' => $draft->subject, 'body' => 'Versão do agente'], draft: $draft);
        expect($draft->fresh()->edited_by_person_at)->toBeNull();

        app(MailClient::class)->saveDraft($this->mailbox, $this->edy, ['to' => $draft->to, 'cc' => [], 'bcc' => [], 'subject' => $draft->subject, 'body' => 'Versão do Edy'], draft: $draft->fresh());
        $result = runCapability($this->clients, 'email.draft_reply', ['email_id' => $this->email->id, 'body' => 'Nova versão do agente']);

        expect($result->content)->toContain('uma pessoa escreveu ou alterou')
            ->and(EmailMessage::query()->where('status', EmailStatus::Draft)->sole()->text_body)->toBe('Versão do Edy');
    });
});

it('archives the older agent drafts of the same email, keeping the newest and what a person wrote', function () {
    [$older, $old, $newest, $other, $persons] = asTenant($this->tenant, function () {
        $draft = fn (string $body, string $inReplyTo = '<pedido-1@fora.test>', array $extra = []) => EmailMessage::factory()->create([
            'mailbox_id' => $this->mailbox->id, 'direction' => 'outbound', 'status' => EmailStatus::Draft,
            'in_reply_to' => $inReplyTo, 'text_body' => $body,
            'agent_run_id' => AgentRun::factory()->create(['agent_id' => $this->clients->id])->id, ...$extra,
        ]);

        return [
            $draft('1'), $draft('2'), $draft('3'),
            $draft('outro email', '<pedido-2@fora.test>'),
            $draft('mudado pela pessoa', extra: ['edited_by_person_at' => now()]),
        ];
    });

    $this->artisan('mail:archive-duplicate-drafts')->assertSuccessful();

    asTenant($this->tenant, function () use ($older, $old, $newest, $other, $persons) {
        // The person's draft is the one that stays; the agent's three go.
        expect($older->fresh()->removed_at)->not->toBeNull()
            ->and($old->fresh()->removed_at)->not->toBeNull()
            ->and($newest->fresh()->removed_at)->not->toBeNull()
            ->and($persons->fresh()->removed_at)->toBeNull()
            ->and($other->fresh()->removed_at)->toBeNull()
            // Archived, not deleted.
            ->and(EmailMessage::query()->count())->toBe(6);
    });
});
