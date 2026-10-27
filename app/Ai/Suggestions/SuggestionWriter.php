<?php

namespace App\Ai\Suggestions;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * Writes the four things the entry screen offers to ask an agent today
 * (docs/DECISOES.md, "Sugestões geradas todos os dias"), from what the agent
 * does and what it has been asked lately. Only from the context: no names,
 * numbers or facts that are not in it.
 */
final class SuggestionWriter implements Agent, HasStructuredOutput
{
    use Promptable;

    public function __construct(private readonly string $context) {}

    public function instructions(): string
    {
        return <<<TXT
        Escreves as sugestões do ecrã de entrada de um agente de IA numa plataforma em que agentes e pessoas trabalham juntos numa empresa em Moçambique.
        Uma sugestão é um pedido curto que uma pessoa da empresa pode fazer hoje a este agente, escrito como ela o escreveria, em português (pt-MZ/pt-PT): por exemplo «Que facturas da Mozal estão por cobrar?» ou «Prepara o resumo das cotações de cimento».

        Regras:
        - Exactamente 4 sugestões, cada uma com no máximo 60 caracteres, sem ponto final (um ponto de interrogação nas perguntas).
        - Só pedidos que o agente consegue fazer com as capacidades listadas abaixo.
        - Prefere o que é útil agora: o que as pessoas lhe têm pedido, os clientes, projectos e assuntos que aparecem no trabalho recente e na memória, o momento do mês (início, fecho, fim de semana).
        - Usa só nomes, clientes, projectos e números que aparecem no contexto. Nunca inventes um facto. Sem contexto específico, faz pedidos gerais da função do agente.
        - Varia em relação às sugestões de ontem; podes repetir uma que continue a ser a mais útil.

        {$this->context}
        TXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'suggestions' => $schema->array()->items($schema->string())->required(),
        ];
    }
}
