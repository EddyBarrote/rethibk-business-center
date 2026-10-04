<?php

namespace App\Ai\Skills\Local;

use App\Ai\Skills\LocalSkill;
use App\Ai\Skills\SkillContext;
use App\Ai\Skills\SkillResult;
use App\Insights\SlaMonitor;
use Illuminate\Contracts\JsonSchema\JsonSchema;

final class SlaStatus extends LocalSkill
{
    public function __construct(private readonly SlaMonitor $sla) {}

    public function key(): string
    {
        return 'clients.sla_status';
    }

    public function name(): string
    {
        return 'Pedidos de clientes por responder';
    }

    public function description(): string
    {
        return 'Pedidos de clientes ainda sem resposta, há quantas horas esperam e se já passaram o SLA (do contrato do cliente ou o da organização).';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function execute(array $arguments, SkillContext $context): SkillResult
    {
        $pending = $this->sla->pending();

        return SkillResult::data(['pending' => $pending, 'breached' => count(array_filter($pending, fn (array $row) => $row['breached']))]);
    }
}
