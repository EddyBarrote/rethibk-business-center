<?php

namespace App\Ai\Capabilities\Local;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Capabilities\LocalCapability;
use App\Insights\SlaMonitor;
use Illuminate\Contracts\JsonSchema\JsonSchema;

final class SlaStatus extends LocalCapability
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

    public function execute(array $arguments, CapabilityContext $context): CapabilityResult
    {
        $pending = $this->sla->pending();

        return CapabilityResult::data(['pending' => $pending, 'breached' => count(array_filter($pending, fn (array $row) => $row['breached']))]);
    }
}
