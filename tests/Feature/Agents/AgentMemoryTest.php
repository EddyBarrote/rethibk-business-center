<?php

use App\Ai\Agents\GenericAgent;
use App\Ai\Agents\InstructionComposer;
use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityRegistry;
use App\Ai\Memory\MemoryConsolidator;
use App\Ai\Memory\MemoryExtractor;
use App\Ai\Runs\AgentRunner;
use App\Enums\MemorySection;
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

it('keeps each person\'s memory with the agent as named entries, and shares the work ones through the knowledge base', function () {
    MemoryExtractor::fake([[
        'entries' => [
            ['section' => 'areas', 'title' => 'Segurança Total EPI', 'description' => 'Fornecedor de EPI: condições de pagamento.', 'body' => '- Passou a pagar a 60 dias.', 'kind' => 'work'],
            ['section' => 'preferences', 'title' => 'Preferências', 'description' => 'Horários e forma de contacto.', 'body' => '- Não receber chamadas antes das 9h.', 'kind' => 'work'],
        ],
        'summary' => 'Condições do fornecedor anotadas.',
    ]]);

    asTenant($this->tenant, function () {
        $chat = chatWithAna();

        expect(app(MemoryConsolidator::class)->consolidate($chat))->toBe(2)
            ->and($chat->fresh()->memory_message_id)->toBe($chat->messages()->max('id'));

        // «Sobre si» is always personal, whatever the extractor said.
        $preferences = AgentMemory::query()->where('section', MemorySection::Preferences)->sole();
        expect($preferences->kind)->toBe('personal')
            ->and($preferences->title)->toBe('Preferências')
            ->and(AgentMemory::query()->pluck('user_id')->unique()->all())->toBe([$this->ana->id]);

        // Work entries went to the knowledge base, in one article for the agent; personal ones never.
        $article = KnowledgeItem::query()->sole();
        expect($article->title)->toBe('Memória de Finanças')
            ->and($article->content)->toContain('## Segurança Total EPI')->toContain('60 dias')
            ->and($article->content)->not->toContain('9h')
            ->and($article->status)->toBe(KnowledgeItem::PUBLISHED);

        // João's memory with the agent is his own: what Ana taught it reaches him only through the knowledge base.
        expect(app(MemoryConsolidator::class)->forPrompt($this->agent, $this->joao))->not->toContain('60 dias')->not->toContain('9h');

        // «Sobre si» goes in full; the other entries only as an index the agent opens with memory.read.
        $run = AgentRun::factory()->create(['agent_id' => $this->agent->id, 'task_id' => $chat->id]);
        $area = AgentMemory::query()->where('section', MemorySection::Areas)->sole();
        expect(app(InstructionComposer::class)->for($this->agent, $run->fresh()))
            ->toContain('antes das 9h')
            ->toContain("#{$area->id} Áreas · Segurança Total EPI — Fornecedor de EPI: condições de pagamento.")
            ->not->toContain('60 dias');

        // Nothing new, nothing read twice.
        expect(app(MemoryConsolidator::class)->consolidate($chat->fresh()))->toBe(0);
    });
});

it('updates an entry by its title instead of adding another, and forgets what stopped being true', function () {
    MemoryExtractor::fake([
        ['entries' => [['section' => 'areas', 'title' => 'Segurança Total EPI', 'description' => 'Fornecedor de EPI.', 'body' => '- Paga a 30 dias.', 'kind' => 'work']]],
        ['entries' => [['section' => 'areas', 'title' => 'segurança total epi', 'description' => 'Fornecedor de EPI: pagamento.', 'body' => '- Passou a pagar a 60 dias (12.10).', 'kind' => 'work']]],
        ['entries' => [], 'forget' => ['Segurança Total EPI']],
    ]);

    asTenant($this->tenant, function () {
        $threads = app(TaskThread::class);
        $chat = chatWithAna();
        app(MemoryConsolidator::class)->consolidate($chat);

        $threads->post($chat->refresh(), $this->ana, 'Agora pagam a 60 dias.', wake: false);
        expect(app(MemoryConsolidator::class)->consolidate($chat->fresh()))->toBe(1);
        expect(AgentMemory::query()->sole()->content)->toBe('- Passou a pagar a 60 dias (12.10).');

        $threads->post($chat->refresh(), $this->ana, 'Deixámos de trabalhar com eles.', wake: false);
        app(MemoryConsolidator::class)->consolidate($chat->fresh());
        expect(AgentMemory::query()->count())->toBe(0);
    });
});

