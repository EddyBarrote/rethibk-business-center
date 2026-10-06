<?php

namespace App\Access;

use App\Models\Agent;
use App\Models\AgentAssignment;
use App\Models\User;

/**
 * Who talks to which agent (docs/DECISOES.md, realinhamento L8): a row in
 * agent_assignments per person, with one of two levels.
 */
final class AgentAccess
{
    /** Ask and get answers. */
    public const CHAT = 'chat';

    /** Also ask for work: tasks and direct actions. */
    public const WORK = 'work';

    public const LEVELS = [self::CHAT, self::WORK];

    public static function levelOf(Agent $agent, User $person): ?string
    {
        $row = AgentAssignment::query()->where('agent_id', $agent->id)->where('user_id', $person->id)->first();

        if ($row === null) {
            return null;
        }

        // Rows from before the two levels were people assigned to the agent: full access.
        return $row->role === self::CHAT ? self::CHAT : self::WORK;
    }
}
