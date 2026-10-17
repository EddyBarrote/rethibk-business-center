<?php

namespace App\Ai\Capabilities\Local;

use App\Ai\Agents\NewColleagues;
use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Capabilities\LocalCapability;
use App\Tasks\TaskThread;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * The Chief of Staff prepares a new skill (an instruction package,
 * docs/CAPACIDADES.md) in the conversation; it is created only when a person
 * who manages the catalogue confirms the card (docs/DECISOES.md, «Chief of
 * Staff cria agentes e skills»).
 */
final class ProposeSkill extends LocalCapability
{
    public function __construct(private readonly NewColleagues $colleagues) {}

    public function key(): string
    {
        return 'skills.propose';
    }

    public function name(): string
    {
        return 'Propor nova skill';
    }

    public function description(): string
    {
        return 'Prepara uma skill nova (o que faz e quando se usa, as instruções de como se faz, e que agentes a recebem) e mostra-a como cartão na conversa. Só é criada quando uma pessoa com permissão confirma. Não inventes regras, valores ou passos: o que a pessoa não disse, pergunta antes.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('O nome, ex.: "Proposta comercial".')->required(),
            'description' => $schema->string()->description('Quando se usa: o agente lê isto para decidir se a carrega.')->required(),
            'instructions' => $schema->string()->description('Como se faz, em markdown, com o que a pessoa disse.')->required(),
            'agents' => $schema->array()->items($schema->string())->description('Chaves dos agentes que a recebem.'),
            'all_agents' => $schema->boolean()->description('Verdadeiro para chegar a todos os agentes.'),
        ];
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function ceilingReason(array $arguments, CapabilityContext $context): string
    {
        return 'uma skill nova é confirmada por uma pessoa que gere as skills';
    }

    public function precheck(array $arguments, CapabilityContext $context): ?string
    {
        $missing = $this->colleagues->skill($arguments)['missing'];

        return $missing === [] ? null
            : 'Proposta não enviada. Falta: '.implode('; ', $missing).'. Pergunta à pessoa o que falta (questions.ask) e volta a propor; não inventes.';
    }

    public function summarise(array $arguments): string
    {
        return sprintf('Criar a skill «%s»', $arguments['name'] ?? '?');
    }

    public function execute(array $arguments, CapabilityContext $context): CapabilityResult
    {
        $skill = $this->colleagues->createSkill($arguments, $context->agent, $context->approval);

        if (is_string($skill)) {
            return CapabilityResult::error($skill);
        }

        if ($context->run->task !== null) {
            app(TaskThread::class)->note($context->run->task, "Nova skill: [{$skill->name}](/settings/skills/{$skill->id}/edit).", $context->run);
        }

        return CapabilityResult::data(['skill_id' => $skill->id, 'key' => $skill->key, 'status' => "A skill «{$skill->name}» foi criada."]);
    }
}
