<?php

use App\Ai\Agents\InstructionComposer;
use App\Ai\Runs\AgentRunner;
use App\Enums\RunStatus;
use App\Enums\TriggerType;
use App\Models\Agent;
use App\Models\EmailMessage;
use App\Models\Mailbox;
use App\Models\MailboxReader;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Tasks\TaskThread;
use Illuminate\Support\Facades\Queue;

// Emails are named by who wrote them and what about (docs/DECISOES.md, "Emails por quem escreveu").

beforeEach(function () {
    Queue::fake();
    $this->tenant = Tenant::factory()->create(['slug' => 'rethink']);

    [$this->boss, $this->chief, $this->email, $this->hidden] = asTenant($this->tenant, function () {
        $boss = User::factory()->create(['name' => 'Chefe', 'role' => 'manager']);
        $chief = Agent::factory()->create(['key' => 'chief_of_staff', 'name' => 'Chief of Staff', 'reports_to_user_id' => $boss->id]);
        $mine = Mailbox::factory()->create(['kind' => Mailbox::PERSON, 'agent_id' => null, 'address' => 'ebarrote@rethink.test']);
        $other = Mailbox::factory()->create(['kind' => Mailbox::PERSON, 'agent_id' => null, 'address' => 'outra@rethink.test']);
        MailboxReader::query()->create(['mailbox_id' => $mine->id, 'agent_id' => $chief->id, 'processes_new' => true]);

        return [
            $boss,
            $chief,
            EmailMessage::factory()->create(['mailbox_id' => $mine->id, 'direction' => 'inbound', 'from_name' => 'carmelia buque', 'from_address' => 'carmeliabu@gmail.com', 'subject' => 'Solicitação de Cotação - Credenciação']),
            EmailMessage::factory()->create(['mailbox_id' => $other->id, 'direction' => 'inbound', 'from_name' => 'Alguém', 'subject' => 'Privado']),
        ];
    });
});

it('names an email in a reply by who wrote it and its subject, not by its number', function () {
    asTenant($this->tenant, function () {
        $chat = app(TaskThread::class)->conversation($this->boss, $this->chief);
        $run = app(AgentRunner::class)->create($this->chief, 'teste', TriggerType::Manual);
        $run->forceFill([
            'task_id' => $chat->id,
            'status' => RunStatus::Completed,
            'output' => ['text' => "Análise do **Email #{$this->email->id}** e acções tomadas.\nO email #{$this->hidden->id} fica como está."],
        ])->save();

        app(TaskThread::class)->recordReply($run->fresh());

        // One it cannot read keeps its number: its sender is not the agent's to tell.
        expect($chat->messages()->latest('id')->value('body'))
            ->toBe("Análise do **Email de Carmelia Buque** («Solicitação de Cotação - Credenciação») e acções tomadas.\nO email #{$this->hidden->id} fica como está.");
    });
});

it('takes the email number out of a task title', function () {
    asTenant($this->tenant, function () {
        $result = runCapability($this->chief, 'tasks.create', [
            'agent' => 'chief_of_staff',
            'title' => "Elaborar cotação de credenciação para evento Olankha (Email #{$this->email->id})",
            'description' => 'Preparar a cotação.',
        ]);

        expect($result->ok)->toBeTrue($result->content)
            ->and(Task::query()->latest('id')->value('title'))->toBe('Elaborar cotação de credenciação para evento Olankha');
    });
});

it('asks every agent to name emails by sender, company and subject', function () {
    asTenant($this->tenant, fn () => expect(app(InstructionComposer::class)->for($this->chief))
        ->toContain('dos emails por quem os escreveu, a empresa e o assunto')
        ->toContain('nunca pelo número (email #143)'));
});
