<?php

namespace App\Ai\Memory;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * Reads a stretch of an agent's conversation or task and keeps what is worth
 * remembering next time (docs/DECISOES.md, realinhamento L7).
 */
final class MemoryExtractor implements Agent, HasStructuredOutput
{
    use Promptable;

    public function __construct(private readonly string $agentName, private readonly string $known) {}

    public function instructions(): string
    {
        return <<<TXT
        És a memória do agente {$this->agentName}, numa empresa em Moçambique. Lês um excerto de uma conversa ou tarefa dele e guardas o que vale a pena lembrar da próxima vez, em português.

        Guarda factos duradouros e úteis: decisões, preferências de trabalho, regras, prazos combinados, contactos, condições de clientes e fornecedores, como uma pessoa gosta de receber o trabalho.
        Não guardes: pedidos pontuais já resolvidos, cumprimentos, o que já está no que o agente sabe (abaixo), números que mudam todos os dias, palavras-passe ou dados bancários.

        Cada facto: uma frase curta e completa, que se entende sozinha (nomes, não "ele").
        kind: "work" para factos de trabalho (servem para qualquer colega); "personal" para o que é pessoal ou que a pessoa pediu para ficar entre vocês.
        about: o nome da pessoa a quem um facto pessoal diz respeito; vazio nos factos de trabalho.
        Se nada vale a pena, devolve uma lista vazia.

        O que o agente já sabe:
        {$this->known}
        TXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'facts' => $schema->array()->items($schema->object([
                'content' => $schema->string()->required(),
                'kind' => $schema->string()->enum(['work', 'personal'])->required(),
                'about' => $schema->string(),
            ]))->required(),
        ];
    }
}
