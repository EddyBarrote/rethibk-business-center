<?php

namespace App\Ai\Demo;

use App\Ai\Capabilities\CapabilityCatalog;
use App\Enums\AgentStatus;
use App\Enums\AutonomyLevel;
use App\Models\Agent;
use App\Models\Capability;
use App\Models\User;
use Laravel\Ai\Responses\Data\ToolCall;

/**
 * The E02 acceptance scenario (section 19): an agent at N1 reads from the
 * ERP, then tries to create a lead, which needs N3 and so becomes an
 * approval. Used by agents:demo and by the tests.
 */
final class DemoScenario
{
    public const AGENT_KEY = 'demo';

    public const INPUT = 'Procura clientes na Beira e regista uma oportunidade para a manutenção anual do porto da Beira, valor estimado de 2 500 000 MZN.';

    public function __construct(private readonly CapabilityCatalog $catalog) {}

    /**
     * Create or refresh the demo agent in the current tenant.
     */
    public function ensureAgent(): Agent
    {
        $this->catalog->syncLocal();
        $this->catalog->syncErp();

        $owner = User::query()->orderBy('id')->first();

        $agent = Agent::query()->updateOrCreate(['key' => self::AGENT_KEY], [
            'name' => 'Agente de demonstração',
            'title' => 'Demonstração da E02',
            'description' => 'Agente usado para demonstrar o gate de autonomia e as aprovações.',
            'personality' => 'Directo e cuidadoso. Escreve em português de Moçambique.',
            'instructions' => 'Usa o ERP para encontrar clientes e registar oportunidades comerciais.',
            'status' => AgentStatus::Active,
            'autonomy_level' => AutonomyLevel::Suggest,
            'reports_to_user_id' => $owner?->id,
        ]);

        $capabilities = Capability::query()->whereIn('key', ['erp.crm.search_accounts', 'erp.leads.create'])->pluck('id');
        $agent->capabilities()->syncWithPivotValues($capabilities, ['enabled' => true]);

        return $agent;
    }

    /**
     * What a model would answer, for running the demo without an API key.
     *
     * @return list<ToolCall|string>
     */
    public static function scriptedResponses(): array
    {
        return [
            new ToolCall('demo-1', 'erp_crm_search_accounts', ['query' => 'Beira']),
            new ToolCall('demo-2', 'erp_leads_create', [
                'title' => 'Manutenção anual do porto da Beira',
                'company_name' => 'Cornelder de Moçambique',
                'source' => 'other',
                'estimated_value' => 2500000,
            ]),
            'Encontrei os clientes da Beira e pedi aprovação para registar a oportunidade no ERP, porque criar leads está acima do meu nível de autonomia.',
        ];
    }
}
