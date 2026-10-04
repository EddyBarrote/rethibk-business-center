<?php

namespace App\Ai\Tools;

use App\Ai\Autonomy\AutonomyGate;
use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityExecutor;
use App\Ai\Capabilities\CapabilityRegistry;
use App\Ai\Runs\ApprovalService;
use App\Ai\Runs\RunRecorder;
use App\Enums\AuditResult;
use App\Enums\CapabilitySource;
use App\Enums\StepType;
use App\Models\AuditLog;
use App\Models\Capability;
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
 * Every capability reaches the model wrapped in a GatedTool (section 12.2): the
 * call is logged on the run, checked by the autonomy gate, and either runs
 * or becomes an approval request. No agent code calls a capability directly.
 */
final class GatedTool implements Tool
{
    public function __construct(
        private readonly Capability $capability,
        private readonly CapabilityContext $context,
    ) {}

    /**
     * Provider-safe name: letters, digits, _ and - only.
     */
    public function name(): string
    {
        return Str::limit((string) preg_replace('/[^a-zA-Z0-9_-]/', '_', $this->capability->key), 64, '');
    }

    public function description(): string
    {
        $description = $this->capability->description ?: $this->capability->name;

        return $this->capability->is_mutating && $this->context->agent->autonomy_level->value < $this->capability->risk->value
            ? $description.' (Precisa de aprovação humana: ao chamar, a acção fica pendente.)'
            : $description;
    }

    public function schema(JsonSchema $schema): array
    {
        if ($this->capability->source === CapabilitySource::Local) {
            return app(CapabilityRegistry::class)->find($this->capability->key)?->schema($schema) ?? [];
        }

        $input = $this->capability->input_schema ?? [];

        // The platform supplies the idempotency key itself (CapabilityExecutor).
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

        $recorder->step($run, StepType::ToolCall, ['arguments' => $arguments], $this->capability->key);

        $decision = app(AutonomyGate::class)->evaluate($this->capability, $arguments, $this->context);

        if (! $decision->allowed) {
            $approval = app(ApprovalService::class)->request($this->capability, $arguments, $decision, $this->context);

            return "Acção suspensa e enviada para aprovação humana (#{$approval->id}): {$decision->reason()} "
                .'Não voltes a tentar executá-la nesta execução. Continua com o resto do trabalho e diz no fim que ficou à espera de aprovação.';
        }

        $started = hrtime(true);
        $result = app(CapabilityExecutor::class)->execute($this->capability, $arguments, $this->context);
        $duration = (int) round((hrtime(true) - $started) / 1_000_000);

        // ERP calls are audited by the ErpGateway, with the run id.
        if ($this->capability->source === CapabilitySource::Local) {
            AuditLog::record($this->context->agent, $this->capability->key, [
                'agent_run_id' => $run->id,
                'arguments' => $arguments,
                'result' => $result->ok ? Str::limit($result->content, 2000) : null,
                'error' => $result->ok ? null : $result->content,
            ], $result->ok ? AuditResult::Ok : AuditResult::Error);
        }

        $recorder->step($run, $result->ok ? StepType::ToolResult : StepType::Error, [
            'ok' => $result->ok,
            'content' => Str::limit($result->content, 4000),
        ], $this->capability->key, $duration);

        return $result->content;
    }
}
