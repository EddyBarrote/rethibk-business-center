<?php

namespace App\Ai\Capabilities\Local;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Capabilities\LocalCapability;
use App\Enums\ActorType;
use App\Enums\Permission;
use App\Models\Agent;
use App\Models\TaskMessage;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * The Chief of Staff searches what people and agents said, to report to the
 * CEO (docs/DECISOES.md, realinhamento L10). Only when the person asking has
 * the "pedir reports sobre conversas" permission; every search is audited.
 */
final class SearchConversations extends LocalCapability
{
    public function key(): string
    {
        return 'conversations.search';
    }

    public function name(): string
    {
        return 'Pesquisar conversas';
    }

    public function description(): string
    {
        return 'Pesquisa as conversas e tarefas de pessoas com agentes (palavras, pessoa, agente, últimos dias) e devolve excertos com ligação. Só funciona a pedido de quem pode pedir reports sobre conversas. Ao reportar: resumo com os excertos citados e a ligação de cada conversa.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Palavras a procurar; vazio para tudo o que a pessoa ou o agente disse.'),
            'person' => $schema->string()->description('Nome ou email da pessoa.'),
            'agent' => $schema->string()->description('Chave ou nome do agente.'),
            'days' => $schema->integer()->min(1)->max(365)->description('Últimos N dias (por omissão 30).'),
        ];
    }

    public function execute(array $arguments, CapabilityContext $context): CapabilityResult
    {
        $data = Validator::make($arguments, [
            'query' => 'nullable|string|max:200',
            'person' => 'nullable|string|max:255',
            'agent' => 'nullable|string|max:120',
            'days' => 'nullable|integer|min:1|max:365',
        ])->validate();

        $asker = $context->run->task->user ?? $context->run->requestedBy;

        if ($asker === null || ! $asker->hasPermission(Permission::RequestConversationReports)) {
            return CapabilityResult::error('só pesquiso conversas a pedido de quem pode pedir reports sobre conversas (por omissão, o CEO).');
        }

        $person = filled($data['person'] ?? null)
            ? User::query()->where('email', mb_strtolower(trim($data['person'])))->orWhere('name', 'like', '%'.trim($data['person']).'%')->first()
            : null;
        $agent = filled($data['agent'] ?? null)
            ? Agent::query()->where('key', trim($data['agent']))->orWhere('name', 'like', '%'.trim($data['agent']).'%')->first()
            : null;

        if (filled($data['person'] ?? null) && $person === null) {
            return CapabilityResult::error("não encontrei a pessoa «{$data['person']}».");
        }

        $messages = TaskMessage::query()
            ->whereIn('author_type', [ActorType::User, ActorType::Agent])
            ->where('created_at', '>=', now()->subDays((int) ($data['days'] ?? 30)))
            ->when(filled($data['query'] ?? null), fn (Builder $q) => $q->where(function (Builder $words) use ($data) {
                foreach (array_slice(preg_split('/\s+/', trim((string) $data['query'])) ?: [], 0, 6) as $word) {
                    $words->where('body', 'like', '%'.$word.'%');
                }
            }))
            ->when($person !== null, fn (Builder $q) => $q->whereHas('task', fn (Builder $t) => $t->where('user_id', $person->id)->orWhere('assignee_user_id', $person->id)->orWhere('created_by_user_id', $person->id)))
            ->when($agent !== null, fn (Builder $q) => $q->whereHas('task', fn (Builder $t) => $t->where('assignee_agent_id', $agent->id)))
            ->with(['task.tenant:id,slug', 'task.user:id,name', 'task.assigneeAgent:id,name', 'authorUser:id,name', 'authorAgent:id,name'])
            ->latest('id')
            ->limit(30)
            ->get();

        return CapabilityResult::data([
            'asked_by' => $asker->name,
            'matches' => $messages->map(fn (TaskMessage $m) => [
                'where' => $m->task->chat_key !== null ? 'conversa' : 'tarefa',
                'ref' => $m->task->identifier(),
                'title' => $m->task->title,
                'person' => $m->task->user?->name,
                'agent' => $m->task->assigneeAgent?->name,
                'author' => $m->authorName(),
                'date' => $m->created_at->toDateTimeString(),
                'excerpt' => Str::limit($m->body, 400),
                'link' => "/tasks/{$m->task_id}",
            ])->all(),
        ]);
    }
}
