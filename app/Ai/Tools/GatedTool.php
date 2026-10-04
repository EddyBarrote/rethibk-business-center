<?php

namespace App\Ai\Tools;

use App\Ai\Autonomy\AutonomyGate;
use App\Ai\Runs\ApprovalService;
use App\Ai\Runs\RunRecorder;
use App\Ai\Skills\SkillContext;
use App\Ai\Skills\SkillExecutor;
use App\Ai\Skills\SkillRegistry;
use App\Enums\AuditResult;
use App\Enums\SkillSource;
use App\Enums\StepType;
use App\Models\AuditLog;
use App\Models\Skill;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\JsonSchema as JsonSchemaFactory;
use Illuminate\JsonSchema\Types\ObjectType;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Schema\SchemaNormalizer;
use Laravel\Ai\Tools\Request;
use Throwable;

/**
 * Every skill reaches the model wrapped in a GatedTool (section 12.2): the
 * call is logged on the run, checked by the autonomy gate, and either runs
 * or becomes an approval request. No agent code calls a skill directly.
 */
final class GatedTool implements Tool
{
    public function __construct(
        private readonly Skill $skill,
        private readonly SkillContext $context,
    ) {}

    /**
     * Provider-safe name: letters, digits, _ and - only.
     */
    public function name(): string
    {
        return Str::limit((string) preg_replace('/[^a-zA-Z0-9_-]/', '_', $this->skill->key), 64, '');
    }

    public function description(): string
    {
        $description = $this->skill->description ?: $this->skill->name;

        return $this->skill->is_mutating && $this->context->agent->autonomy_level->value < $this->skill->risk->value
            ? $description.' (Precisa de aprovação humana: ao chamar, a acção fica pendente.)'
            : $description;
    }

    public function schema(JsonSchema $schema): array
    {
        if ($this->skill->source === SkillSource::Local) {
            return app(SkillRegistry::class)->find($this->skill->key)?->schema($schema) ?? [];
        }

        $input = $this->skill->input_schema ?? [];

        // The platform supplies the idempotency key itself (SkillExecutor).
        unset($input['properties']['idempotency_key']);

        if (isset($input['required']) && is_array($input['required'])) {
            $input['required'] = array_values(array_diff($input['required'], ['idempotency_key']));
        }

        if ($input === [] || ($input['properties'] ?? []) === []) {
            return [];
        }

        try {
            $type = JsonSchemaFactory::fromArray(SchemaNormalizer::normalize($input));
        } catch (Throwable) {
            return [];
        }

        return $type instanceof ObjectType ? (fn (): array => $this->properties)->call($type) : [];
    }

    public function handle(Request $request): string
    {
        $arguments = Arr::except($request->all(), ['idempotency_key']);
        $recorder = app(RunRecorder::class);
        $run = $this->context->run;

        $recorder->step($run, StepType::ToolCall, ['arguments' => $arguments], $this->skill->key);

        $decision = app(AutonomyGate::class)->evaluate($this->skill, $arguments, $this->context);

        if (! $decision->allowed) {
            $approval = app(ApprovalService::class)->request($this->skill, $arguments, $decision, $this->context);

            return "Acção suspensa e enviada para aprovação humana (#{$approval->id}): {$decision->reason()} "
                .'Não voltes a tentar executá-la nesta execução. Continua com o resto do trabalho e diz no fim que ficou à espera de aprovação.';
        }

        $started = hrtime(true);
        $result = app(SkillExecutor::class)->execute($this->skill, $arguments, $this->context);
        $duration = (int) round((hrtime(true) - $started) / 1_000_000);

        // ERP calls are audited by the ErpGateway, with the run id.
        if ($this->skill->source === SkillSource::Local) {
            AuditLog::record($this->context->agent, $this->skill->key, [
                'agent_run_id' => $run->id,
                'arguments' => $arguments,
                'result' => $result->ok ? Str::limit($result->content, 2000) : null,
                'error' => $result->ok ? null : $result->content,
            ], $result->ok ? AuditResult::Ok : AuditResult::Error);
        }

        $recorder->step($run, $result->ok ? StepType::ToolResult : StepType::Error, [
            'ok' => $result->ok,
            'content' => Str::limit($result->content, 4000),
        ], $this->skill->key, $duration);

        return $result->content;
    }
}
