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

it('keeps work facts for everyone and personal facts for the person, and publishes the work ones', function () {
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
        expect($personal->about_user_id)->toBe($this->ana->id);

        // Work facts went to the knowledge base, in one article for the agent; personal ones never.
        $article = KnowledgeItem::query()->sole();
        expect($article->title)->toBe('Memória de Finanças')
            ->and($article->content)->toContain('60 dias')
            ->and($article->content)->not->toContain('9h')
            ->and($article->status)->toBe(KnowledgeItem::PUBLISHED);

        // With João the agent remembers the supplier, not Ana's preference.
        $run = AgentRun::factory()->create(['agent_id' => $this->agent->id, 'task_id' => app(TaskThread::class)->conversation($this->joao, $this->agent)->id]);
        $prompt = app(InstructionComposer::class)->for($this->agent, $run->fresh());
        expect($prompt)->toContain('60 dias')->not->toContain('9h');

        $run = AgentRun::factory()->create(['agent_id' => $this->agent->id, 'task_id' => $chat->id]);
        expect(app(InstructionComposer::class)->for($this->agent, $run->fresh()))->toContain('antes das 9h');

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

it('lets the agent\'s chefia and the administrators correct or forget a fact, and nobody else', function () {
    $memory = asTenant($this->tenant, fn () => AgentMemory::factory()->create(['agent_id' => $this->agent->id, 'content' => 'Facto errado.']));

    $this->actingAs($this->ana)->get(tenantUrl($this->tenant, "agents/{$this->agent->id}"))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('memories', null));
    $this->actingAs($this->ana)->delete(tenantUrl($this->tenant, "agents/{$this->agent->id}/memories/{$memory->id}"))->assertForbidden();

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, "agents/{$this->agent->id}"))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('memories.0.content', 'Facto errado.'));

    $this->actingAs($this->owner)->put(tenantUrl($this->tenant, "agents/{$this->agent->id}/memories/{$memory->id}"), ['content' => 'Facto certo.', 'kind' => 'work'])->assertRedirect();
    asTenant($this->tenant, fn () => expect($memory->fresh()->content)->toBe('Facto certo.')
        ->and(KnowledgeItem::query()->sole()->content)->toContain('Facto certo.'));

    $this->actingAs($this->owner)->delete(tenantUrl($this->tenant, "agents/{$this->agent->id}/memories/{$memory->id}"))->assertRedirect();
    asTenant($this->tenant, fn () => expect(AgentMemory::query()->count())->toBe(0));
});
