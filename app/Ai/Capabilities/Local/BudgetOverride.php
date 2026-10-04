<?php

namespace App\Ai\Capabilities\Local;

use App\Ai\Budget\BudgetGuard;
use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Capabilities\LocalCapability;
use App\Enums\AutonomyLevel;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * The budget exception (Paperclip's budget_override_required approval): when a
 * monthly cap is used up, the platform asks a person to allow extra spend for
 * the rest of the month. Approving it raises the cap and wakes the agents the
 * cap had suspended. Never run by an agent on its own: always a human decision.
 */
final class BudgetOverride extends LocalCapability
{
    public function __construct(private readonly BudgetGuard $budget) {}

    public function key(): string
    {
        return 'budget.override';
    }

    public function name(): string
    {
        return 'Excepção de orçamento';
    }

    public function description(): string
    {
        return 'Autoriza gasto extra de IA até ao fim do mês e reactiva os agentes parados pelo tecto. Pedido pela plataforma; decide sempre uma pessoa.';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function defaultRisk(): AutonomyLevel
    {
        return AutonomyLevel::ExecuteAndReport;
    }

    public function ceilingReason(array $arguments, CapabilityContext $context): string
    {
        return 'aumentar o orçamento de IA é sempre uma decisão humana';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'scope' => $schema->string()->enum(['tenant', 'agent'])->required(),
            'agent_id' => $schema->integer(),
            'extra_usd' => $schema->number()->required(),
            'period' => $schema->string()->description('AAAA-MM')->required(),
        ];
    }

    public function execute(array $arguments, CapabilityContext $context): CapabilityResult
    {
        if ($context->approval === null) {
            return CapabilityResult::error('uma excepção de orçamento só existe aprovada por uma pessoa.');
        }

        $data = Validator::make($arguments, [
            'scope' => ['required', Rule::in(['tenant', 'agent'])],
            'agent_id' => 'required_if:scope,agent|nullable|integer',
            'extra_usd' => 'required|numeric|min:0.01|max:100000',
            'period' => 'required|date_format:Y-m',
        ])->validate();

        $reactivated = $this->budget->grantExtra($data['scope'], $data['agent_id'] ?? null, (float) $data['extra_usd'], $data['period']);

        return CapabilityResult::data(['extra_usd' => (float) $data['extra_usd'], 'period' => $data['period'], 'reactivated' => $reactivated]);
    }

    public function summarise(array $arguments): string
    {
        return sprintf(
            'Autorizar mais %.2f USD de IA em %s (%s)',
            (float) ($arguments['extra_usd'] ?? 0),
            $arguments['period'] ?? '',
            ($arguments['scope'] ?? '') === 'tenant' ? 'toda a organização' : 'este agente',
        );
    }
}
