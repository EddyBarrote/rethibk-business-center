<?php

namespace App\Http\Controllers;

use App\Ai\Agents\AgentDrafting;
use App\Ai\Budget\BudgetExceeded;
use App\Ai\Capabilities\CapabilityRegistry;
use App\Ai\Runs\MissingProviderKey;
use App\Enums\AgentStatus;
use App\Enums\EmailCategory;
use App\Enums\Permission;
use App\Enums\WorkflowRunStatus;
use App\Enums\WorkflowStatus;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\Capability;
use App\Models\Skill;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Tenancy\TenantRule;
use App\Workflows\EmailRouter;
use App\Workflows\WorkflowDrafting;
use App\Workflows\WorkflowEngine;
use App\Workflows\WorkflowGraph;
use App\Workflows\WorkflowReadiness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Fluxos de trabalho (docs/DECISOES.md): the map of what happens to each kind
 * of email after triage, and the editor where a flow is drawn as a canvas or
 * written as a list. Both edit the same graph (App\Workflows\WorkflowGraph).
 */
class WorkflowController extends Controller
{
    public function __construct(
        private readonly WorkflowReadiness $readiness,
        private readonly EmailRouter $router,
    ) {}

    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $workflows = Workflow::query()->with(['agent', 'fallbackUser:id,name'])->orderBy('name')->get();
        $since = now()->subDays(30);
        $counts = WorkflowRun::query()->where('created_at', '>=', $since)
            ->selectRaw('workflow_id, count(*) as total, sum(case when status = ? then 1 else 0 end) as completed', [WorkflowRunStatus::Completed->value])
            ->groupBy('workflow_id')->get()->keyBy('workflow_id');
        $recent = WorkflowRun::query()->with('task:id,number,tenant_id,title')->latest('id')->limit(200)->get()->groupBy('workflow_id');

        return Inertia::render('Workflows/Index', [
            'categories' => collect(EmailCategory::cases())->map(function (EmailCategory $category) {
                $route = $this->router->route($category);

                return [
                    'value' => $category->value,
                    'label' => $category->label(),
                    'source' => $route['source'],
                    'agent' => $route['agent'] ? ['id' => $route['agent']->id, 'name' => $route['agent']->name] : null,
                    'workflow_id' => $route['workflow']?->id,
                    'fallback' => $route['fallback']?->name,
                ];
            })->values(),
            'workflows' => $workflows->map(fn (Workflow $workflow) => [
                ...$this->present($workflow),
                'runs_30d' => ['total' => (int) ($counts[$workflow->id]->total ?? 0), 'completed' => (int) ($counts[$workflow->id]->completed ?? 0)],
                'recent_runs' => ($recent[$workflow->id] ?? collect())->take(6)->map(fn (WorkflowRun $run) => [
                    'id' => $run->id,
                    'status' => $run->status->value,
                    'status_label' => $run->status->label(),
                    'node' => $run->current_node_id ? (new WorkflowGraph($run->graph))->label($run->current_node_id) : null,
                    'task' => $run->task ? ['id' => $run->task->id, 'identifier' => $run->task->identifier(), 'title' => $run->task->title] : null,
                    'started_at' => $run->started_at?->toIso8601String(),
                ])->values(),
                'can_edit' => $user->can('update', $workflow),
            ])->values(),
            'agents' => $this->agents(),
            'can_create' => $user->can('create', Workflow::class),
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', Workflow::class);

        $category = EmailCategory::tryFrom((string) $request->query('category'));

        return $this->editor(null, $category);
    }

    public function edit(Workflow $workflow): Response
    {
        Gate::authorize('update', $workflow);

        return $this->editor($workflow);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Workflow::class);

        $user = $this->user($request);
        $data = $this->validated($request);
        $workflow = Workflow::query()->create([...$data, 'status' => WorkflowStatus::Draft, 'created_by_user_id' => $user->id, 'updated_by_user_id' => $user->id]);
        AuditLog::record($user, 'workflow.created', ['workflow_id' => $workflow->id], subject: $workflow);

        $message = $request->boolean('activate') ? $this->activate($workflow, $user) : 'Fluxo guardado como rascunho.';

        return to_route('workflows.edit', $workflow)->with('success', $message);
    }

