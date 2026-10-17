<?php

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityRegistry;
use App\Email\MailClient;
use App\Enums\EmailStatus;
use App\Enums\RunStatus;
use App\Enums\TriggerType;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\EmailMessage;
use App\Models\GeneratedDocument;
use App\Models\Mailbox;
use App\Models\MailboxOwner;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

// A client's request that came to a person's second mailbox, answered by the
// agent given the task: it reads the email, answers from that mailbox in the
// same conversation, and the proposal it generated goes with the email.

beforeEach(function () {
    Queue::fake();
    Storage::fake('local');
    $this->tenant = Tenant::factory()->create();

    asTenant($this->tenant, function () {
        $this->edy = User::factory()->create(['name' => 'Edy']);
        $this->clients = Agent::factory()->create(['key' => 'client_manager', 'name' => 'Gestor de Clientes', 'reports_to_user_id' => $this->edy->id]);
        // geral@ first, as in the company: the agent reads neither.
        $this->geral = Mailbox::factory()->personal()->create(['address' => 'geral@rethink.test', 'smtp_host' => 'smtp.rethink.test', 'smtp_port' => 465]);
        $this->own = Mailbox::factory()->personal()->create(['address' => 'edy@rethink.test', 'smtp_host' => 'smtp.rethink.test', 'smtp_port' => 465]);
        MailboxOwner::factory()->create(['mailbox_id' => $this->geral->id, 'user_id' => $this->edy->id]);
        MailboxOwner::factory()->create(['mailbox_id' => $this->own->id, 'user_id' => $this->edy->id]);

        $this->request = EmailMessage::factory()->create([
            'mailbox_id' => $this->own->id,
            'from_address' => 'carmelia@olankha.test',
            'from_name' => 'Carmélia Buque',
            'to' => ['edy@rethink.test'],
            'subject' => 'Solicitação de Cotação - Credenciação',
            'text_body' => 'Solicito cotação para gestão de credenciação, 150 pessoas, 20 de Novembro de 2026.',
            'thread_id' => null,
        ]);

        $task = Task::factory()->create([
            'title' => 'Cotação de credenciação para a Olankha',
            'description' => 'Preparar a cotação para Carmélia Buque (carmelia@olankha.test).',
            'assignee_agent_id' => $this->clients->id,
            'user_id' => $this->edy->id,
            'source_type' => (new EmailMessage)->getMorphClass(),
            'source_id' => $this->request->id,
        ]);
        $this->run = AgentRun::query()->create([
            'agent_id' => $this->clients->id,
            'task_id' => $task->id,
            'trigger_type' => TriggerType::Agent,
            'status' => RunStatus::Running,
            'input' => $task->description,
            'started_at' => now(),
        ]);

        Storage::disk('local')->put('documents/proposta.pdf', '%PDF-1.4 proposta');
        $this->proposal = GeneratedDocument::factory()->create([
            'agent_id' => $this->clients->id,
            'agent_run_id' => $this->run->id,
            'title' => 'Proposta Comercial - Olankha',
            'path' => 'documents/proposta.pdf',
            'filename' => 'proposta-comercial-olankha.pdf',
            'size_bytes' => 17,
        ]);
    });
});

function proposalTool(AgentRun $run, string $key, array $arguments): mixed
{
    return app(CapabilityRegistry::class)->find($key)->execute($arguments, new CapabilityContext($run->agent, $run));
}

it('lets the agent read the email its task came from, in a mailbox it does not read', function () {
    $result = asTenant($this->tenant, fn () => proposalTool($this->run, 'email.read', ['email_id' => $this->request->id, 'whole_thread' => true]));

    expect($result->ok)->toBeTrue()
        ->and($result->content)->toContain('carmelia@olankha.test');
});

it('answers the client from the mailbox the request came to, in its conversation, with the proposal attached', function () {
    [$result, $draft] = asTenant($this->tenant, function () {
        $result = proposalTool($this->run, 'email.draft', [
            'recipient' => 'Carmélia Buque, Olankha',
            'to' => ['carmelia@olankha.test'],
            'subject' => 'Proposta Comercial - Credenciação',
            'body' => "Estimada Carmélia,\n\nSegue em anexo a nossa proposta.\n\nCumprimentos,",
            'document_ids' => [$this->proposal->id],
        ]);

        return [$result, EmailMessage::query()->where('direction', 'outbound')->sole()];
    });

    expect($result->ok)->toBeTrue()
        ->and($result->content)->toContain('proposta-comercial-olankha.pdf')
        ->and($draft->mailbox_id)->toBe($this->own->id)
        ->and($draft->in_reply_to)->toBe($this->request->message_id_header)
        ->and($draft->thread_id)->toBe($this->request->thread_id)
        ->and($draft->status)->toBe(EmailStatus::Draft);

    $file = asTenant($this->tenant, fn () => $draft->attachments()->sole());

    expect($file->filename)->toBe('proposta-comercial-olankha.pdf')
        ->and(Storage::disk($file->diskName())->get($file->path))->toBe('%PDF-1.4 proposta')
        // The email's copy is its own: discarding the draft leaves the document.
        ->and($file->path)->toContain('/mail/out/');

    $raw = asTenant($this->tenant, fn () => app(MailClient::class)->raw($draft->fresh()));

    expect($raw)->toContain('proposta-comercial-olankha.pdf')
        ->and($raw)->toContain('In-Reply-To: '.$this->request->message_id_header);
});

it('attaches the document the run generated when the reply says it goes attached', function () {
    $draft = asTenant($this->tenant, function () {
        proposalTool($this->run, 'email.draft_reply', [
            'email_id' => $this->request->id,
            'body' => "Estimada Carmélia,\n\nApresentamos em anexo a nossa proposta.\n\nCumprimentos,",
        ]);

        return EmailMessage::query()->where('direction', 'outbound')->with('attachments')->sole();
    });

    expect($draft->mailbox_id)->toBe($this->own->id)
        ->and($draft->attachments->pluck('filename')->all())->toBe(['proposta-comercial-olankha.pdf']);
});

it('attaches only documents generated in the task', function () {
    [$result, $draft] = asTenant($this->tenant, function () {
        $other = GeneratedDocument::factory()->create(['path' => 'documents/outro.pdf', 'filename' => 'outro.pdf']);
        $result = proposalTool($this->run, 'email.draft_reply', [
            'email_id' => $this->request->id,
            'body' => 'Obrigado pelo contacto.',
            'document_ids' => [$other->id],
        ]);

        return [$result, EmailMessage::query()->where('direction', 'outbound')->with('attachments')->sole()];
    });

    expect($result->content)->toContain('não os anexei')
        ->and($draft->attachments)->toHaveCount(0);
});
