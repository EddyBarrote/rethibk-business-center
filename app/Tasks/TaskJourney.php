<?php

namespace App\Tasks;

use App\Enums\ActorType;
use App\Enums\ApprovalStatus;
use App\Enums\TaskStatus;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\EmailMessage;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The path of one case across agents and people (docs/DECISOES.md, "Percurso"):
 * from the email or request that started it, every hand-off with how it was
 * decided (a flow, the agent itself, a person) and why, the time each one
 * took, the approvals and questions waiting on people, and who has it now.
 *
 * Built from what is already stored: the task tree (a hand-off is always a
 * sub-task), the flow steps, the approvals and the agent runs.
 */
final class TaskJourney
{
    /** The tree is small (delegation stops at TaskThread::MAX_DEPTH); this guards a broken parent chain. */
    private const MAX_TASKS = 60;

    /** @var Collection<int, Task> */
    private Collection $tasks;

    /** @var Collection<int, AgentRun> */
    private Collection $runs;

    /** @var Collection<int, Approval> */
    private Collection $approvals;

    /** @var array<string, array{key: string, type: string, name: string, agent_id: int|null, avatar_url: string|null, first_at: Carbon}> */
    private array $actors = [];

    private Carbon $now;

    /**
     * @param  callable(Task): bool  $canView  whether the viewer may open a task of the tree
     * @return array<string, mixed>
     */
    public function for(Task $task, callable $canView): array
    {
        $this->now = Carbon::now();
        $root = $this->root($task);
        $this->tasks = $this->tree($root);
        $root = $this->tasks->get($root->id);
        $ids = $this->tasks->pluck('id');

        $this->runs = AgentRun::query()->whereIn('task_id', $ids)->get(['id', 'task_id', 'agent_id', 'cost_usd', 'duration_ms', 'started_at', 'finished_at', 'created_at']);
        $this->approvals = Approval::query()->with(['agent', 'assignedTo:id,name', 'decidedBy:id,name', 'decidedByAgent', 'reviewAgent'])
            ->whereIn('agent_run_id', $this->runs->pluck('id'))->orderBy('id')->get();
        $steps = WorkflowStep::query()->with('run.workflow:id,name')->whereIn('task_id', $ids)->get()->keyBy('task_id');
        $flow = WorkflowRun::query()->with('workflow:id,name')->where('task_id', $root->id)->latest('id')->first();

        $hops = [];
        $email = $root->source instanceof EmailMessage ? $root->source : null;
        $start = $email !== null && $email->received_at !== null ? $email->received_at : $root->created_at;

        foreach ($this->tasks->sortBy([['created_at', 'asc'], ['id', 'asc']]) as $node) {
            $visible = $canView($node);
            $hops = [...$hops, ...$this->taskHops($node, $root, $email, $steps->get($node->id), $flow, $visible)];
        }

        foreach ($this->approvals as $approval) {
            $hops[] = $this->approvalHop($approval);
        }

        usort($hops, fn (array $a, array $b) => [$a['at'], $a['order']] <=> [$b['at'], $b['order']]);

        $open = $this->tasks->contains(fn (Task $t) => ! $t->status->isClosed()) || $this->approvals->contains(fn (Approval $a) => $a->status === ApprovalStatus::Pending);
        $end = $open ? $this->now : Carbon::parse($this->tasks->max('completed_at') ?? $this->tasks->max('updated_at'));
        $total = max(0, (int) $start->diffInSeconds($end, true));
        $agentSeconds = (int) round($this->runs->sum(fn (AgentRun $run) => $this->runSeconds($run)));
        $waitingSeconds = $this->waitingOnPeople();

        return [
            'root' => ['id' => $root->id, 'ref' => $root->identifier(), 'title' => $root->title, 'is_current' => $root->id === $task->id],
            'now' => $this->holder($root),
            'stops' => $this->stops($hops),
            'hops' => array_map(fn (array $hop) => [...$hop, 'at' => $hop['at']->toIso8601String(), 'is_current' => ($hop['task']['id'] ?? null) === $task->id], $hops),
            'totals' => [
                'seconds' => $total,
                'agent_seconds' => min($agentSeconds, $total),
                'waiting_seconds' => min($waitingSeconds, max(0, $total - $agentSeconds)),
                'other_seconds' => max(0, $total - $agentSeconds - $waitingSeconds),
                'cost_usd' => round((float) $this->runs->sum('cost_usd'), 4),
                'agents' => collect($this->actors)->where('type', 'agent')->count(),
                'people' => collect($this->actors)->where('type', 'person')->count(),
                'handoffs' => collect($hops)->where('type', 'handoff')->count(),
                'by_agents' => collect($hops)->where('type', 'handoff')->where('how', 'agent')->count(),
                'workflow' => $flow?->workflow?->name,
                'open' => $open,
            ],
        ];
    }

