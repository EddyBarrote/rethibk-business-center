<?php

use App\Ai\Agents\InstructionComposer;
use App\Ai\Memory\MemoryConsolidator;
use App\Ai\Memory\MemoryExtractor;
use App\Enums\TaskStatus;
use App\Jobs\ConsolidateAgentMemory;
use App\Models\Agent;
use App\Models\AgentMemory;
use App\Models\AgentRun;
use App\Models\KnowledgeItem;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Tasks\TaskThread;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

// Each agent's consolidated memory (docs/DECISOES.md, realinhamento L7).

beforeEach(function () {
    Queue::fake();
    $this->tenant = Tenant::factory()->create();

    [$this->owner, $this->ana, $this->joao, $this->agent] = asTenant($this->tenant, function () {
        $owner = User::factory()->owner()->create();

        return [
            $owner,
            User::factory()->create(['name' => 'Ana Mondlane']),
            User::factory()->create(['name' => 'João Sitoe']),
            Agent::factory()->create(['key' => 'finance', 'name' => 'Finanças', 'reports_to_user_id' => $owner->id]),
        ];
    });
});

function chatWithAna(): Task
{
    $thread = app(TaskThread::class);
    $chat = $thread->conversation(test()->ana, test()->agent);
    $thread->post($chat, test()->ana, 'O fornecedor Segurança Total passou a pagar a 60 dias. E não me ligues antes das 9h.', wake: false);
    $thread->post($chat, test()->agent, 'Anotado.', wake: false);

    return $chat;
}

it('keeps each person\'s memory with the agent, and shares the work facts through the knowledge base', function () {
    MemoryExtractor::fake([[
        'facts' => [
            ['content' => 'A Segurança Total EPI passou a pagar a 60 dias.', 'kind' => 'work'],
            ['content' => 'A Ana prefere não receber chamadas antes das 9h.', 'kind' => 'personal', 'about' => 'Ana'],
        ],
    ]]);

    asTenant($this->tenant, function () {
        $chat = chatWithAna();

        expect(app(MemoryConsolidator::class)->consolidate($chat))->toBe(2)
            ->and($chat->fresh()->memory_message_id)->toBe($chat->messages()->max('id'));

        $personal = AgentMemory::query()->where('kind', 'personal')->sole();
        expect($personal->about_user_id)->toBe($this->ana->id)
            ->and(AgentMemory::query()->pluck('user_id')->unique()->all())->toBe([$this->ana->id]);

        // Work facts went to the knowledge base, in one article for the agent; personal ones never.
        $article = KnowledgeItem::query()->sole();
        expect($article->title)->toBe('Memória de Finanças')
            ->and($article->content)->toContain('60 dias')
            ->and($article->content)->not->toContain('9h')
            ->and($article->status)->toBe(KnowledgeItem::PUBLISHED);

        // João's memory with the agent is his own: what Ana taught it reaches him only through the knowledge base.
        $run = AgentRun::factory()->create(['agent_id' => $this->agent->id, 'task_id' => app(TaskThread::class)->conversation($this->joao, $this->agent)->id]);
        expect(app(MemoryConsolidator::class)->forPrompt($this->agent, $this->joao))->toBe('');

        $run = AgentRun::factory()->create(['agent_id' => $this->agent->id, 'task_id' => $chat->id]);
        expect(app(InstructionComposer::class)->for($this->agent, $run->fresh()))->toContain('antes das 9h')->toContain('60 dias');

        // Nothing new, nothing read twice.
        expect(app(MemoryConsolidator::class)->consolidate($chat->fresh()))->toBe(0);
    });
});

it('consolidates when a task is delivered, and daily for conversations', function () {
    asTenant($this->tenant, function () {
        $task = Task::factory()->create(['assignee_agent_id' => $this->agent->id, 'user_id' => $this->ana->id, 'status' => TaskStatus::InProgress]);
        app(TaskThread::class)->setStatus($task, TaskStatus::InReview, $this->agent);
        chatWithAna();
    });

    Queue::assertPushed(ConsolidateAgentMemory::class, 1);

    $this->artisan('agents:consolidate-memory')->assertSuccessful();
    Queue::assertPushed(ConsolidateAgentMemory::class, 2);
});

it('keeps what the agent learned from work with no person for every conversation', function () {
    asTenant($this->tenant, function () {
        AgentMemory::factory()->create(['agent_id' => $this->agent->id, 'content' => 'A rotina das 8h corre ao sábado.']);
        AgentMemory::factory()->create(['agent_id' => $this->agent->id, 'user_id' => $this->ana->id, 'content' => 'A Ana trata do fornecedor X.']);

        expect(app(MemoryConsolidator::class)->forPrompt($this->agent, $this->joao))->toContain('rotina das 8h')->not->toContain('fornecedor X')
            ->and(app(MemoryConsolidator::class)->forPrompt($this->agent, $this->ana))->toContain('rotina das 8h')->toContain('fornecedor X');
    });
});

it('lets only the administrators see, correct or forget a fact, not the agent\'s chefia nor the person', function () {
    [$chefia, $agent] = asTenant($this->tenant, function () {
        $chefia = User::factory()->create(['name' => 'Chefia']);

        return [$chefia, Agent::factory()->create(['reports_to_user_id' => $chefia->id])];
    });
    $memory = asTenant($this->tenant, fn () => AgentMemory::factory()->create(['agent_id' => $this->agent->id, 'user_id' => $this->ana->id, 'content' => 'Facto errado.']));

    $this->actingAs($this->ana)->get(tenantUrl($this->tenant, "agents/{$this->agent->id}"))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('memories', null));
    $this->actingAs($this->ana)->delete(tenantUrl($this->tenant, "agents/{$this->agent->id}/memories/{$memory->id}"))->assertForbidden();
    $this->actingAs($chefia)->get(tenantUrl($this->tenant, "agents/{$agent->id}"))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('memories', null));

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, "agents/{$this->agent->id}"))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('memories.0.content', 'Facto errado.')->where('memories.0.person.name', 'Ana Mondlane'));

    $this->actingAs($this->owner)->put(tenantUrl($this->tenant, "agents/{$this->agent->id}/memories/{$memory->id}"), ['content' => 'Facto certo.', 'kind' => 'work'])->assertRedirect();
    asTenant($this->tenant, fn () => expect($memory->fresh()->content)->toBe('Facto certo.')
        ->and(KnowledgeItem::query()->sole()->content)->toContain('Facto certo.'));

    $this->actingAs($this->owner)->delete(tenantUrl($this->tenant, "agents/{$this->agent->id}/memories/{$memory->id}"))->assertRedirect();
    asTenant($this->tenant, fn () => expect(AgentMemory::query()->count())->toBe(0));
});
