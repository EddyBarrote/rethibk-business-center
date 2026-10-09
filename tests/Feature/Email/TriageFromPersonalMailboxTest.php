<?php

use App\Ai\Agents\GenericAgent;
use App\Email\EmailPrompt;
use App\Email\InboundEmailIngestor;
use App\Enums\EmailStatus;
use App\Enums\Permission;
use App\Models\AgentRun;
use App\Models\EmailMessage;
use App\Models\Mailbox;
use App\Models\MailboxOwner;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

// A person's mailbox that sends its new email to the company's triage
// (docs/DECISOES.md, "Caixa de pessoa que envia para a triagem"), like geral@.

beforeEach(function () {
    Storage::fake((string) config('mail_ingest.disk'));
    Mail::fake();
    $this->store = freshFakeErp();
    $this->tenant = Tenant::factory()->create(['settings' => ['mail_domain' => 'agentes.micomoc.test']]);

    asTenant($this->tenant, function () {
        $this->owner = User::factory()->owner()->create();
        $this->rui = User::factory()->create(['role' => 'member']);
        $this->triage = templateAgent('triage');
        $this->clients = templateAgent('client_manager', ['reports_to_user_id' => $this->owner->id]);
        $this->geral = MailboxOwner::factory()->create(['user_id' => $this->owner->id])->mailbox;
        $this->geral->update(['address' => 'geral@empresa.test', 'status' => 'active']);
    });
});

afterEach(fn () => @unlink($this->store));

function geralInboundId(): int
{
    return (int) EmailMessage::query()->where('direction', 'inbound')->max('id');
}

it('lets the owner turn on sending new email to triage', function () {
    $this->actingAs($this->owner)->put(tenantUrl($this->tenant, "settings/mailboxes/{$this->geral->id}"), [
        'address' => 'geral@empresa.test',
        'display_name' => 'Geral',
        'sends_to_triage' => true,
    ])->assertSessionHasNoErrors()->assertRedirect();

    asTenant($this->tenant, fn () => expect($this->geral->fresh()->sendsToTriage())->toBeTrue()
        ->and($this->geral->fresh()->processor()?->id)->toBe($this->triage->id));

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, 'settings/mailboxes'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('mailboxes.0.sends_to_triage', true));
});

it('hands a new client request in the mailbox to the area agent as a task, without answering it', function () {
    asTenant($this->tenant, fn () => $this->geral->update(['sends_to_triage' => true]));

    // The hand-off runs the area agent at once (sync queue), between triage's steps.
    GenericAgent::fake([
        toolCall('t1', 'email_classify', fn () => ['email_id' => geralInboundId(), 'category' => 'client_rfq', 'confidence' => 0.95, 'priority' => 'normal', 'summary' => 'Pedido de cotação de um sistema de bilhética.']),
        'Vou preparar a proposta.',
        'Pedido de cotação: passado ao Gestor de Clientes.',
    ]);

    asTenant($this->tenant, function () {
        $email = app(InboundEmailIngestor::class)->ingest($this->geral, mailFixture('client-request'));
        $runs = AgentRun::query()->with('agent')->orderBy('id')->get();
        $task = Task::query()->sole();

        expect($email->fresh()->sent_to_triage_at)->not->toBeNull()
            ->and($email->fresh()->isTriage())->toBeTrue()
            ->and($runs[0]->agent->key)->toBe('triage')
            ->and($runs[0]->input)->toContain('envia o email novo para a triagem')->toContain('não respondas')
            ->and($task->assigneeAgent->key)->toBe('client_manager')
            ->and($task->createdByAgent->key)->toBe('triage')
            ->and($task->source->is($email))->toBeTrue()
            ->and($task->user_id)->toBe($this->owner->id);

        // Triage never answers from a person's mailbox; the area agent reads the
        // email it was handed and drafts the answer there, for an owner to send.
        expect(runCapability($this->triage, 'email.draft_reply', ['email_id' => $email->id, 'body' => 'Obrigado.'])->ok)->toBeFalse()
            ->and(runCapability($this->triage, 'comms.send_email', ['to' => ['cliente@fora.test'], 'subject' => 'Re', 'body' => 'Olá', 'reply_to_email_id' => $email->id])->ok)->toBeFalse()
            ->and(runCapability($this->clients, 'email.read', ['email_id' => $email->id])->ok)->toBeTrue()
            ->and(runCapability($this->clients, 'email.draft_reply', ['email_id' => $email->id, 'body' => 'Segue a proposta.'])->ok)->toBeTrue()
            ->and(EmailMessage::query()->where('status', EmailStatus::Draft)->sole()->mailbox_id)->toBe($this->geral->id);
    });
});

it('leaves the email that arrived before alone, and shows triaged email to who reads triage', function () {
    $before = asTenant($this->tenant, fn () => EmailMessage::factory()->create(['mailbox_id' => $this->geral->id, 'subject' => 'Antes']));
    asTenant($this->tenant, fn () => $this->geral->update(['sends_to_triage' => true]));

    GenericAgent::fake(['Classificado.', 'Ok.']);
    $after = asTenant($this->tenant, fn () => app(InboundEmailIngestor::class)->ingest($this->geral, mailFixture('client-request')));

    asTenant($this->tenant, function () use ($before, $after) {
        expect($before->fresh()->sent_to_triage_at)->toBeNull()
            ->and(EmailMessage::query()->triage()->pluck('id')->all())->toContain($after->id)->not->toContain($before->id)
            ->and(runCapability($this->clients, 'email.read', ['email_id' => $before->id])->ok)->toBeFalse();
    });

    $this->actingAs($this->rui)->get(tenantUrl($this->tenant, "inbox/{$after->id}"))->assertForbidden();
    $this->rui->forceFill(['permission_overrides' => [Permission::ReadTriageEmails->value => true]])->save();
    $this->actingAs($this->rui)->get(tenantUrl($this->tenant, "inbox/{$after->id}"))->assertOk();
    $this->actingAs($this->rui)->get(tenantUrl($this->tenant, "inbox/{$before->id}"))->assertForbidden();
});

it('tells triage, in its prompt, that the mailbox belongs to people', function () {
    asTenant($this->tenant, function () {
        $email = EmailMessage::factory()->create(['mailbox_id' => $this->geral->id, 'sent_to_triage_at' => now()]);

        expect(EmailPrompt::forTriage($email))->toContain($this->owner->email)->toContain('nunca envies');
    });
});
