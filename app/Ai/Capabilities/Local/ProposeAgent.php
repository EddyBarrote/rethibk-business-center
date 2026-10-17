<?php

namespace App\Ai\Capabilities\Local;

use App\Ai\Agents\NewColleagues;
use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Capabilities\LocalCapability;
use App\Tasks\TaskThread;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * The Chief of Staff prepares a new agent in the conversation; it is created
 * only when a person who may create agents confirms the card (docs/DECISOES.md,
 * «Chief of Staff cria agentes e skills»).
 */
final class ProposeAgent extends LocalCapability
{
    public function __construct(private readonly NewColleagues $colleagues) {}

    public function key(): string
    {
        return 'agents.propose';
    }

    public function name(): string
    {
        return 'Propor novo agente';
    }

    public function description(): string
    {
        return 'Prepara um agente novo (nome, função, área, a quem responde, instruções, capacidades, skills e nível de confiança inicial) e mostra-o como cartão na conversa. Só é criado quando uma pessoa com permissão confirma. Não inventes nada: o que a pessoa não disse, pergunta antes.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('O nome do agente, como as pessoas o vão tratar.')->required(),
            'title' => $schema->string()->description('A função, ex.: "Gestor de contratos".')->required(),
            'department' => $schema->string()->description('O nome de uma área (departamento) que já existe.')->required(),
            'reports_to' => $schema->string()->description('A quem responde: o email de uma pessoa ou a chave de um agente.')->required(),
            'instructions' => $schema->string()->description('O que faz e como, em markdown, na segunda pessoa ("Tu tratas de...").')->required(),
            'description' => $schema->string()->description('Uma linha sobre o agente, para a lista de agentes.'),
            'personality' => $schema->string()->description('O tom, se a pessoa o disse.'),
            'capabilities' => $schema->array()->items($schema->string())->description('Chaves das capacidades (ferramentas) que precisa.'),
            'skills' => $schema->array()->items($schema->string())->description('Chaves das skills que recebe.'),
            'autonomy_level' => $schema->integer()->min(0)->max(NewColleagues::MAX_START_LEVEL->value)->description('Nível de confiança inicial: 0 ou 1 (por omissão 1).'),
        ];
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function ceilingReason(array $arguments, CapabilityContext $context): string
    {
        return 'um agente novo é confirmado por uma pessoa que pode criar agentes';
    }

    public function precheck(array $arguments, CapabilityContext $context): ?string
    {
        $missing = $this->colleagues->agent($arguments)['missing'];

        return $missing === [] ? null
            : 'Proposta não enviada. Falta: '.implode('; ', $missing).'. Pergunta à pessoa o que falta (questions.ask) e volta a propor; não inventes.';
    }

    public function summarise(array $arguments): string
    {
        return sprintf('Criar o agente %s (%s)', $arguments['name'] ?? '?', $arguments['title'] ?? '?');
    }

    public function execute(array $arguments, CapabilityContext $context): CapabilityResult
    {
        $agent = $this->colleagues->createAgent($arguments, $context->agent, $context->approval);

        if (is_string($agent)) {
            return CapabilityResult::error($agent);
        }

        if ($context->run->task !== null) {
            app(TaskThread::class)->note($context->run->task, "Novo agente: [{$agent->name}](/agents/{$agent->id}), {$agent->title}. Já aparece em Agentes e no Organigrama.", $context->run);
        }

        return CapabilityResult::data(['agent_id' => $agent->id, 'key' => $agent->key, 'link' => "/agents/{$agent->id}", 'status' => "{$agent->name} foi criado, em {$agent->autonomy_level->code()}."]);
    }
}