    /**
     * Whether there is a path to show: more than one task, an email, or an approval.
     */
    public static function worthShowing(Task $task): bool
    {
        return $task->parent_id !== null || $task->source_type !== null || $task->children()->exists()
            || Approval::query()->whereIn('agent_run_id', $task->runs()->select('id'))->exists();
    }

    private function root(Task $task): Task
    {
        $root = $task;

        for ($guard = 0; $root->parent_id !== null && $guard < 10; $guard++) {
            $parent = Task::query()->find($root->parent_id);

            if ($parent === null) {
                break;
            }

            $root = $parent;
        }

        return $root;
    }

    /**
     * @return Collection<int, Task>
     */
    private function tree(Task $root): Collection
    {
        $relations = ['assigneeAgent', 'assigneeUser:id,name', 'user:id,name', 'createdByAgent', 'createdByUser:id,name', 'tenant:id,slug'];
        $all = collect([Task::query()->with([...$relations, 'source'])->findOrFail($root->id)]);
        $frontier = [$root->id];

        while ($frontier !== [] && $all->count() < self::MAX_TASKS) {
            $next = Task::query()->with($relations)->whereIn('parent_id', $frontier)->orderBy('id')->limit(self::MAX_TASKS)->get();
            $all = $all->merge($next);
            $frontier = $next->pluck('id')->all();
        }

        return $all->keyBy('id');
    }