    public function update(Request $request, Workflow $workflow): RedirectResponse
    {
        Gate::authorize('update', $workflow);

        $user = $this->user($request);
        $workflow->fill([...$this->validated($request), 'updated_by_user_id' => $user->id])->save();
        AuditLog::record($user, 'workflow.updated', ['workflow_id' => $workflow->id], subject: $workflow);

        $message = match (true) {
            $request->boolean('activate') => $this->activate($workflow, $user),
            $workflow->status === WorkflowStatus::Active => $this->activeProblems($workflow) ?? 'Fluxo guardado. Os emails que já estão a meio seguem o desenho anterior.',
            default => 'Fluxo guardado.',
        };

        return back()->with(str_starts_with($message, 'Não') ? 'error' : 'success', $message);
    }

    public function status(Request $request, Workflow $workflow): RedirectResponse
    {
        Gate::authorize('update', $workflow);

        $user = $this->user($request);
        $data = $request->validate(['status' => ['required', Rule::enum(WorkflowStatus::class)]]);

        if ($data['status'] === WorkflowStatus::Active->value) {
            $message = $this->activate($workflow, $user);

            return back()->with(str_starts_with($message, 'Não') ? 'error' : 'success', $message);
        }

        $workflow->forceFill(['status' => $data['status'], 'updated_by_user_id' => $user->id])->save();
        AuditLog::record($user, 'workflow.status', ['workflow_id' => $workflow->id, 'status' => $data['status']], subject: $workflow);

        return back()->with('success', 'Fluxo pausado. Os emails deste tipo voltam à regra de Definições.');
    }

    public function destroy(Request $request, Workflow $workflow): RedirectResponse
    {
        Gate::authorize('delete', $workflow);

        if ($workflow->runs()->exists()) {
            return back()->with('error', 'Este fluxo já tratou emails: pause-o em vez de o apagar, para manter o histórico.');
        }

        AuditLog::record($this->user($request), 'workflow.deleted', ['workflow_id' => $workflow->id, 'name' => $workflow->name], subject: $workflow);
        $workflow->delete();

        return to_route('workflows.index')->with('success', 'Fluxo apagado.');
    }

    /**
     * What each block will do with what the agents have, for the editor while it is drawn.
     */
    public function check(Request $request): JsonResponse
    {
        Gate::authorize('create', Workflow::class);

        $data = $request->validate([
            'agent_id' => ['nullable', 'integer', TenantRule::exists('agents')],
            'graph' => ['required', 'array'],
            'graph.nodes' => ['present', 'array', 'max:200'],
            'graph.edges' => ['present', 'array', 'max:400'],
        ]);

        $graph = new WorkflowGraph($data['graph']);
        $readiness = $this->readiness->check($graph, isset($data['agent_id']) ? Agent::query()->find($data['agent_id']) : null);

        return response()->json([
            'readiness' => $readiness,
            'summary' => WorkflowReadiness::summary($readiness),
            'problems' => $graph->problems(),
        ]);
    }

