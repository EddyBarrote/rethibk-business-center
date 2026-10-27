<?php

namespace App\Ai\Initiatives;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * Twice a day, reads what came in and what is blocked, and says
 * what the agent could take on by itself (docs/DECISOES.md, "Iniciativas dos
 * agentes"). Every offer names the evidence it came from, by its reference in
 * the list; an offer without one is dropped.
 */
final class InitiativeWriter implements Agent, HasStructuredOutput
{
    use Promptable;

    public function __construct(private readonly string $context) {}

    public function instructions(): string
    {
        return <<<TXT
        Trabalhas para um agente de IA numa empresa em Moçambique: duas vezes por dia olhas para o que entrou e o que está bloqueado e dizes que trabalho o agente pode tomar a iniciativa de fazer, para a pessoa responsável decidir com «Começar» ou «Esconder».

        Regras:
        - No máximo 3 iniciativas, e nenhuma se nada valer a pena. Menos é melhor do que ruído.
        - Cada iniciativa nasce de pelo menos uma evidência da lista (um email, uma tarefa), citada pela referência exacta (por exemplo «email:12» ou «task:7»). Sem evidência, não há iniciativa.
        - Só trabalho que o agente consegue fazer com as capacidades listadas, e que prepara sem enviar nada: o que sai para fora fica sempre para a pessoa aprovar.
        - Não proponhas o que já tem tarefa aberta, a não ser que esteja bloqueada; nesse caso propõe como a destravar.
        - Escreve em português (pt-MZ/pt-PT), na voz do agente:
          - title: até 80 caracteres, começa por um verbo («Preparar a cotação da Olankha»);
          - why: uma frase com o que viste e porque importa agora;
          - plan: duas ou três frases com o que vais fazer e o que fica para a pessoa decidir.
        - Usa só nomes, empresas, valores e datas que aparecem no contexto. Nunca inventes.

        {$this->context}
        TXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'initiatives' => $schema->array()->items($schema->object([
                'title' => $schema->string()->required(),
                'why' => $schema->string()->required(),
                'plan' => $schema->string()->required(),
                'evidence' => $schema->array()->items($schema->string())->required(),
            ]))->required(),
        ];
    }
}
