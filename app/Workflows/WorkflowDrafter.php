<?php

namespace App\Workflows;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * "Propor passos" in the flow editor: turns a description into blocks, using
 * only the capabilities the flow's agent (and the other agents) have. The
 * person reviews the proposal on the canvas before anything is saved.
 */
final class WorkflowDrafter implements Agent, HasStructuredOutput
{
    use Promptable;

    public function __construct(private readonly string $context) {}

    public function instructions(): string
    {
        return <<<TXT
        Desenhas fluxos de trabalho numa plataforma chamada MICOMOC, em que agentes de IA e pessoas trabalham juntos numa empresa em Moçambique.
        Um fluxo diz o que acontece a um tipo de email depois da triagem. A plataforma segue-o bloco a bloco.
        Descrevem-te o fluxo; propões os blocos, em português (pt-MZ/pt-PT).

        Tipos de bloco:
        - agent: um passo do agente do fluxo. instruction diz o que fazer; capability é a chave de uma capacidade do agente, se houver uma que sirva.
        - handoff: um passo que passa a outro agente (agent = a chave desse agente) numa sub-tarefa.
        - condition: uma pergunta de sim ou não (label é a pergunta). Saídas: yes e no.
        - loop: "para cada" item (items diz que itens; max no máximo 10). Os blocos de dentro têm inside = ref do ciclo.
        - repeat: "repetir até" (until é a pergunta que, com sim, pára; max). Os blocos de dentro têm inside = ref do ciclo.
        - wait: esperar um número de horas (hours).
        - approval: uma pessoa aprova ou rejeita. Saídas: approved e rejected.
        - person: uma tarefa para uma pessoa fazer.
        - end: fim.

        Regras:
        - ref: identificador curto, só letras minúsculas, números e "_", único.
        - Liga os blocos com next (ou yes/no, approved/rejected). Usa "end" para acabar. Sem ligações para trás: para repetir usa loop ou repeat.
        - Os blocos de dentro de um ciclo só se ligam entre si; o último não tem next.
        - Pagamentos, contratos, contratações e propostas com preço passam sempre por uma pessoa (approval antes de enviar).
        - Usa só capacidades e agentes do contexto abaixo. Se faltar uma capacidade, deixa capability vazio e explica na instruction.
        - Entre 3 e 12 blocos, concretos e curtos.

        {$this->context}
        TXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'steps' => $schema->array()->items($schema->object([
                'ref' => $schema->string()->required(),
                'type' => $schema->string()->enum(['agent', 'handoff', 'condition', 'loop', 'repeat', 'wait', 'approval', 'person', 'end'])->required(),
                'label' => $schema->string()->required(),
                'instruction' => $schema->string(),
                'capability' => $schema->string(),
                'agent' => $schema->string(),
                'items' => $schema->string(),
                'until' => $schema->string(),
                'max' => $schema->integer(),
                'hours' => $schema->integer(),
                'inside' => $schema->string(),
                'next' => $schema->string(),
                'yes' => $schema->string(),
                'no' => $schema->string(),
                'approved' => $schema->string(),
                'rejected' => $schema->string(),
            ]))->required(),
        ];
    }
}
