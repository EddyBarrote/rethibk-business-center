<?php

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityRegistry;
use App\Email\MailClient;
use App\Enums\EmailStatus;
use App\Enums\RunStatus;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\EmailMessage;
use App\Models\Mailbox;
use App\Models\MailboxOwner;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Tasks\TaskThread;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

// An email an agent proposes shows in the conversation as an email card (docs/DECISOES.md, "Email proposto na conversa").

beforeEach(function () {
    Queue::fake();
    $this->tenant = Tenant::factory()->create();

    [$this->edy, $this->chief, $this->clients, $this->mailbox] = asTenant($this->tenant, function () {
        $edy = User::factory()->create(['name' => 'Edy']);
        $chief = Agent::factory()->create(['key' => 'chief_of_staff', 'name' => 'Chief of Staff', 'reports_to_user_id' => $edy->id]);
        $clients = Agent::factory()->create(['key' => 'client_manager', 'name' => 'Gestor de Clientes', 'reports_to_user_id' => $edy->id, 'reports_to_agent_id' => $chief->id]);
        $mailbox = Mailbox::factory()->personal()->create(['address' => 'edy@rethink.test']);
        MailboxOwner::factory()->create(['mailbox_id' => $mailbox->id, 'user_id' => $edy->id]);

        return [$edy, $chief, $clients, $mailbox];
    });
});

/** The run answering the latest message of a conversation, taken as running. */
function workingRun(Task $task): AgentRun
{
    $run = AgentRun::query()->where('task_id', $task->id)->latest('id')->firstOrFail();
    $run->forceFill(['status' => RunStatus::Running, 'started_at' => now()->subSecond()])->save();

    return $run->fresh();
}

function draftEmail(AgentRun $run, array $arguments): mixed
{
    return app(CapabilityRegistry::class)->find('email.draft')->execute($arguments, new CapabilityContext($run->agent, $run));
}

function endTurn(AgentRun $run, string $text): void
{
    $run->forceFill(['status' => RunStatus::Completed, 'output' => ['text' => $text], 'finished_at' => now()])->save();
    app(TaskThread::class)->recordReply($run->fresh());
}

it('leaves the proposed email as a draft in the person\'s mailbox and shows it in the conversation as an email card', function () {
    [$chat, $run, $draft] = asTenant($this->tenant, function () {
        $threads = app(TaskThread::class);
        $chat = $threads->conversation($this->edy, $this->chief);
        $threads->post($chat, $this->edy, 'Prepara o seguimento da proposta à Fundação AZUL. O email da Dra. Natália Saide é natalia.saide@fundacaoazul.org');
        $run = workingRun($chat);

        $result = draftEmail($run, [
            'recipient' => 'Dra. Natália Saide (Fundação AZUL)',
            'to' => ['natalia.saide@fundacaoazul.org'],
            'cc' => ['director@fundacaoazul.org'],
            'subject' => 'Seguimento da Proposta RT-PRO-2026-AZUL-01',
            'body' => "Cara Dra. Natália Saide,\n\nVenho dar seguimento à nossa proposta.\n\nCumprimentos,",
        ]);
        $draft = EmailMessage::query()->sole();

        expect($result->ok)->toBeTrue()
            ->and($result->content)->toContain('cartão de email')
            // The invented address is left out, never guessed.
            ->and($result->content)->toContain('director@fundacaoazul.org')
            ->and($draft->mailbox_id)->toBe($this->mailbox->id)
            ->and($draft->status)->toBe(EmailStatus::Draft)
            ->and($draft->to)->toBe(['natalia.saide@fundacaoazul.org'])
            ->and($draft->cc)->toBe([])
            ->and($draft->agent_run_id)->toBe($run->id);

        endTurn($run, 'Preparei o seguimento para a Dra. Natália.');

        return [$chat, $run, $draft];
    });

    $this->actingAs($this->edy)->get(tenantUrl($this->tenant, "tasks/{$chat->id}"))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where("emails.{$run->id}.0.id", $draft->id)
            ->where("emails.{$run->id}.0.status", 'draft')
            ->where("emails.{$run->id}.0.agent.name", 'Chief of Staff')
            ->where("emails.{$run->id}.0.to", ['natalia.saide@fundacaoazul.org'])
            ->where("emails.{$run->id}.0.subject", 'Seguimento da Proposta RT-PRO-2026-AZUL-01')
            ->where("emails.{$run->id}.0.body", "Cara Dra. Natália Saide,\n\nVenho dar seguimento à nossa proposta.\n\nCumprimentos,")
            ->where("emails.{$run->id}.0.from_colleague", false)
            ->where("emails.{$run->id}.0.can_edit", true));

    // Edited from the card, it is the same draft as in Email.
    $this->actingAs($this->edy)
        ->put(tenantUrl($this->tenant, "inbox/drafts/{$draft->id}"), ['mailbox_id' => $this->mailbox->id, 'to' => ['natalia.saide@fundacaoazul.org'], 'subject' => 'Seguimento da proposta', 'body' => 'Cara Dra. Natália,'])
        ->assertRedirect();

    $this->actingAs($this->edy)->get(tenantUrl($this->tenant, "tasks/{$chat->id}"))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where("emails.{$run->id}.0.id", $draft->id)
            ->where("emails.{$run->id}.0.subject", 'Seguimento da proposta')
            ->where("emails.{$run->id}.0.body", 'Cara Dra. Natália,'));
});

