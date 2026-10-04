<?php

namespace App\Ai\Skills\Local;

use App\Ai\Skills\LocalSkill;
use App\Ai\Skills\SkillContext;
use App\Ai\Skills\SkillResult;
use App\Insights\IssueDetector;
use Illuminate\Contracts\JsonSchema\JsonSchema;

final class DetectIssues extends LocalSkill
{
    public function __construct(private readonly IssueDetector $detector) {}

    public function key(): string
    {
        return 'platform.detect_issues';
    }

    public function name(): string
    {
        return 'Detectar bloqueios e inconsistências';
    }

    public function description(): string
    {
        return 'Lista bloqueios e inconsistências entre áreas: aprovações paradas, agentes suspensos, leads sem registo no ERP, concursos e requisições a vencer, contratos a terminar, banco por reconciliar, pedidos de clientes fora do SLA.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function execute(array $arguments, SkillContext $context): SkillResult
    {
        $issues = $this->detector->detect();

        return SkillResult::data(['count' => count($issues), 'issues' => $issues]);
    }
}
