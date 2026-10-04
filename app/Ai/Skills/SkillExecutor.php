<?php

namespace App\Ai\Skills;

use App\Enums\SkillSource;
use App\Erp\ErpGateway;
use App\Erp\Exceptions\ErpException;
use App\Models\Skill;
use Illuminate\Validation\ValidationException;

/**
 * Runs a skill once the gate (or a human) has allowed it. Knows nothing about
 * autonomy: callers decide whether it may run.
 */
final class SkillExecutor
{
    public function __construct(
        private readonly SkillRegistry $registry,
        private readonly ErpGateway $erp,
        private readonly RecordLinker $linker,
    ) {}

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function execute(Skill $skill, array $arguments, SkillContext $context): SkillResult
    {
        if (! $skill->is_available) {
            return SkillResult::error("A competência {$skill->key} não está disponível.");
        }

        return $skill->source === SkillSource::Mcp
            ? $this->erp($skill, $arguments, $context)
            : $this->local($skill, $arguments, $context);
    }

    /**
     * ERP write tools need an idempotency key (section 8.3). The platform
     * derives it, so a retried run or an approval executed twice never
     * writes twice.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function withIdempotencyKey(Skill $skill, array $arguments, SkillContext $context): array
    {
        $required = $skill->input_schema['required'] ?? [];

        if (! is_array($required) || ! in_array('idempotency_key', $required, true)) {
            return $arguments;
        }

        $scope = $context->approval !== null ? 'approval-'.$context->approval->id : 'run-'.$context->run->id;
        unset($arguments['idempotency_key']);
        ksort($arguments);

        return [...$arguments, 'idempotency_key' => $scope.'-'.substr(hash('sha256', $skill->key.json_encode($arguments)), 0, 16)];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function erp(Skill $skill, array $arguments, SkillContext $context): SkillResult
    {
        try {
            $result = $this->erp->call(
                (string) $skill->mcp_tool_name,
                $this->withIdempotencyKey($skill, $arguments, $context),
                $context->agent,
                ['agent_run_id' => $context->run->id, 'approval_id' => $context->approval?->id],
            );
        } catch (ErpException $e) {
            return SkillResult::error($e->getMessage());
        }

        if (! $result->ok) {
            return SkillResult::error((string) $result->error());
        }

        $this->linker->afterErpWrite($skill, $result->data, $context);

        return $result->data !== null ? SkillResult::data($result->data) : SkillResult::text($result->text);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function local(Skill $skill, array $arguments, SkillContext $context): SkillResult
    {
        $local = $this->registry->find($skill->key);

        if ($local === null) {
            return SkillResult::error("A competência {$skill->key} não existe nesta versão da plataforma.");
        }

        try {
            return $local->execute($arguments, $context);
        } catch (ValidationException $e) {
            return SkillResult::error(implode(' ', $e->validator->errors()->all()));
        }
    }
}
