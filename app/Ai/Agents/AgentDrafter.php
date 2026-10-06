<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * Drafts a new colleague from an admin's description: identity, personality,
 * instructions and which of the tenant's capabilities and skills it needs.
 * The admin reviews the draft in the agent form before anything is saved.
 */
final class AgentDrafter implements Agent, HasStructuredOutput
{
    use Promptable;

    public function __construct(private readonly string $catalogue) {}

    public function instructions(): string
    {
        return <<<TXT
        Desenhas agentes de IA que trabalham como colegas numa empresa em Moçambique, numa plataforma chamada MICOMOC.
        O administrador descreve o colega de que precisa; propões a definição completa, em português (pt-MZ/pt-PT).

        Regras:
        - name: um nome próprio de pessoa, curto e fácil (ex.: Amélia, Tomás, Neusa), adequado a Moçambique. Não uses "Agente".
        - key: identificador em minúsculas, só letras, números e hífen (ex.: comercial, apoio-cliente).
        - title: a função, como num cartão de visita (ex.: Gestora de Propostas Comerciais).
        - description: uma frase sobre o que faz e para quem.
        - personality: 2 a 4 frases sobre como fala e se comporta.
        - instructions: markdown com o que faz, como faz, o que nunca faz e quando pergunta a uma pessoa. Concreto, sem generalidades.
        - autonomy_level: 0 observa, 1 sugere, 2 executa com aprovação, 3 executa dentro de limites, 4 executa e reporta. Na dúvida, 1 ou 2.
        - capabilities e skills: escolhe só chaves que existam no catálogo abaixo, as estritamente necessárias.
        - suggested_skills: skills que ainda não existem e fariam falta a este colega (nome e quando se aplica), no máximo 3.

        Catálogo desta empresa:
        {$this->catalogue}
        TXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->required(),
            'key' => $schema->string()->required(),
            'title' => $schema->string()->required(),
            'description' => $schema->string()->required(),
            'personality' => $schema->string()->required(),
            'instructions' => $schema->string()->required(),
            'autonomy_level' => $schema->integer()->min(0)->max(4)->required(),
            'capabilities' => $schema->array()->items($schema->string())->required(),
            'skills' => $schema->array()->items($schema->string())->required(),
            'suggested_skills' => $schema->array()->items($schema->object([
                'name' => $schema->string()->required(),
                'description' => $schema->string()->required(),
            ]))->required(),
        ];
    }
}
