<?php

namespace App\Ai\Capabilities\Local;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Capabilities\LocalCapability;
use App\Enums\ActorType;
use App\Models\TaskMessage;
use App\Support\MemoryPreferences;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Searches the earlier conversations and tasks of the person of the run with
 * this agent (docs/DECISOES.md, "Memória em entradas", 12.10.2026), like
 * claude.ai's «Search and reference chats». Only the person's own threads with
 * this agent, and only while the person keeps «Procurar nas conversas» on.
 * Given to every agent.
 */
final class SearchPastChats extends LocalCapability
{
    public function key(): string
    {
        return 'memory.search_chats';
    }

    public function name(): string
    {
        return 'Procurar nas conversas anteriores';
    }

    public function description(): string
    {
        return 'Procura nas conversas e tarefas anteriores da pessoa com quem estás a falar, contigo, e devolve excertos com data e ligação. Usa quando a pessoa falar de algo que já trataram ("como combinámos", "aquele fornecedor da semana passada").';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->required()->description('Palavras a procurar.'),
            'days' => $schema->integer()->min(1)->max(730)->description('Últimos N dias (por omissão 180).'),
        ];
    }

    public function execute(array $arguments, CapabilityContext $context): CapabilityResult
    {
        $data = Validator::make($arguments, ['query' => 'required|string|max:200', 'days' => 'nullable|integer|min:1|max:730'])->validate();
        $person = $context->run->task?->user;

        if ($person === null) {
            return CapabilityResult::error('este trabalho não é com uma pessoa, por isso não há conversas dela a procurar.');
        }

        if (! MemoryPreferences::allows($person, 'search_chats')) {
            return CapabilityResult::error("{$person->name} desligou a pesquisa nas conversas anteriores (A minha conta › Memória).");
        }

        $messages = TaskMessage::query()
            ->whereIn('author_type', [ActorType::User, ActorType::Agent])
            ->where('created_at', '>=', now()->subDays((int) ($data['days'] ?? 180)))
            ->where('task_id', '!=', $context->run->task_id)
            ->whereHas('task', fn (Builder $t) => $t->where('assignee_agent_id', $context->agent->id)->where('user_id', $person->id))
            ->where(function (Builder $words) use ($data) {
                foreach (array_slice(preg_split('/\s+/', trim((string) $data['query'])) ?: [], 0, 6) as $word) {
                    $words->where('body', 'like', '%'.$word.'%');
                }
            })
            ->with(['task:id,tenant_id,number,title,chat_key', 'authorUser:id,name', 'authorAgent:id,name'])
            ->latest('id')
            ->limit(20)
            ->get();

        return CapabilityResult::data([
            'person' => $person->name,
            'matches' => $messages->map(fn (TaskMessage $m) => [
                'where' => $m->task->chat_key !== null ? 'conversa' : 'tarefa',
                'ref' => $m->task->identifier(),
                'title' => $m->task->title,
                'author' => $m->authorName(),
                'date' => $m->created_at->toDateTimeString(),
                'excerpt' => Str::limit($m->body, 500),
                'link' => "/tasks/{$m->task_id}",
            ])->all(),
        ]);
    }
}
