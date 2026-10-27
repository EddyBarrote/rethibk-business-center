<?php

namespace App\Http\Controllers;

use App\Enums\TaskKind;
use App\Enums\TaskMessageKind;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\AgentInitiative;
use App\Models\AuditLog;
use App\Models\EmailMessage;
use App\Tasks\TaskThread;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * «Começar» and «Esconder» on what an agent offered to do (docs/DECISOES.md,
 * "Iniciativas dos agentes"), by the person it was offered to.
 */
class InitiativeController extends Controller
{
    public function start(Request $request, AgentInitiative $initiative, TaskThread $threads): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($initiative->user_id === $user->id, 403);

        if ($initiative->status !== AgentInitiative::OPEN) {
            return $initiative->task_id !== null ? to_route('tasks.show', $initiative->task_id) : back()->with('error', 'Esta sugestão já não está em aberto.');
        }

        $agent = $initiative->agent;
        $email = collect($initiative->evidence)->first(fn (array $e) => str_starts_with($e['ref'], 'email:'));
        $evidence = collect($initiative->evidence)->map(fn (array $e) => "- {$e['label']}")->implode("\n");

        $task = $threads->open([
            'kind' => TaskKind::Task,
            'title' => $initiative->title,
            'description' => "{$initiative->plan}\n\nPorquê: {$initiative->why}\n\nDe onde veio:\n{$evidence}",
            'status' => TaskStatus::Todo,
            'priority' => TaskPriority::Normal,
            'assignee_agent_id' => $agent->id,
            'user_id' => $user->id,
            'source_type' => $email !== null ? (new EmailMessage)->getMorphClass() : null,
            'source_id' => $email !== null ? (int) substr($email['ref'], 6) : null,
        ], $user, 'Começa. Prepara tudo, mas nada sai para fora sem eu ver.');
        $threads->post($task, $agent, 'Vou tratar disso.', TaskMessageKind::Message, wake: false, notify: false);

        $initiative->update(['status' => AgentInitiative::STARTED, 'task_id' => $task->id, 'decided_at' => now()]);
        AuditLog::record($user, 'initiative.started', ['initiative_id' => $initiative->id, 'task_id' => $task->id], subject: $task);

        return to_route('tasks.show', $task);
    }

    public function hide(Request $request, AgentInitiative $initiative): RedirectResponse
    {
        abort_unless($initiative->user_id === $this->user($request)->id, 403);

        $initiative->update(['status' => AgentInitiative::HIDDEN, 'decided_at' => now()]);

        return back();
    }
}