    /**
     * The hand-off that opened a task, the time its owner worked on it, the
     * question it is waiting on and, when it closed, the return to the parent.
     *
     * @return list<array<string, mixed>>
     */
    private function taskHops(Task $task, Task $root, ?EmailMessage $email, ?WorkflowStep $step, ?WorkflowRun $flow, bool $visible): array
    {
        $hops = [];
        $owner = $this->owner($task);
        $ownerKey = $owner ? $this->actor($owner, $task->created_at) : null;
        $ref = ['id' => $visible ? $task->id : null, 'ref' => $task->identifier()];

        if ($task->id === $root->id && $email !== null) {
            $triage = $task->createdByAgent;
            $category = $email->classification?->label();
            $hops[] = $this->hop($email->received_at ?? $task->created_at, 'email', 'email', $triage ? $this->actor($triage, $email->received_at ?? $task->created_at) : null, 'triage', 'Triagem',
                $category ? "Classificou como «{$category}»." : 'Email recebido.',
                quote: false,
                chips: array_values(array_filter([$email->from_address ? "De {$email->from_address}" : null, $email->subject ? Str::limit($email->subject, 80) : null])),
                seconds: (int) ($email->received_at ?? $task->created_at)->diffInSeconds($task->created_at, true),
                order: 0,
            );
        }

        $from = match (true) {
            $task->createdByAgent !== null => $this->actor($task->createdByAgent, $task->created_at),
            $task->createdByUser !== null => $this->actor($task->createdByUser, $task->created_at),
            default => null,
        };

        [$how, $howLabel] = match (true) {
            $step !== null => ['flow', 'Fluxo «'.($step->run->workflow->name ?? 'Fluxo').'»'],
            $task->id === $root->id && $email !== null && $flow !== null => ['flow', 'Fluxo «'.($flow->workflow->name ?? 'Fluxo').'»'],
            $task->id === $root->id && $email !== null => ['triage', 'Tipo de email'],
            $task->createdByAgent !== null && $task->createdByAgent->id === $task->assignee_agent_id => ['self', 'O agente abriu para si'],
            $task->createdByAgent !== null => ['agent', 'Decisão do agente'],
            default => ['person', 'Pedido de uma pessoa'],
        };

        $agentRuns = $this->runs->where('task_id', $task->id);
        $chips = [];

        if ($agentRuns->isNotEmpty()) {
            $chips[] = 'a trabalhar '.$this->words((int) round($agentRuns->sum(fn (AgentRun $run) => $this->runSeconds($run))));
            $cost = (float) $agentRuns->sum('cost_usd');
            $chips[] = $agentRuns->count().' '.($agentRuns->count() === 1 ? 'execução' : 'execuções').($cost > 0 ? ' · '.number_format($cost, 2, ',', ' ').' US$' : '');
        }

        if ($task->parent_id !== null && ! $task->status->isClosed() && $how !== 'flow') {
            $parentOwner = $this->owner($this->tasks->get($task->parent_id));
            $chips[] = $parentOwner ? "{$parentOwner->name} espera o resultado" : 'A tarefa-mãe espera o resultado';
        }

        // The owner's own time: until it handed the work on (a sub-task or an approval), closed it, or now.
        $handedAt = collect([
            $this->tasks->where('parent_id', $task->id)->min('created_at'),
            $this->approvals->whereIn('agent_run_id', $agentRuns->pluck('id'))->min('created_at'),
        ])->filter()->map(fn ($at) => Carbon::parse($at))->min();
        $until = $handedAt ?? $task->completed_at ?? $this->now;

        $hops[] = $this->hop($task->created_at, $how === 'self' ? 'work' : 'handoff', $from, $ownerKey, $how, $howLabel,
            $visible ? $this->reason($task, $how) : 'Sem acesso a esta tarefa.',
            quote: $visible && in_array($how, ['agent', 'person'], true),
            chips: $chips,
            seconds: (int) $task->created_at->diffInSeconds($until, true),
            counting: $handedAt === null && ! $task->status->isClosed(),
            task: $ref,
            status: ['value' => $task->status->value, 'label' => $task->status->label(), 'open' => ! $task->status->isClosed()],
            order: 1,
        );

        if ($task->status === TaskStatus::WaitingHuman) {
            $person = $task->assigneeUser ?? $task->user;
            $question = $task->messages()->where('author_type', ActorType::Agent)->reorder('id', 'desc')->first();
            $since = $question->created_at ?? $task->last_activity_at ?? $task->updated_at;

            if ($person !== null) {
                $hops[] = $this->hop($since, 'question', $ownerKey, $this->actor($person, $since), 'question', 'Pergunta a uma pessoa',
                    $visible && $question ? Str::limit(trim($question->body), 260) : 'À espera de uma resposta.',
                    quote: $visible && $question !== null,
                    seconds: (int) $since->diffInSeconds($this->now, true),
                    counting: true,
                    task: $ref,
                    status: ['value' => 'waiting', 'label' => 'À espera', 'open' => true],
                    order: 3,
                );
            }
        }

        if ($task->parent_id !== null && $task->completed_at !== null && $how !== 'flow') {
            $parentOwner = $this->owner($this->tasks->get($task->parent_id));
            $hops[] = $this->hop($task->completed_at, 'returned', $ownerKey, $parentOwner ? $this->actor($parentOwner, $task->completed_at) : null, 'return', 'Resultado entregue',
                "{$task->identifier()} {$task->status->label()}: o resultado volta a quem a pediu.",
                task: $ref,
                order: 4,
            );
        }

        return $hops;
    }

    /**
     * An action the autonomy gate held back: the Chief of Staff revalidates it, or it waits on a person.
     *
     * @return array<string, mixed>
     */
    private function approvalHop(Approval $approval): array
    {
        $agent = $approval->agent;
        $byAgent = $approval->review_stage === Approval::STAGE_AGENT && $approval->status === ApprovalStatus::Pending;
        $to = match (true) {
            $byAgent && $approval->reviewAgent !== null => $this->actor($approval->reviewAgent, $approval->created_at),
            $approval->decidedByAgent !== null && $approval->decidedBy === null => $this->actor($approval->decidedByAgent, $approval->created_at),
            $approval->decidedBy !== null => $this->actor($approval->decidedBy, $approval->created_at),
            $approval->assignedTo !== null => $this->actor($approval->assignedTo, $approval->created_at),
            default => $this->actor('Aprovações', $approval->created_at),
        };

        $chips = array_values(array_filter([
            $approval->ceiling_reason ? 'Tecto: '.Str::limit($approval->ceiling_reason, 120) : null,
            $approval->decision_note ? 'Nota: '.Str::limit($approval->decision_note, 120) : null,
        ]));

        $run = $this->runs->firstWhere('id', $approval->agent_run_id);
        $task = $run ? $this->tasks->get($run->task_id) : null;

        return $this->hop($approval->created_at, 'approval', $agent ? $this->actor($agent, $approval->created_at) : null, $to,
            $approval->ceiling_reason ? 'ceiling' : 'level',
            $approval->ceiling_reason ? 'Tecto absoluto' : ($byAgent ? 'Revalida o Chief of Staff' : 'Aprovação'),
            $approval->action_summary,
            chips: $chips,
            seconds: (int) $approval->created_at->diffInSeconds($approval->decided_at ?? $this->now, true),
            counting: $approval->status === ApprovalStatus::Pending,
            task: $task ? ['id' => $task->id, 'ref' => $task->identifier()] : null,
            status: ['value' => $approval->status->value, 'label' => $approval->status->label(), 'open' => $approval->status === ApprovalStatus::Pending],
            order: 2,
            approvalId: $approval->id,
        );
    }

