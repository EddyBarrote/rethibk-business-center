<?php

namespace App\Ai\Runs;

use App\Ai\Agents\GenericAgent;
use App\Ai\Agents\InstructionComposer;
use App\Ai\Agents\ToolResolver;
use App\Ai\Budget\BudgetExceeded;
use App\Ai\Budget\BudgetGuard;
use App\Ai\Budget\Pricing;
use App\Ai\Skills\SkillContext;
use App\Enums\AuditResult;
use App\Enums\RunStatus;
use App\Enums\StepType;
use App\Enums\TriggerType;
use App\Events\AgentRunFinished;
use App\Events\AgentRunStarted;
use App\Jobs\RunAgent;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Laravel\Ai\Events\StepCompleted;
use Throwable;

/**
 * The only place agents run (section 6.4): creates the run, builds the
 * agent from its configuration, runs it, records steps, tokens and cost,
 * enforces the budget, and closes the run.
 */
final class AgentRunner
{
    /**
     * Cost of the run in progress, per run id, fed by StepCompleted.
     *
     * @var array<int, float>
     */
    private array $runCosts = [];

    public function __construct(
        private readonly InstructionComposer $composer,
        private readonly ToolResolver $tools,
        private readonly RunRecorder $recorder,
        private readonly BudgetGuard $budget,
        private readonly Pricing $pricing,
    ) {}

    /**
     * Queue a run (agents never run inside an HTTP request, section 3.2).
     */
    public function dispatch(Agent $agent, string $input, TriggerType $trigger, ?User $requestedBy = null, ?Model $source = null): AgentRun
    {
        $run = $this->create($agent, $input, $trigger, $requestedBy, $source);

        RunAgent::dispatch($run->tenant_id, $run->id)
            ->onQueue((string) config('agents.queues.'.$trigger->value, 'agents'));

        return $run;
    }

    /**
     * Record a queued run without dispatching it.
     */
    public function create(Agent $agent, string $input, TriggerType $trigger, ?User $requestedBy = null, ?Model $source = null): AgentRun
    {
        return AgentRun::query()->create([
            'agent_id' => $agent->id,
            'trigger_type' => $trigger,
            'trigger_source_type' => $source?->getMorphClass(),
            'trigger_source_id' => $source?->getKey(),
            'requested_by_user_id' => $requestedBy?->id,
            'status' => RunStatus::Queued,
            'input' => $input,
        ]);
    }

    public function run(AgentRun $run): AgentRun
    {
        if ($run->status !== RunStatus::Queued) {
            return $run;
        }

        $agent = $run->agent;
        $started = hrtime(true);

        $run->forceFill(['status' => RunStatus::Running, 'started_at' => now()])->save();
        AgentRunStarted::live($run);

        try {
            if (! $agent->isActive()) {
                throw new BudgetExceeded('agent', "O agente está {$agent->status->label()}: não pode correr.");
            }

            $this->budget->assertCanRun($agent);

            $context = new SkillContext($agent, $run);
            $generic = new GenericAgent($agent, $run, $this->composer->for($agent), $this->tools->for($context));

            $this->runCosts[$run->id] = 0.0;
            $response = $generic->prompt($run->input);

            $provider = $response->meta->provider;
            $model = $response->meta->model;
            $input = $response->usage->inputTokens;
            $output = $response->usage->outputTokens;

            $this->recorder->step($run, StepType::Message, ['text' => $response->text]);

            $run->forceFill([
                'status' => $run->approvals()->pending()->exists() ? RunStatus::AwaitingApproval : RunStatus::Completed,
                'output' => ['text' => $response->text],
                'provider' => $provider,
                'model' => $model,
                'input_tokens' => $input,
                'output_tokens' => $output,
                'cost_usd' => $this->pricing->cost($provider, $model, $input, $output),
            ]);
        } catch (Throwable $e) {
            $message = $e instanceof BudgetExceeded ? $e->getMessage() : 'O agente falhou: '.Str::limit($e->getMessage(), 500);

            $this->recorder->step($run, StepType::Error, ['message' => $message]);

            $run->forceFill([
                'status' => RunStatus::Failed,
                'error' => $message,
                'cost_usd' => $this->runCosts[$run->id] ?? 0.0,
            ]);

            if (! $e instanceof BudgetExceeded) {
                report($e);
            }
        } finally {
            unset($this->runCosts[$run->id]);
        }

        $run->forceFill([
            'duration_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
            'finished_at' => $run->status === RunStatus::AwaitingApproval ? null : now(),
        ])->save();

        AuditLog::record($agent, 'agent.run', [
            'agent_run_id' => $run->id,
            'trigger' => $run->trigger_type->value,
            'status' => $run->status->value,
            'cost_usd' => $run->cost_usd,
        ], $run->status === RunStatus::Failed ? AuditResult::Error : AuditResult::Ok, $run);

        $this->budget->afterRun($run);
        AgentRunFinished::live($run);

        return $run;
    }

    /**
     * Per-run cap (section 14.3): checked after every model step, so a run
     * that loops on tools is stopped mid-way.
     */
    public function onStepCompleted(StepCompleted $event): void
    {
        if (! $event->agent instanceof GenericAgent || ! isset($this->runCosts[$event->agent->run->id])) {
            return;
        }

        $usage = $event->response->usage;
        $this->runCosts[$event->agent->run->id] += $this->pricing->cost(
            $event->provider->name(), $event->model, $usage->inputTokens, $usage->outputTokens,
        );

        $this->budget->assertRunWithinCap($this->runCosts[$event->agent->run->id]);
    }
}