it('lets the agent read a whole entry, only of the person it is working with', function () {
    asTenant($this->tenant, function () {
        $ana = AgentMemory::factory()->create(['agent_id' => $this->agent->id, 'user_id' => $this->ana->id, 'section' => MemorySection::Projects, 'title' => 'Obra da Matola', 'content' => 'Entrega a 30 de Novembro.']);
        $joao = AgentMemory::factory()->create(['agent_id' => $this->agent->id, 'user_id' => $this->joao->id, 'title' => 'Do João', 'content' => 'Segredo do João.']);
        $run = AgentRun::factory()->create(['agent_id' => $this->agent->id, 'task_id' => app(TaskThread::class)->conversation($this->ana, $this->agent)->id]);
        $read = fn (string $entry) => app(CapabilityRegistry::class)->find('memory.read')->execute(['entry' => $entry], new CapabilityContext($this->agent, $run->fresh()));

        expect($read("#{$ana->id}")->content)->toContain('Obra da Matola')->toContain('30 de Novembro')
            ->and($read('obra da matola')->ok)->toBeTrue()
            ->and($read((string) $joao->id)->ok)->toBeFalse();

        // With memory off, the agent neither reads it nor sees it in its prompt.
        $this->ana->forceFill(['memory_preferences' => ['generate' => false]])->save();
        expect($read((string) $ana->id)->ok)->toBeFalse()
            ->and(app(MemoryConsolidator::class)->forPrompt($this->agent, $this->ana->fresh()))->not->toContain('Obra da Matola');
    });
});

it('follows the person\'s switches: no new memory, no sensitive subjects, no search in past chats', function () {
    MemoryExtractor::fake([['entries' => [['section' => 'topics', 'title' => 'Cobranças', 'description' => 'x', 'body' => 'y', 'kind' => 'work']], 'summary' => 'Tratou-se de cobranças.']]);

    asTenant($this->tenant, function () {
        $this->ana->forceFill(['memory_preferences' => ['generate' => false, 'sensitive' => false, 'search_chats' => false]])->save();
        $chat = chatWithAna();

        // The thread still gets its summary; the person's memory stays as it was.
        expect(app(MemoryConsolidator::class)->consolidate($chat))->toBe(0)
            ->and(AgentMemory::query()->count())->toBe(0)
            ->and($chat->fresh()->summary)->toBe('Tratou-se de cobranças.');

        $instructions = (new MemoryExtractor('Finanças', '(nada ainda)', person: 'Ana', sensitive: false))->instructions();
        expect($instructions)->toContain('não guardar temas sensíveis');

        $run = AgentRun::factory()->create(['agent_id' => $this->agent->id, 'task_id' => $chat->id]);
        $result = app(CapabilityRegistry::class)->find('memory.search_chats')->execute(['query' => 'fornecedor'], new CapabilityContext($this->agent, $run->fresh()));
        expect($result->ok)->toBeFalse()->and($result->content)->toContain('desligou');
    });
});

it('searches the person\'s earlier conversations with the agent, never someone else\'s', function () {
    asTenant($this->tenant, function () {
        $threads = app(TaskThread::class);
        $old = Task::factory()->create(['assignee_agent_id' => $this->agent->id, 'user_id' => $this->ana->id, 'title' => 'Cotação de botas']);
        $threads->post($old, $this->ana, 'A cotação das botas da Segurança Total ficou em 1200 MT.', wake: false);
        $other = Task::factory()->create(['assignee_agent_id' => $this->agent->id, 'user_id' => $this->joao->id]);
        $threads->post($other, $this->joao, 'As botas do João.', wake: false);

        $run = AgentRun::factory()->create(['agent_id' => $this->agent->id, 'task_id' => $threads->conversation($this->ana, $this->agent)->id]);
        $result = app(CapabilityRegistry::class)->find('memory.search_chats')->execute(['query' => 'botas'], new CapabilityContext($this->agent, $run->fresh()));

        expect($result->ok)->toBeTrue()
            ->and($result->data['matches'])->toHaveCount(1)
            ->and($result->data['matches'][0]['excerpt'])->toContain('1200 MT');
    });
});

it('keeps what the agent learned from work with no person for every conversation', function () {
    asTenant($this->tenant, function () {
        AgentMemory::factory()->create(['agent_id' => $this->agent->id, 'title' => 'Rotina das 8h', 'description' => 'Corre ao sábado.']);
        AgentMemory::factory()->create(['agent_id' => $this->agent->id, 'user_id' => $this->ana->id, 'title' => 'Fornecedor X', 'description' => 'A Ana trata dele.']);

        expect(app(MemoryConsolidator::class)->forPrompt($this->agent, $this->joao))->toContain('Rotina das 8h')->not->toContain('Fornecedor X')
            ->and(app(MemoryConsolidator::class)->forPrompt($this->agent, $this->ana))->toContain('Rotina das 8h')->toContain('Fornecedor X');
    });
});