    /**
     * Who has the case now: a person deciding, a person answering, the agent on the deepest open task.
     *
     * @return array<string, mixed>
     */
    private function holder(Task $root): array
    {
        $pending = $this->approvals->where('status', ApprovalStatus::Pending)->sortBy('id')->first();

        if ($pending !== null) {
            $agentStage = $pending->review_stage === Approval::STAGE_AGENT && $pending->reviewAgent !== null;
            $who = $agentStage ? $pending->reviewAgent : $pending->assignedTo;

            return [
                'type' => $agentStage ? 'agent' : 'person',
                'name' => $who->name ?? 'Quem decide aprovações',
                'avatar_url' => $who instanceof Agent ? $who->avatarUrl() : null,
                'doing' => ($agentStage ? 'Revalidar: ' : 'Aprovar: ').$pending->action_summary,
                'since' => $pending->created_at->toIso8601String(),
                'approval_id' => $pending->id,
                'task' => null,
            ];
        }

        $open = $this->tasks->reject(fn (Task $t) => $t->status->isClosed());
        $waiting = $open->firstWhere('status', TaskStatus::WaitingHuman);

        if ($waiting !== null && ($person = $waiting->assigneeUser ?? $waiting->user) !== null) {
            return ['type' => 'person', 'name' => $person->name, 'avatar_url' => null, 'doing' => "Responder à pergunta em {$waiting->identifier()}", 'since' => ($waiting->last_activity_at ?? $waiting->updated_at)->toIso8601String(), 'approval_id' => null, 'task' => ['id' => $waiting->id, 'ref' => $waiting->identifier()]];
        }

        // The newest open task is the deepest one being worked on; the others wait on it.
        $current = $open->sortBy([['created_at', 'desc'], ['id', 'desc']])->first();

        if ($current === null) {
            return ['type' => 'done', 'name' => null, 'avatar_url' => null, 'doing' => "{$root->identifier()} {$root->status->label()}", 'since' => ($root->completed_at ?? $root->updated_at)->toIso8601String(), 'approval_id' => null, 'task' => null];
        }

        $owner = $this->owner($current);

        return [
            'type' => $owner instanceof Agent ? 'agent' : 'person',
            'name' => $owner->name ?? 'Sem responsável',
            'avatar_url' => $owner instanceof Agent ? $owner->avatarUrl() : null,
            'doing' => $current->title,
            'since' => $current->created_at->toIso8601String(),
            'approval_id' => null,
            'task' => ['id' => $current->id, 'ref' => $current->identifier()],
        ];
    }

    /**
     * Everyone the case went through, in order, with the time spent with each.
     *
     * @param  list<array<string, mixed>>  $hops
     * @return list<array<string, mixed>>
     */
    private function stops(array $hops): array
    {
        $order = [];

        foreach ($hops as $hop) {
            foreach ([$hop['from'], $hop['to']] as $key) {
                if ($key !== null && ! in_array($key, $order, true)) {
                    $order[] = $key;
                }
            }
        }

        $stops = [];

        foreach ($order as $i => $key) {
            $actor = $this->actors[$key];
            // How the case reached this stop: the first hop that arrives here.
            $arrival = collect($hops)->first(fn (array $hop) => $hop['to'] === $key && $hop['from'] !== $key);
            // How long the case sat with them: the tasks they held until they passed them on, the
            // decisions and answers they owed. Triage holds the email until it opens the task.
            $seconds = (int) collect($hops)->where('to', $key)->whereIn('type', ['email', 'handoff', 'work', 'approval', 'question'])->sum('seconds');

            $stops[] = [
                'key' => $key,
                'type' => $actor['type'],
                'name' => $actor['name'],
                'avatar_url' => $actor['avatar_url'],
                'seconds' => $seconds,
                'at' => $actor['first_at']->toIso8601String(),
                'via' => $i === 0 ? null : ($arrival['how_label'] ?? null),
                'via_kind' => $i === 0 ? null : ($arrival['how'] ?? null),
            ];
        }

        return $stops;
    }

