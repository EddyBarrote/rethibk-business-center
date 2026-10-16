<?php

use App\Ai\Agents\InstructionComposer;
use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityRegistry;
use App\Ai\Runs\AgentRunner;
use App\Enums\RunStatus;
use App\Enums\TaskKind;
use App\Enums\TriggerType;
use App\Models\Agent;
use App\Models\Department;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Tasks\TaskThread;
use Illuminate\Support\Facades\Queue;

// Tasks are known by their title, agents by their name (docs/DECISOES.md, "Tarefas pelo título").

beforeEach(function () {
    Queue::fake();
    $this->tenant = Tenant::factory()->create(['slug' => 'rethink']);

    [$this->boss, $this->clerk, $this->chief, $this->clients] = asTenant($this->tenant, function () {
        $sales = Department::factory()->create(['name' => 'Comercial']);
        $boss = User::factory()->create(['name' => 'Chefe', 'role' => 'manager', 'department_id' => $sales->id]);
        $chief = Agent::factory()->create(['key' => 'chief_of_staff', 'name' => 'Chief of Staff', 'reports_to_user_id' => $boss->id]);

        return [
            $boss,
            User::factory()->create(['name' => 'Natália', 'email' => 'natalia@rethink.test', 'department_id' => $sales->id]),
            $chief,
            Agent::factory()->create(['key' => 'client_manager', 'name' => 'Gestor de Clientes', 'department_id' => $sales->id, 'reports_to_agent_id' => $chief->id]),
        ];
    });
});

it('gives the agent the title to talk about, and the button opens the task by its title', function () {
    asTenant($this->tenant, function () {
        $parent = app(TaskThread::class)->open(['kind' => TaskKind::Task, 'title' => 'Acompanhar propostas', 'assignee_agent_id' => $this->chief->id], $this->chief);
        $run = app(AgentRunner::class)->create($this->chief, 'teste', TriggerType::Manual);
        $run->forceFill(['task_id' => $parent->id])->save();

        $result = app(CapabilityRegistry::class)->find('tasks.create')->execute([
            'agent' => 'client_manager',
            'title' => 'Seguimento da proposta à Fundação AZUL',
            'description' => 'Contactar a Fundação AZUL para saber se aprovou a proposta.',
        ], new CapabilityContext($this->chief, $run->fresh()));

        $task = Task::query()->where('title', 'Seguimento da proposta à Fundação AZUL')->sole();
        $data = json_decode($result->content, true);

        expect($result->ok)->toBeTrue()
            ->and($data['title'])->toBe('Seguimento da proposta à Fundação AZUL')
            ->and($data['ref'])->toBe($task->identifier())
            ->and($data['assigned_to'])->toBe('Gestor de Clientes')
            ->and($data)->not->toHaveKey('task')
            ->and($parent->messages()->latest('id')->value('body'))
            ->toBe('Chief of Staff deu a tarefa «Seguimento da proposta à Fundação AZUL» a Gestor de Clientes.');
    });
});

it('tells a person about a new task by its title', function () {
    asTenant($this->tenant, function () {
        $result = runCapability($this->clients, 'tasks.create', [
            'person' => 'natalia@rethink.test',
            'title' => 'Ligar à Fundação AZUL',
            'description' => 'Confirmar a data de arranque.',
        ]);

        expect($result->ok)->toBeTrue($result->content)
            ->and($this->clerk->notifications()->sole()->data['title'])->toBe('Nova tarefa: Ligar à Fundação AZUL');
    });
});

it('never shows an agent by its internal key in a reply', function () {
    asTenant($this->tenant, function () {
        $chat = app(TaskThread::class)->conversation($this->boss, $this->chief);
        $run = app(AgentRunner::class)->create($this->chief, 'teste', TriggerType::Manual);
        $run->forceFill([
            'task_id' => $chat->id,
            'status' => RunStatus::Completed,
            'output' => ['text' => "Criei a tarefa e atribuí-a ao Gestor de Clientes (client_manager):\n- Atribuída a: Gestor de Clientes (`client_manager`)\n- Quem: `client_manager`"],
        ])->save();

        app(TaskThread::class)->recordReply($run->fresh());

        expect($chat->messages()->latest('id')->value('body'))
            ->toBe("Criei a tarefa e atribuí-a ao Gestor de Clientes:\n- Atribuída a: Gestor de Clientes\n- Quem: Gestor de Clientes");
    });
});

it('asks every agent to name tasks by title and colleagues by name', function () {
    asTenant($this->tenant, fn () => expect(app(InstructionComposer::class)->for($this->clients))
        ->toContain('Fala das tarefas pelo título')
        ->toContain('nunca pela chave interna')
        ->toContain('diz-lhe o título'));
});