    /**
     * "Propor passos": the assistant drafts the blocks from a description, with the agent's capabilities.
     */
    public function draft(Request $request, WorkflowDrafting $drafting): JsonResponse
    {
        Gate::authorize('create', Workflow::class);

        $data = $request->validate([
            'description' => ['required', 'string', 'min:15', 'max:4000'],
            'agent_id' => ['required', 'integer', TenantRule::exists('agents')],
            'email_category' => ['nullable', Rule::enum(EmailCategory::class)],
        ]);

        try {
            $graph = $drafting->draft($data['description'], Agent::query()->findOrFail($data['agent_id']), EmailCategory::tryFrom((string) ($data['email_category'] ?? '')));
        } catch (MissingProviderKey|BudgetExceeded $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'O assistente não conseguiu propor os passos: '.Str::limit($e->getMessage(), 200)], 422);
        }

        return response()->json(['graph' => $graph]);
    }

    /**
     * A person decides a step of a flow given to them: approve or reject, yes or no, or done.
     */
    public function decide(Request $request, WorkflowStep $step, WorkflowEngine $engine): RedirectResponse
    {
        $user = $this->user($request);
        $task = $step->task;

        abort_unless($task !== null && ($task->assignee_user_id === $user->id || $user->hasPermission(Permission::DecideAllApprovals)), 403);

        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected', 'yes', 'no', 'done'])],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        if (! $step->status->isOpen()) {
            return back()->with('error', 'Este passo já foi decidido.');
        }

        $engine->decide($step, $data['decision'], $user, $data['note'] ?? null);

        return back()->with('success', match ($data['decision']) {
            'approved' => 'Aprovado. O fluxo continua.',
            'rejected' => 'Rejeitado. O fluxo segue pelo caminho da rejeição.',
            'yes', 'no' => 'Resposta registada. O fluxo continua.',
            default => 'Feito. O fluxo continua.',
        });
    }

    private function editor(?Workflow $workflow, ?EmailCategory $category = null): Response
    {
        $agent = $workflow?->agent;
        $graph = $workflow !== null ? $workflow->graph : $this->starterGraph($category);
        $readiness = $this->readiness->check(new WorkflowGraph($graph), $agent);

        return Inertia::render('Workflows/Edit', [
            'workflow' => $workflow ? $this->present($workflow) : null,
            'initial' => [
                'name' => $workflow->name ?? ($category ? 'Responder a '.Str::lcfirst($category->label()) : ''),
                'description' => $workflow->description ?? '',
                'email_category' => $workflow?->email_category->value ?? $category?->value,
                'agent_id' => $workflow !== null ? $workflow->agent_id : ($category ? $this->router->route($category)['agent']?->id : null),
                'fallback_user_id' => $workflow?->fallback_user_id,
                'graph' => $graph,
            ],
            'readiness' => $readiness,
            'categories' => EmailCategory::options(),
            'agents' => $this->agents(),
            'people' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'capabilities' => Capability::query()->where('is_enabled', true)->whereNotIn('key', CapabilityRegistry::hidden())
                ->whereNotIn('key', CapabilityRegistry::alwaysOn())->orderBy('name')
                ->get(['key', 'name', 'description', 'is_mutating', 'risk', 'is_available'])
                ->map(fn (Capability $c) => ['key' => $c->key, 'name' => $c->name, 'description' => $c->description, 'is_mutating' => $c->is_mutating, 'risk' => $c->risk->code(), 'available' => $c->is_available]),
            'skills' => Skill::query()->with('platformSkill')->get()->filter(fn (Skill $skill) => $skill->isUsable())
                ->map(fn (Skill $skill) => ['key' => $skill->key, 'name' => $skill->displayName()])->values(),
            'can_draft' => AgentDrafting::available(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Workflow $workflow): array
    {
        $graph = new WorkflowGraph($workflow->graph);
        $readiness = $this->readiness->check($graph, $workflow->agent);

        return [
            'id' => $workflow->id,
            'name' => $workflow->name,
            'description' => $workflow->description,
            'status' => $workflow->status->value,
            'status_label' => $workflow->status->label(),
            'email_category' => $workflow->email_category?->value,
            'email_category_label' => $workflow->email_category?->label(),
            'agent' => $workflow->agent ? [
                'id' => $workflow->agent->id,
                'name' => $workflow->agent->name,
                'level' => $workflow->agent->autonomy_level->code(),
                'level_label' => $workflow->agent->autonomy_level->label(),
            ] : null,
            'fallback' => $workflow->fallbackUser?->name,
            'graph' => $workflow->graph,
            'readiness' => $readiness,
            'summary' => WorkflowReadiness::summary($readiness),
            'problems' => $graph->problems(),
            'updated_at' => $workflow->updated_at->toIso8601String(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function agents(): array
    {
        $agents = Agent::query()->with(['capabilities' => fn ($q) => $q->wherePivot('enabled', true)])->where('status', '!=', AgentStatus::Draft)->orderBy('name')->get();

        return $agents->map(fn (Agent $agent) => [
            'id' => $agent->id,
            'name' => $agent->name,
            'title' => $agent->title,
            'active' => $agent->isActive(),
            'level' => $agent->autonomy_level->code(),
            'level_label' => $agent->autonomy_level->label(),
            'capabilities' => $agent->capabilities->pluck('key')->values(),
        ])->values()->all();
    }

    /**
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    private function starterGraph(?EmailCategory $category): array
    {
        return [
            'nodes' => [
                ['id' => 'trigger', 'type' => WorkflowGraph::TRIGGER, 'position' => ['x' => 200, 'y' => 0], 'data' => ['label' => $category?->label() ?? 'Email depois da triagem']],
                ['id' => 'end', 'type' => WorkflowGraph::END, 'position' => ['x' => 200, 'y' => 140], 'data' => ['label' => 'Fim']],
            ],
            'edges' => [['id' => 'trigger-end', 'source' => 'trigger', 'target' => 'end']],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:4000'],
            'email_category' => ['required', Rule::enum(EmailCategory::class)],
            'agent_id' => ['required', 'integer', TenantRule::exists('agents')],
            'fallback_user_id' => ['nullable', 'integer', TenantRule::exists('users')],
            'graph' => ['required', 'array'],
            'graph.nodes' => ['required', 'array', 'min:1', 'max:200'],
            'graph.nodes.*.id' => ['required', 'string', 'max:64'],
            'graph.nodes.*.type' => ['required', Rule::in(WorkflowGraph::TYPES)],
            'graph.nodes.*.data' => ['nullable', 'array'],
            'graph.nodes.*.data.label' => ['nullable', 'string', 'max:200'],
            'graph.nodes.*.data.instruction' => ['nullable', 'string', 'max:4000'],
            'graph.edges' => ['present', 'array', 'max:400'],
            'graph.edges.*.source' => ['required', 'string', 'max:64'],
            'graph.edges.*.target' => ['required', 'string', 'max:64'],
            'graph.edges.*.sourceHandle' => ['nullable', 'string', 'max:20'],
        ]);

        $agent = Agent::query()->findOrFail($data['agent_id']);
        abort_unless($this->user($request)->can('useAgent', [Workflow::class, $agent]), 403, 'Só pode dar fluxos aos agentes a que dá acesso.');

        // validate() keeps only the keys it names; the graph is cleaned from the whole input instead.
        $graph = $this->cleanGraph((array) $request->input('graph'));

        foreach ($graph['nodes'] as $node) {
            $handoff = $node['data']['agent_id'] ?? null;
            $person = $node['data']['user_id'] ?? null;

            if ($handoff !== null && ! Agent::query()->whereKey($handoff)->exists()) {
                throw ValidationException::withMessages(['graph' => 'Um bloco passa a um agente que não existe.']);
            }

            if ($person !== null && ! User::query()->whereKey($person)->exists()) {
                throw ValidationException::withMessages(['graph' => 'Um bloco é decidido por uma pessoa que não existe.']);
            }
        }

        return [...$data, 'graph' => $graph];
    }

    /**
     * Only what the flow needs from the canvas: ids, types, positions, sizes, parents and settings.
     *
     * @param  array<string, mixed>  $graph
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    private function cleanGraph(array $graph): array
    {
        $nodes = array_map(fn (array $node) => array_filter([
            'id' => (string) $node['id'],
            'type' => (string) $node['type'],
            'position' => ['x' => round((float) ($node['position']['x'] ?? 0)), 'y' => round((float) ($node['position']['y'] ?? 0))],
            'parentId' => isset($node['parentId']) ? (string) $node['parentId'] : null,
            'width' => isset($node['width']) ? round((float) $node['width']) : null,
            'height' => isset($node['height']) ? round((float) $node['height']) : null,
            'data' => $this->cleanData((array) ($node['data'] ?? [])),
        ], fn ($value) => $value !== null), array_values($graph['nodes']));

        $edges = array_map(fn (array $edge) => array_filter([
            'id' => (string) ($edge['id'] ?? "{$edge['source']}-{$edge['target']}"),
            'source' => (string) $edge['source'],
            'target' => (string) $edge['target'],
            'sourceHandle' => isset($edge['sourceHandle']) && $edge['sourceHandle'] !== '' ? (string) $edge['sourceHandle'] : null,
        ], fn ($value) => $value !== null), array_values($graph['edges'] ?? []));

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    /**
     * A block's settings, each with its type and length.
     *
     * @param  array<mixed>  $data
     * @return array<string, string|int>
     */
    private function cleanData(array $data): array
    {
        $texts = ['label' => 200, 'instruction' => 4000, 'capability' => 255, 'skill' => 255, 'items' => 1000, 'until' => 500];
        $numbers = ['agent_id' => [1, PHP_INT_MAX], 'user_id' => [1, PHP_INT_MAX], 'max' => [1, WorkflowGraph::MAX_ITERATIONS], 'hours' => [1, 720]];
        $clean = [];

        foreach ($texts as $key => $limit) {
            if (is_scalar($data[$key] ?? null) && trim((string) $data[$key]) !== '') {
                $clean[$key] = Str::limit(trim((string) $data[$key]), $limit, '');
            }
        }

        foreach ($numbers as $key => [$min, $max]) {
            if (is_numeric($data[$key] ?? null)) {
                $clean[$key] = max($min, min($max, (int) $data[$key]));
            }
        }

        return $clean;
    }

    /**
     * Turn a flow on, if it is complete; another active flow for the same kind of email is paused.
     */
    private function activate(Workflow $workflow, User $user): string
    {
        $problems = $this->activeProblems($workflow);

        if ($problems !== null) {
            return $problems;
        }

        $paused = DB::transaction(function () use ($workflow, $user) {
            $others = Workflow::query()->whereKeyNot($workflow->id)->where('email_category', $workflow->email_category)->where('status', WorkflowStatus::Active)->get();
            $others->each(fn (Workflow $other) => $other->forceFill(['status' => WorkflowStatus::Paused])->save());
            $workflow->forceFill(['status' => WorkflowStatus::Active, 'updated_by_user_id' => $user->id])->save();

            return $others->pluck('name')->all();
        });

        AuditLog::record($user, 'workflow.status', ['workflow_id' => $workflow->id, 'status' => 'active', 'paused' => $paused], subject: $workflow);

        return "Fluxo activo: os emails «{$workflow->email_category?->label()}» seguem-no a partir de agora."
            .($paused !== [] ? ' Pausei «'.implode('», «', $paused).'», que tratava o mesmo tipo de email.' : '');
    }

    private function activeProblems(Workflow $workflow): ?string
    {
        $problems = (new WorkflowGraph($workflow->graph))->problems();

        if ($workflow->agent === null || ! $workflow->agent->isActive()) {
            $problems[] = 'o agente do fluxo não está activo.';
        }

        if ($problems !== []) {
            if ($workflow->status === WorkflowStatus::Active) {
                $workflow->forceFill(['status' => WorkflowStatus::Paused])->save();

                return 'Não ficou activo: '.$problems[0].' O fluxo foi pausado até ser corrigido.';
            }

            return 'Não foi activado: '.$problems[0];
        }

        return null;
    }
}
