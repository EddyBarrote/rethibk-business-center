<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Database\Factories\AgentSkillFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $agent_id
 * @property int $skill_id
 */
#[Fillable(['agent_id', 'skill_id'])]
class AgentSkill extends Pivot
{
    /** @use HasFactory<AgentSkillFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'agent_skill';

    public $incrementing = true;
}
