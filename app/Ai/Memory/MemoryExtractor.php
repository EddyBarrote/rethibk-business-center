<?php

namespace App\Ai\Memory;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * Reads a stretch of an agent's conversation or task and updates the memory
 * entries worth keeping (docs/DECISOES.md, realinhamento L7; entries since
 * 12.10.2026). Also sorts the loose notes of the old memory into entries
 * (MemoryConsolidator::organise()).
 */
final class MemoryExtractor implements Agent, HasStructuredOutput
{
    use Promptable;

    public function __construct(
        private readonly string $agentName,
        private readonly string $known,
        private readonly string $company = '(nada ainda)',
        private readonly string $summary = '(nada ainda)',
        private readonly ?string $person = null,
        private readonly bool $sensitive = true,
        private readonly bool $remember = true,
    ) {}

    public function instructions(): string
    {
        $who = $this->person !== null ? "a memória que {$this->agentName} tem de {$this->person}" : "a memória do trabalho de {$this->agentName} sem pessoa (rotinas, email)";
        $sensitive = $this->sensitive
            ? 'Temas sensíveis (saúde, religião, política, vida familiar ou amorosa, dinheiro pessoal, questões legais) só se a pessoa os contou e servem para o trabalho, e sempre com kind "personal".'
            : 'A pessoa pediu para não guardar temas sensíveis: nada de saúde, religião, política, vida familiar ou amorosa, dinheiro pessoal ou questões legais, mesmo que os conte.';
        $entries = $this->remember ? <<<TXT
        Mantens {$who}, organizada em entradas com nome, como um caderno. Lês um excerto de uma conversa ou tarefa e devolves só as entradas que mudam ou que são novas.

        Secções:
        - preferences (Sobre si · Preferências): como a pessoa quer que o agente trabalhe: tom, formato, horários, o que evitar. Normalmente uma só entrada, «Preferências».
        - profile (Sobre si · Perfil): quem é a pessoa: cargo, equipa, de que trata, com quem trabalha. Normalmente uma só entrada, «Perfil».
        - topics (Temas): assuntos recorrentes, um por entrada (por exemplo «Cobranças», «Relatórios mensais»).
        - areas (Áreas): uma entrada por cliente, parceiro, fornecedor ou frente de trabalho (por exemplo «Loop Experience»).
        - people (Pessoas): uma entrada por pessoa de quem se fala, com o nome como título.
        - projects (Projectos): uma entrada por projecto: objectivo, estado, prazos, o que falta.
        Na memória sem pessoa, não uses preferences nem profile.

        Cada entrada: title curto (o nome do cliente, da pessoa, do tema), description com uma linha que diz o que lá está, e body com o texto completo em frases curtas ou tópicos com «- ». Para mudar uma entrada que já existe, usa exactamente o mesmo title e devolve o body inteiro já actualizado (junta o novo ao que lá estava, corrige o que mudou, tira o que deixou de ser verdade). Não cries uma entrada nova para o que cabe numa que já existe.
        A memória nunca tem contradições. Se o excerto muda ou desmente algo que está numa entrada, mesmo com outro título ou noutra secção, actualiza essa entrada (com o title dela) e tira o que deixou de ser verdade; nunca deixes uma entrada a dizer uma coisa e outra a dizer o contrário. Se a mesma coisa está em duas entradas, junta-as numa e põe a outra em forget.
        Guarda o que é duradouro e útil: decisões, preferências, regras, prazos combinados, contactos, condições de clientes e fornecedores, como uma pessoa gosta de receber o trabalho.
        Não guardes: pedidos pontuais já resolvidos, cumprimentos, números que mudam todos os dias, palavras-passe ou dados bancários.
        {$sensitive}
        kind: "work" para o que serve a qualquer colega; "personal" para o que é pessoal ou que a pessoa pediu para ficar entre vocês (preferences e profile são sempre "personal").
        about: numa entrada de people, o nome da pessoa; vazio nas outras.
        forget: os títulos de entradas que deixaram de ser verdade de todo ou que a pessoa pediu para esquecer.
        Se nada muda, devolve listas vazias.

        company: factos de trabalho que valem para toda a empresa e para todos os agentes (quem somos, condições de um cliente ou parceiro, uma regra ou política, um preço, quem decide o quê, uma decisão da direcção) e que ainda não estão na memória da empresa (abaixo), cada um numa frase que se entende sozinha. Uma pessoa aprova-os antes de chegarem a todos. section: about, clients, rules, pricing, people ou decisions. replaces: quando o facto corrige ou contradiz um da memória da empresa, o número desse (o #); ao ser aprovado, o antigo sai. Nunca proponhas um facto que contradiz outro sem o indicar em replaces.
        TXT : 'A pessoa desligou a memória: não guardes nada dela (entries, forget e company vazios).';

        return <<<TXT
        És a memória do agente {$this->agentName}, numa empresa em Moçambique. Escreves em português.

        {$entries}

        summary: onde está esta conversa ou tarefa, para o agente retomar quando as mensagens antigas já não estiverem à vista. Parte do resumo anterior (abaixo) e actualiza-o com o excerto, em 3 a 10 frases curtas: o que se está a tratar, o que ficou decidido ou combinado (com datas), o que falta e quem o deve. Tira o que ficou resolvido e já não interessa. Não repitas o que está nas entradas.

        O resumo anterior:
        {$this->summary}

        As entradas que já existem:
        {$this->known}

        A memória da empresa:
        {$this->company}
        TXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'entries' => $schema->array()->items($schema->object([
                'section' => $schema->string()->enum(['preferences', 'profile', 'topics', 'areas', 'people', 'projects'])->required(),
                'title' => $schema->string()->required(),
                'description' => $schema->string()->required(),
                'body' => $schema->string()->required(),
                'kind' => $schema->string()->enum(['work', 'personal'])->required(),
                'about' => $schema->string(),
            ]))->required(),
            'forget' => $schema->array()->items($schema->string()),
            'company' => $schema->array()->items($schema->object([
                'content' => $schema->string()->required(),
                'section' => $schema->string()->enum(['about', 'clients', 'rules', 'pricing', 'people', 'decisions'])->required(),
                'replaces' => $schema->integer(),
            ])),
            'summary' => $schema->string(),
        ];
    }
}
