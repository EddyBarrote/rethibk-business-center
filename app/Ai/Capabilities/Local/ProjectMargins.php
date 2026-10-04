<?php

namespace App\Ai\Capabilities\Local;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Capabilities\LocalCapability;
use App\Erp\ErpGateway;
use App\Erp\Exceptions\ErpException;
use App\Support\TenantSettings;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Margin per project from the ERP, with deviation alerts (E05):
 * budget used above the alert level, or margin on invoiced work below the
 * minimum. Thresholds are tenant settings.
 */
final class ProjectMargins extends LocalCapability
{
    public function __construct(private readonly ErpGateway $erp) {}

    public function key(): string
    {
        return 'finance.project_margins';
    }

    public function name(): string
    {
        return 'Margem por projecto';
    }

    public function description(): string
    {
        return 'Calcula, a partir do ERP, orçamento consumido, facturado, custo e margem de cada projecto, e assinala desvios (orçamento acima do limite de alerta ou margem abaixo do mínimo).';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['status' => $schema->string()->enum(['planned', 'in_progress', 'on_hold', 'completed', 'cancelled'])->description('Por omissão: em curso.')];
    }

    public function execute(array $arguments, CapabilityContext $context): CapabilityResult
    {
        try {
            $result = $this->erp->call('projects.list', ['status' => $arguments['status'] ?? 'in_progress'], $context->agent, ['agent_run_id' => $context->run->id]);
        } catch (ErpException $e) {
            return CapabilityResult::error($e->getMessage());
        }

        if (! $result->ok) {
            return CapabilityResult::error((string) $result->error());
        }

        return CapabilityResult::data(self::analyse((array) ($result->data['projects'] ?? [])));
    }

    /**
     * @param  array<int, mixed>  $projects
     * @return array{thresholds: array{min_margin_pct: int, budget_alert_pct: int}, projects: list<array<string, mixed>>, alerts: list<string>}
     */
    public static function analyse(array $projects): array
    {
        $minMargin = TenantSettings::int('min_margin_pct');
        $budgetAlert = TenantSettings::int('budget_alert_pct');
        $rows = [];
        $alerts = [];

        foreach ($projects as $project) {
            $budget = (float) ($project['budget'] ?? 0);
            $spent = (float) ($project['spent'] ?? 0);
            $invoiced = (float) ($project['invoiced'] ?? 0);
            $usedPct = $budget > 0 ? round($spent / $budget * 100, 1) : null;
            $margin = round($invoiced - $spent, 2);
            $marginPct = $invoiced > 0 ? round($margin / $invoiced * 100, 1) : null;
            $flags = [];

            if ($usedPct !== null && $usedPct >= $budgetAlert) {
                $flags[] = "orçamento consumido {$usedPct}% (alerta a {$budgetAlert}%)";
            }

            if ($marginPct !== null && $marginPct < $minMargin) {
                $flags[] = "margem sobre facturado {$marginPct}% (mínimo {$minMargin}%)";
            }

            $rows[] = [
                'id' => $project['id'] ?? null, 'name' => $project['name'] ?? null, 'status' => $project['status'] ?? null,
                'budget' => $budget, 'spent' => $spent, 'invoiced' => $invoiced, 'budget_used_pct' => $usedPct,
                'margin' => $margin, 'margin_pct' => $marginPct, 'flags' => $flags,
            ];

            foreach ($flags as $flag) {
                $alerts[] = ($project['name'] ?? $project['id'] ?? 'Projecto').": {$flag}";
            }
        }

        return ['thresholds' => ['min_margin_pct' => $minMargin, 'budget_alert_pct' => $budgetAlert], 'projects' => $rows, 'alerts' => $alerts];
    }
}