it('folds the old loose facts into two entries, then sorts them into entries by subject', function () {
    MemoryExtractor::fake([[
        'entries' => [
            ['section' => 'preferences', 'title' => 'Preferências', 'description' => 'Horários.', 'body' => '- Nada antes das 9h.', 'kind' => 'personal'],
            ['section' => 'areas', 'title' => 'Segurança Total EPI', 'description' => 'Pagamentos.', 'body' => '- 60 dias.', 'kind' => 'work'],
        ],
    ]]);

    asTenant($this->tenant, function () {
        // What the 12.10.2026 migration leaves of the old facts.
        $loose = AgentMemory::factory()->create(['agent_id' => $this->agent->id, 'user_id' => $this->ana->id, 'section' => MemorySection::Topics, 'title' => 'Notas de trabalho', 'description' => 'O que o agente aprendeu no trabalho, por organizar.', 'content' => "- A Segurança Total paga a 60 dias.\n- A Ana não quer chamadas antes das 9h."]);

        expect(app(MemoryConsolidator::class)->organise($this->agent, $this->ana))->toBe(2)
            ->and(AgentMemory::query()->find($loose->id))->toBeNull()
            ->and(AgentMemory::query()->orderBy('title')->pluck('title')->all())->toBe(['Preferências', 'Segurança Total EPI']);
    });

    $this->artisan('agents:organise-memory')->assertSuccessful();
});

it('lets only the administrators see, correct or forget an entry on the agent\'s page, not the agent\'s chefia', function () {
    [$chefia, $agent] = asTenant($this->tenant, function () {
        $chefia = User::factory()->create(['name' => 'Chefia']);

        return [$chefia, Agent::factory()->create(['reports_to_user_id' => $chefia->id])];
    });
    $memory = asTenant($this->tenant, fn () => AgentMemory::factory()->create(['agent_id' => $this->agent->id, 'user_id' => $this->ana->id, 'title' => 'Errado', 'content' => 'Facto errado.']));
    $entry = ['section' => 'topics', 'title' => 'Certo', 'description' => 'Corrigido.', 'content' => 'Facto certo.', 'kind' => 'work'];

    $this->actingAs($this->ana)->get(tenantUrl($this->tenant, "agents/{$this->agent->id}"))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('memories', null));
    $this->actingAs($this->ana)->delete(tenantUrl($this->tenant, "agents/{$this->agent->id}/memories/{$memory->id}"))->assertForbidden();
    $this->actingAs($chefia)->get(tenantUrl($this->tenant, "agents/{$agent->id}"))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('memories', null));

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, "agents/{$this->agent->id}"))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('memories.0.title', 'Errado')->where('memories.0.person.name', 'Ana Mondlane')->has('memory_sections', 6));

    $this->actingAs($this->owner)->put(tenantUrl($this->tenant, "agents/{$this->agent->id}/memories/{$memory->id}"), $entry)->assertRedirect();
    asTenant($this->tenant, fn () => expect($memory->fresh()->content)->toBe('Facto certo.')
        ->and($memory->fresh()->title)->toBe('Certo')
        ->and(KnowledgeItem::query()->sole()->content)->toContain('Facto certo.'));

    $this->actingAs($this->owner)->delete(tenantUrl($this->tenant, "agents/{$this->agent->id}/memories/{$memory->id}"))->assertRedirect();
    asTenant($this->tenant, fn () => expect(AgentMemory::query()->count())->toBe(0));
});

