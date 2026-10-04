<?php

namespace App\Ai\Capabilities\Local;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Capabilities\LocalCapability;
use App\Finance\BankReconciler;
use Illuminate\Contracts\JsonSchema\JsonSchema;

final class ListUnreconciled extends LocalCapability
{
    public function __construct(private readonly BankReconciler $reconciler) {}

    public function key(): string
    {
        return 'bank.unreconciled';
    }

    public function name(): string
    {
        return 'Movimentos por reconciliar';
    }

    public function description(): string
    {
        return 'Movimentos bancários por reconciliar, cada um com as facturas em dívida no ERP que lhe podem corresponder (pelo número ou pelo montante).';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['limit' => $schema->integer()->min(1)->max(200)];
    }

    public function execute(array $arguments, CapabilityContext $context): CapabilityResult
    {
        $open = $this->reconciler->openWithCandidates($context->agent, (int) ($arguments['limit'] ?? 50));

        return CapabilityResult::data(['count' => count($open), 'transactions' => $open]);
    }
}
