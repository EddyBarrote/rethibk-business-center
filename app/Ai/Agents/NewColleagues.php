<?php

namespace App\Ai\Agents;

use App\Ai\Capabilities\CapabilityRegistry;
use App\Ai\Skills\SkillVersions;
use App\Enums\AgentStatus;
use App\Enums\AuditResult;
use App\Enums\AutonomyLevel;
use App\Models\Agent;
use App\Models\Approval;
use App\Models\AuditLog;
use App\Models\Capability;
use App\Models\Department;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * New agents and new skills the Chief of Staff prepares in a conversation
 * (docs/DECISOES.md, «Chief of Staff cria agentes e skills»). The proposal is
 * in words (names, emails, keys); this resolves it against the company, says
 * what is missing, shows it on the card, and creates it once a person with
 * the permission confirms.
 */
final class NewColleagues
{
    /** A new agent starts low; its trust rises later with agents.set_trust_level. */
    public const MAX_START_LEVEL = AutonomyLevel::Suggest;

    public function __construct(private readonly AgentEditor $editor, private readonly SkillVersions $versions) {}

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{missing: list<string>, data: array<string, mixed>, facts: list<array{label: string, value: string}>}
     */
    public function agent(array $arguments): array
    {
        $missing = [];
        $text = fn (string $field) => trim((string) ($arguments[$field] ?? ''));

        foreach (['name' => 'o nome', 'title' => 'a função', 'department' => 'a área', 'reports_to' => 'a quem responde', 'instructions' => 'o que faz e como (instruções)'] as $field => $label) {
            if ($text($field) === '') {
                $missing[] = $label;
            }
        }

        $department = $text('department') === '' ? null : Department::query()->whereRaw('lower(name) = ?', [mb_strtolower($text('department'))])->first();

        if ($text('department') !== '' && $department === null) {
            $missing[] = sprintf('uma área que exista (não há «%s»; há: %s)', $text('department'), Department::query()->orderBy('name')->pluck('name')->implode(', ') ?: 'nenhuma');
        }

        [$person, $boss] = $this->reportsTo($text('reports_to'));

        if ($text('reports_to') !== '' && $person === null && $boss === null) {
            $missing[] = sprintf('a quem responde: «%s» não é o email de uma pessoa activa nem a chave de um agente', $text('reports_to'));
        }

        if (Agent::query()->whereRaw('lower(name) = ?', [mb_strtolower($text('name'))])->exists()) {
            $missing[] = sprintf('outro nome: já há um agente «%s»', $text('name'));
        }

        $level = AutonomyLevel::tryFrom((int) ($arguments['autonomy_level'] ?? self::MAX_START_LEVEL->value)) ?? self::MAX_START_LEVEL;

        if ($level->value > self::MAX_START_LEVEL->value) {
            $missing[] = sprintf('um nível de confiança inicial até %s (um agente novo começa baixo; sobe depois com o histórico)', self::MAX_START_LEVEL->code());
        }

        [$capabilities, $unknownCapabilities] = $this->capabilities($this->keys($arguments['capabilities'] ?? []));
        [$skills, $unknownSkills] = $this->skills($this->keys($arguments['skills'] ?? []));

        if ($unknownCapabilities !== []) {
            $missing[] = 'capacidades que existam (não há: '.implode(', ', $unknownCapabilities).')';
        }

        if ($unknownSkills !== []) {
            $missing[] = 'skills que existam (não há: '.implode(', ', $unknownSkills).')';
        }

        $data = [
            'key' => $this->agentKey($text('name')),
            'name' => $text('name'),
            'title' => $text('title'),
            'description' => $text('description') ?: null,
            'personality' => $text('personality') ?: null,
            'instructions' => $text('instructions'),
            'department_id' => $department?->id,
            'reports_to_user_id' => $person?->id,
            'reports_to_agent_id' => $boss?->id,
            'status' => AgentStatus::Active->value,
            'autonomy_level' => min($level->value, self::MAX_START_LEVEL->value),
            'capabilities' => [...$capabilities->pluck('id')->all(), ...Capability::query()->whereIn('key', CapabilityRegistry::ON_BY_DEFAULT)->pluck('id')->all()],
            'skills' => $skills->pluck('id')->all(),
        ];

        $facts = array_values(array_filter([
            ['label' => 'Nome', 'value' => $data['name']],
            ['label' => 'Função', 'value' => $data['title']],
            ['label' => 'Área', 'value' => $department->name ?? $text('department')],
            ['label' => 'Responde a', 'value' => $person->name ?? $boss->name ?? $text('reports_to')],
            ['label' => 'Confiança', 'value' => AutonomyLevel::from($data['autonomy_level'])->code().' · '.AutonomyLevel::from($data['autonomy_level'])->label()],
            ['label' => 'Capacidades', 'value' => $capabilities->pluck('name')->implode(', ')],
            ['label' => 'Skills', 'value' => $skills->map(fn (Skill $skill) => $skill->displayName())->implode(', ')],
            ['label' => 'Descrição', 'value' => (string) $data['description']],
            ['label' => 'Instruções', 'value' => $data['instructions']],
        ], fn (array $fact) => $fact['value'] !== ''));

        return ['missing' => $missing, 'data' => $data, 'facts' => $facts];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{missing: list<string>, data: array<string, mixed>, facts: list<array{label: string, value: string}>}
     */
    public function skill(array $arguments): array
    {
        $missing = [];
        $text = fn (string $field) => trim((string) ($arguments[$field] ?? ''));

        foreach (['name' => 'o nome', 'description' => 'quando se usa (descrição)', 'instructions' => 'como se faz (instruções)'] as $field => $label) {
            if ($text($field) === '') {
                $missing[] = $label;
            }
        }

        if ($text('name') !== '' && Skill::query()->whereRaw('lower(name) = ?', [mb_strtolower($text('name'))])->exists()) {
            $missing[] = sprintf('outro nome: já há uma skill «%s»', $text('name'));
        }

        $allAgents = filter_var($arguments['all_agents'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $wanted = $this->keys($arguments['agents'] ?? []);
        $agents = Agent::query()->whereIn('key', $wanted)->orderBy('name')->get(['id', 'key', 'name']);
        $unknown = array_values(array_diff($wanted, $agents->pluck('key')->all()));

        if ($unknown !== []) {
            $missing[] = 'agentes que existam (não há: '.implode(', ', $unknown).')';
        }

        if (! $allAgents && $wanted === []) {
            $missing[] = 'que agentes a recebem (ou todos)';
        }

        $data = [
            'key' => $this->skillKey($text('name')),
            'name' => $text('name'),
            'description' => $text('description'),
            'instructions' => $text('instructions'),
            'all_agents' => $allAgents,
            'agents' => $agents->pluck('id')->all(),
        ];

        $facts = array_values(array_filter([
            ['label' => 'Nome', 'value' => $data['name']],
            ['label' => 'Quando se usa', 'value' => $data['description']],
            ['label' => 'Agentes', 'value' => $allAgents ? 'Todos os agentes' : $agents->pluck('name')->implode(', ')],
            ['label' => 'Instruções', 'value' => $data['instructions']],
        ], fn (array $fact) => $fact['value'] !== ''));

        return ['missing' => $missing, 'data' => $data, 'facts' => $facts];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function createAgent(array $arguments, Agent $by, ?Approval $approval): Agent|string
    {
        $proposal = $this->agent($arguments);

        if ($proposal['missing'] !== []) {
            return 'Falta '.implode('; ', $proposal['missing']).'.';
        }

        $agent = $this->editor->save(new Agent, $proposal['data'], $this->approver($by, $approval));

        AuditLog::record($by, 'agent.proposed_created', ['agent_id' => $agent->id, 'approval_id' => $approval?->id], AuditResult::Ok, $agent);

        return $agent;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function createSkill(array $arguments, Agent $by, ?Approval $approval): Skill|string
    {
        $proposal = $this->skill($arguments);

        if ($proposal['missing'] !== []) {
            return 'Falta '.implode('; ', $proposal['missing']).'.';
        }

        $approver = $this->approver($by, $approval);
        $data = $proposal['data'];

        $skill = DB::transaction(function () use ($data, $approver) {
            $skill = new Skill([...array_intersect_key($data, array_flip(['key', 'name', 'description', 'instructions', 'all_agents'])), 'is_enabled' => true]);
            $skill->forceFill(['created_by_user_id' => $approver->id])->save();
            $skill->agents()->sync($data['agents']);
            $this->versions->record($skill, $approver);

            return $skill;
        });

        AuditLog::record($approver, 'skill.created', ['key' => $skill->key, 'proposed_by_agent_id' => $by->id, 'approval_id' => $approval?->id], subject: $skill);

        return $skill;
    }

    /**
     * Who created it: the person who confirmed, or the one the agent reports to.
     */
    private function approver(Agent $by, ?Approval $approval): User
    {
        return $approval->decidedBy ?? $by->reportsTo ?? User::query()->where('role', 'owner')->firstOrFail();
    }

    /**
     * @return array{0: User|null, 1: Agent|null}
     */
    private function reportsTo(string $who): array
    {
        if ($who === '') {
            return [null, null];
        }

        $person = User::query()->where('is_active', true)
            ->where(fn ($q) => $q->whereRaw('lower(email) = ?', [mb_strtolower($who)])->orWhereRaw('lower(name) = ?', [mb_strtolower($who)]))
            ->first();

        if ($person !== null) {
            return [$person, null];
        }

        return [null, Agent::query()->where(fn ($q) => $q->where('key', $who)->orWhereRaw('lower(name) = ?', [mb_strtolower($who)]))->first()];
    }

    /**
     * @param  list<string>  $keys
     * @return array{0: Collection<int, Capability>, 1: list<string>}
     */
    private function capabilities(array $keys): array
    {
        $found = Capability::query()->whereIn('key', $keys)->whereNotIn('key', CapabilityRegistry::hidden())->get(['id', 'key', 'name']);

        return [$found, array_values(array_diff($keys, $found->pluck('key')->all()))];
    }

    /**
     * @param  list<string>  $keys
     * @return array{0: Collection<int, Skill>, 1: list<string>}
     */
    private function skills(array $keys): array
    {
        $found = Skill::query()->with('platformSkill')->whereIn('key', $keys)->get();

        return [$found, array_values(array_diff($keys, $found->pluck('key')->all()))];
    }

    /**
     * @return list<string>
     */
    private function keys(mixed $value): array
    {
        $list = is_array($value) ? $value : preg_split('/\s*,\s*/', (string) $value);

        return array_values(array_unique(array_filter(array_map(fn ($key) => trim((string) $key), $list ?: []), fn (string $key) => $key !== '')));
    }

    private function agentKey(string $name): string
    {
        $base = Str::limit(Str::slug($name, '_') ?: 'agente', 56, '');
        $key = $base;

        for ($i = 2; Agent::query()->where('key', $key)->exists(); $i++) {
            $key = "{$base}_{$i}";
        }

        return $key;
    }

    private function skillKey(string $name): string
    {
        $base = Str::limit(Str::slug($name) ?: 'skill', 56, '');
        $key = $base;

        for ($i = 2; Skill::query()->where('key', $key)->exists(); $i++) {
            $key = "{$base}-{$i}";
        }

        return $key;
    }
}
