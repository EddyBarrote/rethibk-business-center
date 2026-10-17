<?php

use App\Email\Imap\ImapGateway;
use App\Email\MailboxSync;
use App\Enums\EmailStatus;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\EmailFolder;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Models\Mailbox;
use App\Models\MailboxOwner;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeImap;

// An agent's draft is a proposal until the person keeps it (docs/DECISOES.md, "Rascunho só quando a pessoa escolhe").

beforeEach(function () {
    Queue::fake();
    $this->imap = new FakeImap;
    app()->instance(ImapGateway::class, $this->imap);
    $this->tenant = Tenant::factory()->create();

    [$this->edy, $this->clients, $this->mailbox, $this->email] = asTenant($this->tenant, function () {
        $edy = User::factory()->create(['name' => 'Edy']);
        $clients = Agent::factory()->create(['key' => 'client_manager', 'name' => 'Gestor de Clientes']);
        $mailbox = Mailbox::factory()->personal()->create(['address' => 'geral@rethink.test', 'imap_host' => 'imap.rethink.test', 'imap_encryption' => 'ssl']);
        MailboxOwner::factory()->create(['mailbox_id' => $mailbox->id, 'user_id' => $edy->id]);
        $email = EmailMessage::factory()->create([
            'mailbox_id' => $mailbox->id, 'direction' => 'inbound', 'status' => EmailStatus::Processed,
            'from_address' => 'cliente@fora.test', 'subject' => 'Pedido de cotação',
            'message_id_header' => '<pedido-1@fora.test>', 'sent_to_triage_at' => now(),
            'thread_id' => EmailThread::factory()->create()->id,
        ]);
        app(MailboxSync::class)->folders($mailbox);

        return [$edy, $clients, $mailbox, $email];
    });
});

it('shows the proposal under the email, marked as not yet in the Rascunhos', function () {
    asTenant($this->tenant, fn () => runCapability($this->clients, 'email.draft_reply', ['email_id' => $this->email->id, 'body' => 'Proposta']));

    $this->actingAs($this->edy)->get(tenantUrl($this->tenant, "inbox/{$this->email->id}"))->assertOk()
        ->assertInertia(fn ($page) => $page->where('open.messages', fn ($messages) => collect($messages)->firstWhere('status', 'draft')['proposal'] === true));
});

it('never rewrites a draft the person kept as it was', function () {
    asTenant($this->tenant, function () {
        runCapability($this->clients, 'email.draft_reply', ['email_id' => $this->email->id, 'body' => 'Versão do agente']);
    });
    $draft = asTenant($this->tenant, fn () => EmailMessage::query()->where('status', EmailStatus::Draft)->sole());

    $this->actingAs($this->edy)->post(tenantUrl($this->tenant, "inbox/drafts/{$draft->id}/keep"))->assertSessionHas('success');

    asTenant($this->tenant, function () {
        $result = runCapability($this->clients, 'email.draft_reply', ['email_id' => $this->email->id, 'body' => 'Outra versão']);

        expect($result->content)->toContain('guardou')
            ->and(EmailMessage::query()->where('status', EmailStatus::Draft)->sole()->text_body)->toBe('Versão do agente');
    });
});

it('lets only who writes from the mailbox keep a proposal', function () {
    asTenant($this->tenant, fn () => runCapability($this->clients, 'email.draft_reply', ['email_id' => $this->email->id, 'body' => 'Proposta']));
    [$draft, $other] = asTenant($this->tenant, fn () => [EmailMessage::query()->where('status', EmailStatus::Draft)->sole(), User::factory()->owner()->create()]);

    $this->actingAs($other)->post(tenantUrl($this->tenant, "inbox/drafts/{$draft->id}/keep"))->assertForbidden();
    expect(asTenant($this->tenant, fn () => $draft->fresh()->isProposal()))->toBeTrue();
});

it('takes the agent drafts that went to the Rascunhos by themselves back to proposals, and leaves a person\'s', function () {
    [$filed, $kept, $persons, $folder] = asTenant($this->tenant, function () {
        $folder = EmailFolder::query()->where('mailbox_id', $this->mailbox->id)->where('role', EmailFolder::DRAFTS)->sole();
        $draft = fn (string $body, array $extra = []) => EmailMessage::factory()->create([
            'mailbox_id' => $this->mailbox->id, 'direction' => 'outbound', 'status' => EmailStatus::Draft, 'text_body' => $body,
            'email_folder_id' => $folder->id, 'imap_uid' => null,
            'agent_run_id' => AgentRun::factory()->create(['agent_id' => $this->clients->id])->id, ...$extra,
        ]);

        return [
            $draft('do agente'),
            $draft('guardado pela pessoa', ['kept_at' => now()]),
            $draft('escrito pela pessoa', ['agent_run_id' => null, 'written_by_user_id' => $this->edy->id]),
            $folder,
        ];
    });

    $this->artisan('mail:return-agent-drafts', ['--dry-run' => true])->assertSuccessful();
    expect(asTenant($this->tenant, fn () => $filed->fresh()->email_folder_id))->toBe($folder->id);

    $this->artisan('mail:return-agent-drafts')->assertSuccessful();

    asTenant($this->tenant, function () use ($filed, $kept, $persons, $folder) {
        expect($filed->fresh()->email_folder_id)->toBeNull()
            ->and($filed->fresh()->removed_at)->toBeNull()
            ->and($kept->fresh()->email_folder_id)->toBe($folder->id)
            ->and($persons->fresh()->email_folder_id)->toBe($folder->id)
            ->and($folder->messages()->pluck('id')->sort()->values()->all())->toBe([$kept->id, $persons->id]);
    });
});
