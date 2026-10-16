<?php

namespace App\Ai\Capabilities\Local;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Capabilities\LocalCapability;
use App\Enums\AutonomyLevel;
use App\Tasks\Handoffs;
use App\Tasks\OrgChart;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;

/**
 * Pass the person's request to a colleague agent, like Grok's "Messaged …"
 * (docs/DECISOES.md, "Mensagens entre agentes"). The colleague works on it
 * now, in the person's own conversation with it, and its answer comes back
 * here on its own.
 */
final class MessageAgent extends LocalCapability
{
    public function __construct(private readonly Handoffs $handoffs, private readonly OrgChart $chart) {}

    public function key(): string
    {
        return 'agents.message';
    }

    public function name(): string
    {
        return 'Mandar mensagem a um colega agente';
    }

    public function description(): string
    {
        return 'Passa um pedido da pessoa com quem falas a um colega agente, para ele tratar já: o colega trabalha na conversa dessa pessoa com ele, fala-lhe lá, e a resposta volta aqui sozinha quando terminar. O colega só lê esta mensagem, não a tua conversa: escreve o pedido com o contexto todo (o que procurar, o que fica de fora, prazos, nomes). Para trabalho longo, com prazo e acompanhamento, usa tasks.create.';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function defaultRisk(): AutonomyLevel
    {
        return AutonomyLevel::Suggest;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent' => $schema->string()->description('Chave (ou nome) do colega agente.')->required(),
            'message' => $schema->string()->description('O pedido, com o contexto todo: quem recebe não vê a tua conversa.')->required(),
        ];
    }

    public function execute(array $arguments, CapabilityContext $context): CapabilityResult
    {
        $data = Validator::make($arguments, [
            'agent' => 'required|string|max:120',
            'message' => 'required|string|max:8000',
        ])->validate();

        $target = $this->chart->find($data['agent']);

        if ($target === null) {
            return CapabilityResult::error("não há nenhum agente «{$data['agent']}».");
        }

        $handoff = $this->handoffs->send($context->run, $context->agent, $target, $data['message']);

        if (is_string($handoff)) {
            return CapabilityResult::error($handoff);
        }

        $person = $handoff->user->name ?? 'a pessoa';

        return CapabilityResult::data([
            'sent_to' => $target->name,
            'conversation' => "/tasks/{$handoff->to_task_id}",
            'next' => "{$target->name} está a trabalhar nisto na conversa de {$person} com ele e fala-lhe lá. A resposta volta a esta conversa quando terminar; não esperes por ela agora."
                ." Diz a {$person} o que {$target->name} vai fazer e o que fica de fora, que ainda não há resultado, e que ninguém é contactado nem nada é enviado sem ordem dele.",
        ]);
    }

    public function summarise(array $arguments): string
    {
        return 'Mensagem a '.($arguments['agent'] ?? '?');
    }
}