it('shows the address as missing when no source has it', function () {
    [$chat, $run] = asTenant($this->tenant, function () {
        $threads = app(TaskThread::class);
        $chat = $threads->conversation($this->edy, $this->chief);
        $threads->post($chat, $this->edy, 'Prepara o seguimento da proposta à Dra. Natália Saide.');
        $run = workingRun($chat);

        $result = draftEmail($run, ['recipient' => 'Dra. Natália Saide', 'to' => ['natalia@azul.org'], 'subject' => 'Seguimento', 'body' => 'Cara Dra. Natália,']);

        expect($result->ok)->toBeTrue()
            ->and($result->content)->toContain('falta o endereço')
            ->and(EmailMessage::query()->sole()->to)->toBe([]);

        return [$chat, $run];
    });

    $this->actingAs($this->edy)->get(tenantUrl($this->tenant, "tasks/{$chat->id}"))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where("emails.{$run->id}.0.to", [])
            ->where("emails.{$run->id}.0.pending_recipient", 'Dra. Natália Saide'));
});

it('refuses when there is no mailbox to leave the draft in', function () {
    asTenant($this->tenant, function () {
        $this->mailbox->delete();
        $threads = app(TaskThread::class);
        $chat = $threads->conversation($this->edy, $this->chief);
        $threads->post($chat, $this->edy, 'Prepara um email.');

        $result = draftEmail(workingRun($chat), ['subject' => 'Olá', 'body' => 'Olá']);

        expect($result->ok)->toBeFalse()
            ->and($result->content)->toContain('ligue a caixa')
            ->and(EmailMessage::query()->count())->toBe(0);
    });
});

