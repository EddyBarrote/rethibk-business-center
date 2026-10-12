<?php

namespace App\Ai\Capabilities\Local;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Capabilities\LocalCapability;
use App\Ai\Knowledge\KnowledgeAccess;
use App\Ai\Knowledge\KnowledgeLocator;
use App\Ai\Memory\CompanyMemory;
use App\Enums\AutonomyLevel;
use App\Enums\CompanySection;
use App\Models\CompanyFact;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * An agent proposes a fact for the company memory (docs/DECISOES.md,
 * 12.10.2026). Nothing changes until a person approves it, so every agent
 * has it and it never waits for the autonomy gate.
 */
final class ProposeCompanyFact extends LocalCapability
{
    public function __construct(
        private readonly CompanyMemory $memory,
        private readonly KnowledgeAccess $access,
        private readonly KnowledgeLocator $locator,
    ) {}

    public function key(): string
    {
        return 'company.propose_fact';
    }

    public function name(): string
    {
        return 'Propor facto à memória da empresa';
    }

    public function description(): string
    {
        return 'Propõe um facto duradouro que vale para toda a empresa (quem somos, clientes e parceiros, regras, preços e condições, pessoas e papéis, decisões). Uma pessoa aprova antes de todos os agentes o saberem. Para corrigir um facto da memória da empresa, indique o número dele em replaces_id. Não use para factos pessoais nem pedidos pontuais.';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function defaultRisk(): AutonomyLevel
    {
        return AutonomyLevel::Observe;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'section' => $schema->string()->enum(array_column(CompanySection::cases(), 'value'))->description('about, clients, rules, pricing, people ou decisions.')->required(),
            'content' => $schema->string()->description('Uma ou duas frases completas, que se entendem sozinhas (nomes, datas, valores).')->required(),
            'replaces_id' => $schema->integer()->description('O número do facto que este corrige, se for o caso.'),
            'domain' => $schema->string()->description('Só se o facto não deve chegar a todos: o domínio do conhecimento a que fica limitado (ex.: "Recursos Humanos").'),
        ];
    }

    public function execute(array $arguments, CapabilityContext $context): CapabilityResult
    {
        $data = Validator::make($arguments, [
            'section' => ['required', Rule::enum(CompanySection::class)],
            'content' => 'required|string|max:'.CompanyMemory::MAX_FACT,
            'replaces_id' => 'nullable|integer',
            'domain' => 'nullable|string|max:120',
        ])->validate();

        $domain = null;

        if (filled($data['domain'] ?? null)) {
            $this->access->ensureDefaults();
            $domain = $this->locator->domain($data['domain']);

            if ($domain === null || ! $this->access->canOpen($context->agent, $domain)) {
                return CapabilityResult::error('Domínio não encontrado ou sem acesso. Deixe vazio para um facto de toda a empresa.');
            }
        }

        $replaces = isset($data['replaces_id']) ? CompanyFact::query()->where('status', CompanyFact::PUBLISHED)->find($data['replaces_id']) : null;

        if (isset($data['replaces_id']) && $replaces === null) {
            return CapabilityResult::error('Não há nenhum facto publicado com esse número.');
        }

        $fact = $this->memory->propose($context->agent, CompanySection::from($data['section']), $data['content'], $domain, $replaces, $context->run->task);

        return CapabilityResult::data([
            'fact_id' => $fact->id,
            'status' => $fact->status === CompanyFact::PUBLISHED ? 'já está na memória da empresa' : 'à espera de aprovação por uma pessoa',
        ]);
    }

    public function summarise(array $arguments): string
    {
        return 'Propor à memória da empresa: '.($arguments['content'] ?? '');
    }
}
