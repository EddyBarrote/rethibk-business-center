<?php

namespace App\Ai\Tools;

use App\Ai\Autonomy\AutonomyGate;
use App\Ai\Autonomy\PeopleData;
use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityExecutor;
use App\Ai\Capabilities\CapabilityRegistry;
use App\Ai\Runs\ApprovalService;
use App\Ai\Runs\FactCheck;
use App\Ai\Runs\RunRecorder;
use App\Enums\AuditResult;
use App\Enums\CapabilitySource;
use App\Enums\StepType;
use App\Models\AgentQuestion;
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
    /** Characters of a tool result kept in the run's trace. */
    public const TRACE_LIMIT = 200_000;

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

    /**
     * The capability's name in Portuguese, as people see it.
     */
    public function label(): string
    {
        return $this->capability->name;
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

        // After questions.ask the run only waits (docs/DECISOES.md, "Perguntas dos agentes").
        if ($this->capability->key !== 'questions.ask' && AgentQuestion::query()->where('agent_run_id', $run->id)->pending()->exists()) {
            $recorder->step($run, StepType::Error, ['ok' => false, 'content' => 'À espera das respostas às perguntas.'], $this->capability->key);

            return 'Não executado: estás à espera das respostas às tuas perguntas. Termina a tua resposta agora com uma frase curta; continuas quando a pessoa responder.';
        }

        // Pay and personal data only in a routine or for someone who manages people (docs/DECISOES.md, "Director de Recursos Humanos").
        $people = app(PeopleData::class);

        if (($refused = $people->refusal($this->capability, $this->context)) !== null) {
            $recorder->step($run, StepType::Error, ['ok' => false, 'content' => $refused], $this->capability->key);
            AuditLog::record($this->context->agent, $this->capability->key, [
                'agent_run_id' => $run->id,
                'arguments' => $arguments,
                'for_user_id' => $people->personOf($run)?->id,
                'reason' => in_array($this->capability->key, PeopleData::PUBLISHES, true)
                    ? 'Agente com dados de pessoal: não escreve na base de conhecimento nem na memória da empresa.'
                    : 'Dados de pessoal (salários, contratos, documentos, processos disciplinares) fora de uma rotina e sem a permissão «Gerir pessoas».',
            ], AuditResult::Denied);

            return $refused;
        }

        // Never an invented price, term or contact (docs/DECISOES.md, "Perguntar, nunca inventar").
        if (($blocked = app(FactCheck::class)->blocked($this->capability, $arguments, $this->context)) !== null) {
            $recorder->step($run, StepType::Error, ['ok' => false, 'content' => $blocked], $this->capability->key);
            AuditLog::record($this->context->agent, $this->capability->key, [
                'agent_run_id' => $run->id,
                'arguments' => $arguments,
                'error' => $blocked,
            ], AuditResult::Denied);

            return $blocked;
        }

        $local = $this->capability->source === CapabilitySource::Local ? app(CapabilityRegistry::class)->find($this->capability->key) : null;

        if ($local !== null && ($missing = $local->precheck($arguments, $this->context)) !== null) {
            $recorder->step($run, StepType::Error, ['ok' => false, 'content' => $missing], $this->capability->key);

            return $missing;
        }

        $decision = app(AutonomyGate::class)->evaluate($this->capability, $arguments, $this->context);

        if ($decision->denied) {
            $refused = "Recusado: {$decision->reason()} Não tentes de novo nem de outra forma; diz na resposta que esta acção está proibida por uma regra e quem a pode mudar (Definições › Regras de aprovação).";
            $recorder->step($run, StepType::Error, ['ok' => false, 'content' => $refused], $this->capability->key);
            AuditLog::record($this->context->agent, $this->capability->key, [
                'agent_run_id' => $run->id,
                'arguments' => $arguments,
                'rule_id' => $decision->rule?->id,
                'reason' => $decision->reason(),
            ], AuditResult::Denied);

            return $refused;
        }

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
                'rule_id' => $decision->rule?->id,
                'result' => $result->ok ? Str::limit($result->content, 2000) : null,
                'error' => $result->ok ? null : $result->content,
            ], $result->ok ? AuditResult::Ok : AuditResult::Error);
        }

        // The trace keeps what the agent read in full, so every name and value
        // it writes can be traced back to the ERP (docs/DECISOES.md).
        $recorder->step($run, $result->ok ? StepType::ToolResult : StepType::Error, [
            'ok' => $result->ok,
            'content' => Str::limit($result->content, self::TRACE_LIMIT),
        ], $this->capability->key, $duration);

        return $result->content;
    }
}