    private function waitingOnPeople(): int
    {
        $seconds = $this->approvals
            ->reject(fn (Approval $a) => $a->review_stage === Approval::STAGE_AGENT && $a->status === ApprovalStatus::Pending)
            ->sum(fn (Approval $a) => $a->created_at->diffInSeconds($a->decided_at ?? $this->now, true));

        $seconds += $this->tasks
            ->filter(fn (Task $t) => $t->assignee_user_id !== null && $t->assignee_agent_id === null)
            ->sum(fn (Task $t) => $t->created_at->diffInSeconds($t->completed_at ?? $this->now, true));

        return (int) round($seconds);
    }

    private function runSeconds(AgentRun $run): float
    {
        if ($run->duration_ms !== null) {
            return $run->duration_ms / 1000;
        }

        return $run->started_at ? $run->started_at->diffInSeconds($run->finished_at ?? $this->now, true) : 0;
    }

    private function owner(?Task $task): Agent|User|null
    {
        if ($task === null) {
            return null;
        }

        return $task->assigneeAgent ?? $task->assigneeUser;
    }

    private function actor(Agent|User|string $who, Carbon $at): string
    {
        $key = match (true) {
            $who instanceof Agent => "agent:{$who->id}",
            $who instanceof User => "user:{$who->id}",
            default => 'other:'.Str::slug($who),
        };

        $this->actors[$key] ??= [
            'key' => $key,
            'type' => $who instanceof Agent ? 'agent' : 'person',
            'name' => is_string($who) ? $who : $who->name,
            'agent_id' => $who instanceof Agent ? $who->id : null,
            'avatar_url' => $who instanceof Agent ? $who->avatarUrl() : null,
            'first_at' => $at,
        ];

        return $key;
    }

    /**
     * Why the task exists, in the words of whoever opened it. A flow step's
     * description is the platform's own preface, so its instruction is used.
     */
    private function reason(Task $task, string $how): string
    {
        $text = trim((string) ($task->description ?? ''));

        if ($how === 'flow' || $how === 'triage') {
            $text = Str::of($text)->after("\n\n")->before("\n\nQuando terminares")->trim()->value() ?: $task->title;
        }

        if ($how === 'triage') {
            return Str::limit($task->title, 200);
        }

        return Str::limit(preg_replace('/\s+/', ' ', $text ?: $task->title) ?? $task->title, 260);
    }

    /**
     * @param  list<string>  $chips
     * @param  array{id: int|null, ref: string}|null  $task
     * @param  array{value: string, label: string, open: bool}|null  $status
     * @return array<string, mixed>
     */
    private function hop(Carbon $at, string $type, ?string $from, ?string $to, string $how, string $howLabel, string $reason, bool $quote = false, array $chips = [], int $seconds = 0, ?array $task = null, ?array $status = null, int $order = 0, ?int $approvalId = null, bool $counting = false): array
    {
        if ($type === 'email') {
            $this->actors['email'] ??= ['key' => 'email', 'type' => 'email', 'name' => 'Email', 'agent_id' => null, 'avatar_url' => null, 'first_at' => $at];
            $from = 'email';
        }

        $name = fn (?string $key) => $key !== null ? ($this->actors[$key]['name'] ?? null) : null;

        return [
            'at' => $at,
            'order' => $order,
            'type' => $type,
            'from' => $from,
            'to' => $to,
            'from_name' => $name($from),
            'to_name' => $name($to),
            'to_type' => $to !== null ? ($this->actors[$to]['type'] ?? null) : null,
            'how' => $how,
            'how_label' => $howLabel,
            'reason' => $reason,
            'quote' => $quote,
            'chips' => $chips,
            'seconds' => $seconds,
            'task' => $task,
            'status' => $status,
            'approval_id' => $approvalId,
            'counting' => $counting,
        ];
    }

    private function words(int $seconds): string
    {
        return match (true) {
            $seconds < 60 => "{$seconds} s",
            $seconds < 3600 => intdiv($seconds, 60).' min',
            default => intdiv($seconds, 3600).' h '.str_pad((string) intdiv($seconds % 3600, 60), 2, '0', STR_PAD_LEFT),
        };
    }
}
