<?php

namespace App\Console\Concerns;

use App\Ai\Runs\AgentDirectory;
use App\Ai\Runs\AgentRunner;
use App\Enums\TriggerType;
use App\Models\AgentRun;
use Illuminate\Database\Eloquent\Model;

/**
 * Scheduled commands hand work to the agent playing a role in the current
 * tenant; a tenant without that agent is skipped.
 */
trait DispatchesRoles
{
    protected function dispatchRole(string $role, string $prompt, ?Model $source = null, TriggerType $trigger = TriggerType::Schedule): ?AgentRun
    {
        $agent = app(AgentDirectory::class)->forRole($role);

        return $agent === null ? null : app(AgentRunner::class)->dispatch($agent, $prompt, $trigger, source: $source);
    }
}
