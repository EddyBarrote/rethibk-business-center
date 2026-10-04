<?php

namespace App\Http\Presenters;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Approval;

/**
 * Shapes shared by several tenant console screens.
 */
final class Present
{
    /**
     * @return array<string, mixed>
     */
    public static function agent(Agent $agent): array
    {
        return [
            'id' => $agent->id,
            'key' => $agent->key,
            'name' => $agent->name,
            'title' => $agent->title,
            'description' => $agent->description,
            'status' => $agent->status->value,
            'status_label' => $agent->status->label(),
            'suspended_reason' => $agent->suspended_reason,
            'autonomy_level' => $agent->autonomy_level->value,
            'department' => $agent->department?->name,
            'reports_to' => $agent->reportsTo?->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function run(AgentRun $run): array
    {
        return [
            'id' => $run->id,
            'agent' => ['id' => $run->agent_id, 'name' => $run->agent->name],
            'trigger' => $run->trigger_type->value,
            'trigger_label' => $run->trigger_type->label(),
            'status' => $run->status->value,
            'status_label' => $run->status->label(),
            'input' => $run->input,
            'output' => $run->output['text'] ?? null,
            'requested_by' => $run->requestedBy?->name,
            'provider' => $run->provider,
            'model' => $run->model,
            'input_tokens' => $run->input_tokens,
            'output_tokens' => $run->output_tokens,
            'cost_usd' => $run->cost_usd,
            'duration_ms' => $run->duration_ms,
            'error' => $run->error,
            'created_at' => $run->created_at->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function approval(Approval $approval, bool $canDecide): array
    {
        return [
            'id' => $approval->id,
            'run_id' => $approval->agent_run_id,
            'agent' => ['id' => $approval->agent_id, 'name' => $approval->agent->name],
            'action_type' => $approval->action_type,
            'action_summary' => $approval->action_summary,
            'payload' => $approval->payload,
            'required_level' => $approval->required_level->value,
            'agent_level' => $approval->agent_level->value,
            'ceiling_reason' => $approval->ceiling_reason,
            'status' => $approval->status->value,
            'status_label' => $approval->status->label(),
            'assigned_to' => $approval->assignedTo?->name,
            'decided_by' => $approval->decidedBy?->name,
            'decided_at' => $approval->decided_at?->toIso8601String(),
            'decision_note' => $approval->decision_note,
            'execution_status' => $approval->execution_status->value,
            'execution_result' => $approval->execution_result,
            'created_at' => $approval->created_at->toIso8601String(),
            'can_decide' => $canDecide && $approval->status->value === 'pending',
        ];
    }
}
