<?php

namespace App\Audit;

use App\Ai\Autonomy\PeopleData;
use App\Enums\ActorType;
use App\Models\Agent;
use App\Models\AgentHandoff;
use App\Models\AgentInitiative;
use App\Models\AgentMemory;
use App\Models\AgentQuestion;
use App\Models\AgentRoutine;
use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\AuditLog;
use App\Models\Briefing;
use App\Models\Capability;
use App\Models\FollowUp;
use App\Models\GeneratedDocument;
use App\Models\Report;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;

/**
 * Audit entries with pay and personal data (docs/DECISOES.md, "Agente
 * confidencial" and "Auditoria pesquisável e exportável"): what a
 * confidential agent did or was the object of, and every call of a
 * people-data capability, whoever made it. Whoever may not manage people
 * sees the row (when, who, what, result) but not its details: the arguments,
 * the results and the errors, nor the name of what it touched.
 *
 * One definition, as a query: the page and the export mask the same rows,
 * and the free-text search leaves their details out.
 */
final class ConfidentialEntries
{
    /** Models whose agent_id says whose they are. */
    private const OWNED_BY_AGENT = [
        Approval::class, AgentRun::class, AgentRoutine::class, AgentMemory::class, AgentQuestion::class,
        AgentInitiative::class, Report::class, Briefing::class, GeneratedDocument::class, FollowUp::class,
    ];

    /** Details that name an agent. */
    private const AGENT_KEYS = ['agent_id', 'to_agent_id', 'assignee_agent_id', 'proposed_by_agent_id'];

    /** Details that name a task. */
    private const TASK_KEYS = ['task_id', 'from_task_id', 'to_task_id'];

    /** Actions whose details name the ERP tool called. */
    private const TOOL_ACTIONS = ['erp.tool_call'];

    /**
     * @param  list<int>  $agentIds  the confidential agents
     * @param  list<string>  $keys  people-data capability keys
     * @param  list<string>  $tools  the ERP tools behind them
     */
    private function __construct(
        private readonly array $agentIds,
        private readonly array $keys,
        private readonly array $tools,
    ) {}

    /**
     * Read every time, as PeopleData does: an admin may give or take a
     * capability at any moment.
     */
    public static function forCurrentTenant(): self
    {
        $capabilities = Capability::query()->where('key', 'like', 'erp.%')->get(['key', 'mcp_tool_name'])
            ->filter(fn (Capability $capability) => PeopleData::isPeopleData($capability->key));

        // The contract's keys by name, and the actions already recorded under a key of the list.
        $literal = array_filter((array) config('autonomy.people_data', []), fn ($key) => is_string($key) && ! str_contains($key, '*'));
        $recorded = AuditLog::query()->tap(fn (Builder $q) => AuditFilters::whereStartsWith($q, 'action', 'erp.'))
            ->distinct()->pluck('action')->filter(fn ($action) => PeopleData::isPeopleData((string) $action));

        $keys = collect($literal)->merge($capabilities->pluck('key'))->merge($recorded)
            ->filter(fn ($key) => PeopleData::isPeopleData((string) $key))->map(fn ($key) => (string) $key)->unique()->values();

        $tools = $keys->map(fn (string $key) => Str::after($key, 'erp.'))
            ->merge($capabilities->pluck('mcp_tool_name')->filter()->map(fn ($tool) => (string) $tool))
            ->unique()->values();

        return new self(PeopleData::confidentialAgentIds(), $keys->all(), $tools->all());
    }

    public static function readBy(?User $user): bool
    {
        return PeopleData::mayRead($user);
    }

    /** Nothing in this tenant is confidential: no agent holds the capabilities and none was ever called. */
    public function isEmpty(): bool
    {
        return $this->agentIds === [] && $this->keys === [];
    }

    /**
     * The confidential entries among the ones given.
     *
     * @param  list<int>  $ids
     * @return array<int, true>
     */
    public function among(array $ids): array
    {
        if ($ids === [] || $this->isEmpty()) {
            return [];
        }

        return array_fill_keys($this->scope(AuditLog::query()->whereKey($ids))->pluck('id')->map(fn ($id) => (int) $id)->all(), true);
    }

    /**
     * Narrow the query to the confidential entries.
     *
     * @param  Builder<AuditLog>  $query
     * @return Builder<AuditLog>
     */
    public function scope(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            if ($this->isEmpty()) {
                $q->whereRaw('1 = 0');

                return;
            }

            if ($this->keys !== []) {
                $q->orWhereIn('action', $this->keys)
                    ->orWhere(fn (Builder $w) => $w->whereIn('action', self::TOOL_ACTIONS)->whereIn('payload->tool', $this->tools))
                    // A person's decision on an approval says which action it was.
                    ->orWhere(fn (Builder $w) => $w->tap(fn (Builder $a) => AuditFilters::whereStartsWith($a, 'action', 'approval.'))->whereIn('payload->action', $this->keys));
            }

            if ($this->agentIds === []) {
                return;
            }

            $agents = $this->agentIds;
            $tasks = PeopleData::tasksOf($agents);

            // Done by the agent.
            $q->orWhere(fn (Builder $w) => $w->where('actor_type', ActorType::Agent)->whereIn('actor_id', $agents));

            // About the agent, or about something that is the agent's.
            $q->orWhere(fn (Builder $w) => $w->where('subject_type', Relation::getMorphAlias(Agent::class))->whereIn('subject_id', $agents));
            $q->orWhere(fn (Builder $w) => $w->where('subject_type', Relation::getMorphAlias(Task::class))->whereIn('subject_id', $tasks));
            $q->orWhere(fn (Builder $w) => $w->where('subject_type', Relation::getMorphAlias(AgentHandoff::class))->whereIn('subject_id', AgentHandoff::query()->where(fn (Builder $h) => $h->whereIn('from_agent_id', $agents)->orWhereIn('to_agent_id', $agents))->select('id')));

            foreach (self::OWNED_BY_AGENT as $class) {
                $q->orWhere(fn (Builder $w) => $w->where('subject_type', Relation::getMorphAlias($class))->whereIn('subject_id', $class::query()->whereIn('agent_id', $agents)->select('id')));
            }

            // Details that point at the agent, its runs, its approvals or its tasks.
            foreach (self::AGENT_KEYS as $key) {
                $q->orWhereIn("payload->{$key}", $agents);
            }

            $q->orWhereIn('payload->agent_run_id', AgentRun::query()->whereIn('agent_id', $agents)->select('id'));
            $q->orWhereIn('payload->approval_id', Approval::query()->whereIn('agent_id', $agents)->select('id'));

            foreach (self::TASK_KEYS as $key) {
                $q->orWhereIn("payload->{$key}", PeopleData::tasksOf($agents));
            }
        });
    }
}
