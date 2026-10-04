<?php

namespace App\Ai\Agents;

use App\Enums\AgentStatus;
use App\Enums\AutonomyLevel;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\Capability;
use App\Models\Department;
use App\Models\PlatformAdmin;
use App\Models\Skill;
use App\Models\User;
use App\Tasks\OrgChart;
use App\Tenancy\TenantRule;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Creating and editing an agent's definition, shared by the super admin
 * console and the tenant's own admins (docs/CAPACIDADES.md): identity,
 * personality, instructions, model, autonomy, capabilities and skills.
 */
final class AgentEditor
{
    /**
     * @return array<string, mixed>
     */
    public function rules(?Agent $agent = null): array
    {
        $key = TenantRule::unique('agents', 'key');

        if ($agent !== null) {
            $key->ignore($agent->id);
        }

        return [
            'key' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9_-]*$/', $key],
            'name' => ['required', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'personality' => ['nullable', 'string', 'max:5000'],
            'instructions' => ['nullable', 'string', 'max:20000'],
            'department_id' => ['nullable', 'integer', TenantRule::exists('departments')],
            'reports_to_user_id' => ['nullable', 'integer', TenantRule::exists('users')],
            'reports_to_agent_id' => ['nullable', 'integer', TenantRule::exists('agents'), ...($agent !== null ? [Rule::notIn([$agent->id])] : [])],
            'status' => ['required', Rule::enum(AgentStatus::class)],
            'autonomy_level' => ['required', Rule::enum(AutonomyLevel::class)],
            'provider' => ['nullable', 'string', Rule::in(array_keys((array) config('ai.providers')))],
            'model' => ['nullable', 'string', 'max:255'],
            'temperature' => ['nullable', 'numeric', 'between:0,2'],
            'max_tokens' => ['nullable', 'integer', 'between:1,200000'],
            'max_steps' => ['nullable', 'integer', 'between:1,50'],
            'capabilities' => ['array'],
            'capabilities.*' => ['integer', TenantRule::exists('capabilities')],
            'skills' => ['array'],
            'skills.*' => ['integer', TenantRule::exists('skills')],
        ];
    }

    /**
     * @param  array<string, mixed>  $data  validated with rules()
     */
    public function save(Agent $agent, array $data, User|PlatformAdmin $actor): Agent
    {
        $isNew = ! $agent->exists;

        if (! $isNew && array_key_exists('reports_to_agent_id', $data) && app(OrgChart::class)->createsCycle($agent, $data['reports_to_agent_id'])) {
            throw ValidationException::withMessages(['reports_to_agent_id' => 'Isso criava um ciclo no organigrama.']);
        }

        $before = $isNew ? null : ['autonomy_level' => $agent->autonomy_level->value, 'status' => $agent->status->value];

        DB::transaction(function () use ($agent, $data, $actor, $isNew) {
            $agent->fill(Arr::except($data, ['capabilities', 'skills']));

            if (array_key_exists('reports_to_agent_id', $data)) {
                $agent->reports_to_agent_id = $data['reports_to_agent_id'];
            }

            if ($agent->status !== AgentStatus::Suspended) {
                $agent->suspended_reason = null;
            }

            if ($isNew) {
                $agent->forceFill($actor instanceof PlatformAdmin ? ['created_by_admin_id' => $actor->id] : ['created_by_user_id' => $actor->id]);
            }

            $agent->save();
            $agent->capabilities()->sync(array_fill_keys($data['capabilities'] ?? [], ['enabled' => true]));

            if (array_key_exists('skills', $data)) {
                $agent->skills()->sync($data['skills'] ?? []);
            }
        });

        AuditLog::record($actor, $isNew ? 'agent.created' : 'agent.updated', array_filter([
            'key' => $agent->key,
            'before' => $before,
            'after' => ['autonomy_level' => $agent->autonomy_level->value, 'status' => $agent->status->value],
            'capabilities' => $data['capabilities'] ?? [],
            'skills' => $data['skills'] ?? null,
        ], fn ($value) => $value !== null), subject: $agent);

        return $agent;
    }

    /**
     * The form's values for an existing agent.
     *
     * @return array<string, mixed>
     */
    public function present(Agent $agent, ?string $avatarUrl = null): array
    {
        return [
            ...$agent->only(['id', 'key', 'name', 'title', 'description', 'personality', 'instructions', 'department_id', 'reports_to_user_id', 'reports_to_agent_id', 'provider', 'model', 'temperature', 'max_tokens', 'max_steps']),
            'status' => $agent->status->value,
            'autonomy_level' => $agent->autonomy_level->value,
            'capabilities' => $agent->capabilities()->wherePivot('enabled', true)->pluck('capabilities.id'),
            'skills' => $agent->skills()->pluck('skills.id'),
            'avatar_url' => $avatarUrl ?? $agent->avatarUrl(),
        ];
    }

    /**
     * Everything the form lets you pick from, in the current tenant.
     *
     * @return array<string, mixed>
     */
    public function options(?Agent $agent = null): array
    {
        return [
            'departments' => Department::query()->orderBy('name')->get(['id', 'name']),
            'users' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'agents' => Agent::query()->when($agent, fn ($q) => $q->whereKeyNot($agent->id))->orderBy('name')->get(['id', 'name', 'title']),
            'capabilities' => Capability::query()->orderBy('source')->orderBy('key')->get()->map(fn (Capability $capability) => [
                'id' => $capability->id,
                'key' => $capability->key,
                'name' => $capability->name,
                'description' => $capability->description,
                'source' => $capability->source->value,
                'scope' => $capability->scope->value,
                'is_mutating' => $capability->is_mutating,
                'is_available' => $capability->isUsable(),
                'risk' => $capability->risk->value,
                'ceiling' => config('autonomy.ceiling.'.$capability->key) !== null,
            ]),
            'skills' => Skill::query()->with('platformSkill')->get()
                ->sortBy(fn (Skill $skill) => $skill->displayName())
                ->values()
                ->map(fn (Skill $skill) => [
                    'id' => $skill->id,
                    'key' => $skill->key,
                    'name' => $skill->displayName(),
                    'description' => $skill->displayDescription(),
                    'scope' => $skill->ownership()->value,
                    'is_available' => $skill->isUsable(),
                ]),
            'levels' => AutonomyLevel::options(),
            'statuses' => array_map(fn (AgentStatus $status) => ['value' => $status->value, 'label' => $status->label()], AgentStatus::cases()),
            'providers' => array_keys((array) config('ai.providers')),
            'defaultProvider' => config('ai.default'),
        ];
    }
}