it('shows each person their own memory, with the switches, to correct and delete', function () {
    [$mine, $theirs] = asTenant($this->tenant, fn () => [
        AgentMemory::factory()->create(['agent_id' => $this->agent->id, 'user_id' => $this->ana->id, 'section' => MemorySection::Profile, 'title' => 'Perfil', 'kind' => 'personal', 'content' => 'Contabilista.']),
        AgentMemory::factory()->create(['agent_id' => $this->agent->id, 'user_id' => $this->joao->id, 'title' => 'Do João']),
    ]);

    $this->actingAs($this->ana)->get(tenantUrl($this->tenant, 'settings/memory'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Settings/Memory')
            ->where('prefs', ['search_chats' => true, 'generate' => true, 'sensitive' => true])
            ->has('entries', 1)
            ->where('entries.0.title', 'Perfil')
            ->where('agents.0.name', 'Finanças'));

    $this->actingAs($this->ana)->put(tenantUrl($this->tenant, 'settings/memory/preferences'), ['key' => 'sensitive', 'enabled' => false])->assertRedirect();
    expect($this->ana->fresh()->memory_preferences)->toBe(['sensitive' => false]);

    // Another person's entry is not there to change.
    $this->actingAs($this->ana)->delete(tenantUrl($this->tenant, "settings/memory/{$theirs->id}"))->assertNotFound();

    // «Sobre si» stays personal even if asked to share it.
    $this->actingAs($this->ana)->put(tenantUrl($this->tenant, "settings/memory/{$mine->id}"), ['section' => 'profile', 'title' => 'Perfil', 'description' => 'Cargo.', 'content' => 'Contabilista sénior.', 'kind' => 'work'])->assertRedirect();
    asTenant($this->tenant, fn () => expect($mine->fresh()->content)->toBe('Contabilista sénior.')->and($mine->fresh()->kind)->toBe('personal')->and($mine->fresh()->edited_by_user_id)->toBe($this->ana->id));

    $this->actingAs($this->ana)->delete(tenantUrl($this->tenant, "settings/memory/agents/{$this->agent->id}"))->assertRedirect();
    asTenant($this->tenant, fn () => expect(AgentMemory::query()->pluck('id')->all())->toBe([$theirs->id]));
});

// Context over days (docs/DECISOES.md, "Contexto dos agentes", 12.10.2026).

it('consolidates a busy thread before its messages leave the history, not only at 21:00', function () {
    config(['business.memory_every_messages' => 4]);
    GenericAgent::fake(['Primeira.', 'Segunda.']);

    asTenant($this->tenant, function () {
        $threads = app(TaskThread::class);
        $chat = $threads->conversation($this->ana, $this->agent);
        $threads->post($chat, $this->ana, 'Olá.');
        app(AgentRunner::class)->run(AgentRun::query()->sole());
        Queue::assertNotPushed(ConsolidateAgentMemory::class);

        $threads->post($chat->refresh(), $this->ana, 'E a cotação?');
        app(AgentRunner::class)->run(AgentRun::query()->latest('id')->first());
        Queue::assertPushed(ConsolidateAgentMemory::class, fn (ConsolidateAgentMemory $job) => $job->taskId === $chat->id);
    });
});

it('dates each message in the history, so the agent sees the days that passed', function () {
    asTenant($this->tenant, function () {
        $this->travelTo(now()->setDate(2026, 10, 9)->setTime(10, 15));
        $chat = chatWithAna();
        $this->travelBack();

        $run = AgentRun::factory()->create(['agent_id' => $this->agent->id, 'task_id' => $chat->id]);
        $history = app(TaskThread::class)->history($run);

        expect($history[0]->content)->toStartWith('[Ana Mondlane · 2026-10-09 ')
            ->and($history[1]->content)->toBe('Anotado.')
            ->and(app(InstructionComposer::class)->for($this->agent, $run))->toContain('a data e hora');
    });
});

it('keeps a rolling summary of the thread and shows it once the start leaves the history', function () {
    MemoryExtractor::fake([
        ['entries' => [], 'summary' => 'A Ana pediu a cotação da Segurança Total; falta o preço das botas.'],
        ['entries' => []],
    ]);

    asTenant($this->tenant, function () {
        $chat = chatWithAna();
        app(MemoryConsolidator::class)->consolidate($chat);
        $run = AgentRun::factory()->create(['agent_id' => $this->agent->id, 'task_id' => $chat->id]);

        expect($chat->fresh()->summary)->toContain('falta o preço das botas')
            // Short thread: the history has it all, the summary stays out.
            ->and(app(InstructionComposer::class)->for($this->agent, $run->fresh()))->not->toContain('O que veio antes');

        foreach (range(1, TaskThread::HISTORY_MESSAGES) as $i) {
            app(TaskThread::class)->post($chat, $this->ana, "Mensagem {$i}.", wake: false);
        }

        expect(app(InstructionComposer::class)->for($this->agent, $run->fresh()))->toContain('O que veio antes')->toContain('falta o preço das botas');

        // A pass with no new summary keeps the one there was.
        app(MemoryConsolidator::class)->consolidate($chat->fresh());
        expect($chat->fresh()->summary)->toContain('falta o preço das botas');
    });
});