it('shows the email a colleague drafted under the reply of the agent that passed it the request', function () {
    [$cosChat, $relay, $draft] = asTenant($this->tenant, function () {
        $threads = app(TaskThread::class);
        $cosChat = $threads->conversation($this->edy, $this->chief);
        $threads->post($cosChat, $this->edy, 'Mostra aqui a proposta do email a enviar à Fundação AZUL.');
        $cosRun = workingRun($cosChat);

        app(CapabilityRegistry::class)->find('agents.message')->execute(
            ['agent' => 'client_manager', 'message' => 'Prepara o email de seguimento da proposta RT-PRO-2026-AZUL-01 à Dra. Natália Saide. Não envies.'],
            new CapabilityContext($this->chief, $cosRun),
        );
        $clientsChat = Task::query()->where('chat_key', TaskThread::chatKey($this->edy, $this->clients))->sole();
        $clientsRun = workingRun($clientsChat);

        draftEmail($clientsRun, ['recipient' => 'Dra. Natália Saide (Fundação AZUL)', 'subject' => 'Seguimento da Proposta RT-PRO-2026-AZUL-01', 'body' => 'Cara Dra. Natália Saide,']);
        endTurn($clientsRun, 'Preparei o rascunho do seguimento; falta o endereço da Dra. Natália.');
        endTurn($cosRun, 'Pedi ao Gestor de Clientes.');

        $relay = AgentRun::query()->where('task_id', $cosChat->id)->latest('id')->first();

        // The agent that passed the request knows there is a card and does not paste the email.
        expect($relay->id)->not->toBe($cosRun->id)
            ->and($relay->input)->toContain('deixou um rascunho de email')
            ->and($relay->input)->toContain('Seguimento da Proposta RT-PRO-2026-AZUL-01')
            ->and($relay->input)->toContain('sem endereço do destinatário')
            ->and($relay->input)->toContain('não copies o email');

        return [$cosChat, $relay, EmailMessage::query()->sole()];
    });

    $this->actingAs($this->edy)->get(tenantUrl($this->tenant, "tasks/{$cosChat->id}"))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where("emails.{$relay->id}.0.id", $draft->id)
            ->where("emails.{$relay->id}.0.agent.name", 'Gestor de Clientes')
            ->where("emails.{$relay->id}.0.from_colleague", true)
            ->where("emails.{$relay->id}.0.mailbox_id", $this->mailbox->id));
});

it('rewrites its open draft when it prepares the email again for the same people, and leaves alone one the person changed', function () {
    asTenant($this->tenant, function () {
        $threads = app(TaskThread::class);
        $chat = $threads->conversation($this->edy, $this->chief);
        $threads->post($chat, $this->edy, 'Prepara o seguimento à Dra. Natália, natalia.saide@fundacaoazul.org');
        $first = workingRun($chat);
        draftEmail($first, ['to' => ['natalia.saide@fundacaoazul.org'], 'subject' => 'Seguimento', 'body' => 'Primeira versão']);
        endTurn($first, 'Preparei o seguimento.');

        $threads->post($chat, $this->edy, 'Muda o assunto para «Seguimento da proposta».');
        $second = workingRun($chat);
        $result = draftEmail($second, ['to' => ['natalia.saide@fundacaoazul.org'], 'subject' => 'Seguimento da proposta', 'body' => 'Segunda versão']);
        $draft = EmailMessage::query()->sole();

        expect($result->content)->toContain('actualizei esse')
            ->and($draft->subject)->toBe('Seguimento da proposta')
            ->and($draft->text_body)->toBe('Segunda versão')
            ->and($draft->agent_run_id)->toBe($second->id);

        // Another person in the same conversation is another email.
        draftEmail($second, ['recipient' => 'Director da Fundação', 'subject' => 'Apresentação', 'body' => 'Caro Director,']);
        expect(EmailMessage::query()->count())->toBe(2);
        endTurn($second, 'Mudei o assunto.');

        // Once the person changed it, the agent no longer rewrites it.
        app(MailClient::class)->saveDraft($this->mailbox, $this->edy, ['to' => ['natalia.saide@fundacaoazul.org'], 'cc' => [], 'bcc' => [], 'subject' => 'Seguimento da proposta', 'body' => 'O meu texto'], draft: $draft->fresh());
        $threads->post($chat, $this->edy, 'Revê o email.');
        $result = draftEmail(workingRun($chat), ['to' => ['natalia.saide@fundacaoazul.org'], 'subject' => 'Seguimento', 'body' => 'Terceira versão']);

        expect($result->content)->toContain('uma pessoa alterou')
            ->and($draft->fresh()->text_body)->toBe('O meu texto')
            ->and($draft->fresh()->edited_by_person_at)->not->toBeNull()
            ->and(EmailMessage::query()->count())->toBe(2);
    });
});
