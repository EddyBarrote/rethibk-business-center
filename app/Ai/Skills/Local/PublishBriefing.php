<?php

namespace App\Ai\Skills\Local;

use App\Ai\Skills\LocalSkill;
use App\Ai\Skills\SkillContext;
use App\Ai\Skills\SkillResult;
use App\Enums\BriefingType;
use App\Mail\BriefingMail;
use App\Models\Briefing;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Notifier;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Saves a briefing and delivers it to its reader, in the console and by
 * email (E04). Internal delivery only, so it needs no approval.
 */
final class PublishBriefing extends LocalSkill
{
    public function __construct(private readonly Notifier $notifier) {}

    public function key(): string
    {
        return 'briefings.publish';
    }

    public function name(): string
    {
        return 'Publicar briefing';
    }

    public function description(): string
    {
        return 'Guarda um briefing e entrega-o ao destinatário na consola e por email. O conteúdo é markdown; as decisões pendentes levam ligação para a consola.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->enum(array_column(BriefingType::cases(), 'value'))->required(),
            'title' => $schema->string()->required(),
            'content' => $schema->string()->description('Markdown: prioridades, o que mudou, riscos, números.')->required(),
            'highlights' => $schema->array()->items($schema->string())->description('Até 5 pontos-chave de uma linha.'),
            'decisions_pending' => $schema->array()->items($schema->object([
                'title' => $schema->string()->required(),
                'link' => $schema->string()->description('Caminho na consola, ex.: /approvals'),
                'owner' => $schema->string(),
            ]))->description('O que precisa de decisão de uma pessoa.'),
            'for' => $schema->string()->description('Email da conta do destinatário. Vazio: a chefia do agente.'),
            'period_start' => $schema->string()->format('date'),
            'period_end' => $schema->string()->format('date'),
        ];
    }

    public function execute(array $arguments, SkillContext $context): SkillResult
    {
        $data = Validator::make($arguments, [
            'type' => ['required', Rule::enum(BriefingType::class)],
            'title' => 'required|string|max:255',
            'content' => 'required|string|max:60000',
            'highlights' => 'nullable|array|max:10',
            'highlights.*' => 'string|max:500',
            'decisions_pending' => 'nullable|array|max:30',
            'decisions_pending.*.title' => 'required|string|max:500',
            'decisions_pending.*.link' => ['nullable', 'string', 'max:500', 'regex:#^/[^/]#'],
            'decisions_pending.*.owner' => 'nullable|string|max:255',
            'for' => 'nullable|string|max:255',
            'period_start' => 'nullable|date',
            'period_end' => 'nullable|date',
        ])->validate();

        $reader = filled($data['for'] ?? null)
            ? User::query()->where('email', mb_strtolower($data['for']))->where('is_active', true)->first()
            : $context->agent->reportsTo;

        if ($reader === null) {
            return SkillResult::error('destinatário não encontrado: indique o email da conta ou defina a chefia do agente.');
        }

        $briefing = Briefing::query()->create([
            'agent_id' => $context->agent->id,
            'agent_run_id' => $context->run->id,
            'type' => $data['type'],
            'for_user_id' => $reader->id,
            'title' => $data['title'],
            'content' => $data['content'],
            'highlights' => $data['highlights'] ?? [],
            'decisions_pending' => $data['decisions_pending'] ?? [],
            'period_start' => $data['period_start'] ?? today(),
            'period_end' => $data['period_end'] ?? today(),
            'delivered_at' => now(),
        ]);

        $path = "/briefings/{$briefing->id}";
        $this->notifier->notify($reader, $briefing->title, $data['highlights'][0] ?? 'O briefing está pronto.', $path, $context->agent->name);

        $emailed = true;

        try {
            Mail::to($reader->email)->send(new BriefingMail($briefing, (string) Tenant::current()?->url()));
        } catch (Throwable $e) {
            report($e);
            $emailed = false;
        }

        return SkillResult::data(['briefing_id' => $briefing->id, 'for' => $reader->name, 'emailed' => $emailed, 'link' => $path]);
    }

    public function summarise(array $arguments): string
    {
        return 'Publicar briefing: '.($arguments['title'] ?? '');
    }
}
