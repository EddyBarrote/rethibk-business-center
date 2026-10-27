<?php

namespace App\Tasks;

use App\Enums\CollaboratorRole;
use App\Enums\TaskKind;
use App\Enums\TaskStatus;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\Task;
use App\Models\TaskCollaborator;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Agents and colleagues working on someone's task (docs/DECISOES.md,
 * "Tarefas: colaborar com agentes e colegas"). Each one's part is a sub-task
 * of the task, assigned to them, with what their role asks at the top: an
 * agent starts on it at once, a colleague is told. When the part is done,
 * the sub-task reports to the task's thread (TaskThread::reportToParent).
 */
class TaskCollaboration
{
    public function __construct(private readonly TaskThread $threads) {}

    public function invite(Task $task, Agent|User $who, CollaboratorRole $role, User $by, ?string $note = null): TaskCollaborator
    {
        $description = collect([
            $role->brief(),
            filled($note) ? "Pedido de {$by->name}: {$note}" : null,
            "Tarefa principal: «{$task->title}» ({$task->identifier()}).",
            filled($task->description) ? Str::limit((string) $task->description, 3000) : null,
        ])->filter()->implode("\n\n");

        $subtask = $this->threads->open([
            'kind' => TaskKind::Task,
            'title' => Str::limit($role->subtaskTitle($task->title), 200, ''),
            'description' => $description,
            'status' => TaskStatus::Todo,
            'priority' => $task->priority,
            'assignee_agent_id' => $who instanceof Agent ? $who->id : null,
            'assignee_user_id' => $who instanceof User ? $who->id : null,
            'user_id' => $by->id,
            'parent_id' => $task->id,
            'goal_id' => $task->goal_id,
            'project_id' => $task->project_id,
            'due_at' => $task->due_at,
        ], $by);

        $collaborator = $task->collaborators()->create([
            'agent_id' => $who instanceof Agent ? $who->id : null,
            'user_id' => $who instanceof User ? $who->id : null,
            'role' => $role,
            'subtask_id' => $subtask->id,
            'invited_by_user_id' => $by->id,
        ]);

        $this->threads->note($task, "{$by->name} convidou {$who->name} para {$this->verb($role)}.");
        AuditLog::record($by, 'task.collaborator_invited', ['task_id' => $task->id, 'role' => $role->value, 'agent_id' => $collaborator->agent_id, 'user_id' => $collaborator->user_id], subject: $task);

        return $collaborator;
    }

    /**
     * Their part stops (the sub-task is cancelled, if still open) and they leave the task.
     */
    public function remove(TaskCollaborator $collaborator, User $by): void
    {
        $task = $collaborator->task;
        $subtask = $collaborator->subtask;
        $name = $collaborator->agent->name ?? $collaborator->user->name ?? 'Colaborador';

        if ($subtask !== null && ! $subtask->status->isClosed()) {
            $this->threads->setStatus($subtask, TaskStatus::Cancelled, $by, 'Saiu da tarefa principal.');
        }

        $collaborator->delete();
        $this->threads->note($task, "{$by->name} retirou {$name} da tarefa.");
        AuditLog::record($by, 'task.collaborator_removed', ['task_id' => $task->id, 'role' => $collaborator->role->value], subject: $task);
    }

    private function verb(CollaboratorRole $role): string
    {
        return match ($role) {
            CollaboratorRole::Prepare => 'preparar',
            CollaboratorRole::Draft => 'fazer o rascunho',
            CollaboratorRole::FollowUp => 'acompanhar',
            CollaboratorRole::Do => 'fazer a tarefa',
            CollaboratorRole::Review => 'rever',
        };
    }
}
