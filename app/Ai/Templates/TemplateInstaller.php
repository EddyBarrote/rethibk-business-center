<?php

namespace App\Ai\Templates;

use App\Ai\Capabilities\CapabilityCatalog;
use App\Ai\Capabilities\CapabilityRegistry;
use App\Enums\AgentStatus;
use App\Enums\MailboxStatus;
use App\Enums\Role;
use App\Erp\Exceptions\ErpException;
use App\Erp\Perfex\PerfexToolMap;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\Capability;
use App\Models\Department;
use App\Models\Mailbox;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates an agent from a template in the current tenant: department,
 * capabilities, routines and a disabled mailbox waiting for credentials. An agent
 * that already exists for the template is left as the admin configured it,
 * unless someone asks to bring it up to date (refresh()).
 */
final class TemplateInstaller
{
    private const CHIEF = 'chief_of_staff';

    public function __construct(private readonly CapabilityCatalog $catalog) {}

    /**
     * @return array{agent: Agent, created: bool, missing_capabilities: list<string>, mailbox: string|null}
     */
    public function install(AgentTemplate $template, ?Model $actor = null, AgentStatus $status = AgentStatus::Active): array
    {
        $existing = $this->find($template);

        if ($existing !== null) {
            return ['agent' => $existing, 'created' => false, 'missing_capabilities' => [], 'mailbox' => Mailbox::query()->where('agent_id', $existing->id)->value('address')];
        }

        $this->ensureCapabilities($template);

        return DB::transaction(function () use ($template, $actor, $status) {
            $department = Department::query()->where('name', $template->department)->first()
                ?? Department::query()->create(['name' => $template->department, 'slug' => Str::slug($template->department)]);

            $manager = User::query()->where('department_id', $department->id)->where('is_active', true)->whereIn('role', [Role::Manager, Role::Admin, Role::Owner])->orderBy('id')->first()
                ?? User::query()->where('role', Role::Owner)->where('is_active', true)->orderBy('id')->first();

            $agent = Agent::query()->create([
                'key' => $template->key,
                'name' => $template->name,
                'title' => $template->title,
                'description' => $template->description,
                'personality' => $template->personality,
                'instructions' => $template->instructions,
                'department_id' => $department->id,
                'reports_to_user_id' => $manager?->id,
                'status' => $status,
                'autonomy_level' => $template->autonomy,
                'settings' => ['template' => $template->key],
            ]);

            $this->placeInOrgChart($agent, $template);

            $capabilities = Capability::query()->whereIn('key', [...$template->capabilities, ...CapabilityRegistry::ON_BY_DEFAULT])->pluck('id', 'key');
            $agent->capabilities()->sync(array_fill_keys($capabilities->values()->all(), ['enabled' => true]));
            // On a Perfex ERP the contract keys have no rows: the reads that do their job instead.
            $perfex = $this->catalog->grantPerfexReadsFor($agent, $template->capabilities);

            foreach ($template->routines as $routine) {
                $agent->routines()->create([...$routine, 'is_active' => true]);
            }

            $address = $this->mailbox($agent, $template);

            AuditLog::record($actor, 'agent.installed_from_template', ['template' => $template->key, 'capabilities' => [...$capabilities->keys()->all(), ...$perfex]], subject: $agent);

            return [
                'agent' => $agent,
                'created' => true,
                'missing_capabilities' => $this->missing($template, [...$capabilities->keys()->all(), ...$perfex]),
                'mailbox' => $address,
            ];
        });
    }

    /**
     * Brings an agent made from the template up to its current version
     * (docs/DECISOES.md, Director de Recursos Humanos), only when asked:
     * name, title, description, personality and instructions as the template
     * says; the template's capabilities added, none taken away; its routines
     * matched by name. A routine keeps the on/off state the admin left it in;
     * a new one starts off when all the agent's routines are off; one the
     * template no longer has is switched off, not deleted. Department,
     * autonomy, model, chefia and mailbox stay as they are.
     *
     * @return array{agent: Agent, capabilities_added: list<string>, routines_created: list<string>, routines_updated: list<string>, routines_deactivated: list<string>}|null null when no agent comes from the template
     */
    public function refresh(AgentTemplate $template, ?Model $actor = null): ?array
    {
        $agent = $this->find($template);

        if ($agent === null) {
            return null;
        }

        $this->ensureCapabilities($template);

        return DB::transaction(function () use ($template, $actor, $agent) {
            $before = $agent->only(['name', 'title', 'description', 'personality', 'instructions']);

            $agent->forceFill([
                'name' => $template->name,
                'title' => $template->title,
                'description' => $template->description,
                'personality' => $template->personality,
                'instructions' => $template->instructions,
                'settings' => [...$agent->settings ?? [], 'template' => $template->key],
            ])->save();

            $held = $agent->capabilities()->pluck('capabilities.id')->all();
            // The template's own; the on-by-default ones an admin may have taken away on purpose.
            $new = Capability::query()->whereIn('key', $template->capabilities)->whereNotIn('id', $held)->pluck('id', 'key');
            $agent->capabilities()->syncWithoutDetaching(array_fill_keys($new->values()->all(), ['enabled' => true]));
            $added = [...$new->keys()->all(), ...$this->catalog->grantPerfexReadsFor($agent, $template->capabilities)];

            $routines = $this->syncRoutines($agent, $template);

            AuditLog::record($actor, 'agent.refreshed_from_template', [
                'template' => $template->key,
                'changed' => array_keys(array_diff_assoc($agent->only(array_keys($before)), $before)),
                'capabilities_added' => $added,
                ...$routines,
            ], subject: $agent);

            return ['agent' => $agent->fresh() ?? $agent, 'capabilities_added' => $added, ...$routines];
        });
    }

    /**
     * @return array{routines_created: list<string>, routines_updated: list<string>, routines_deactivated: list<string>}
     */
    private function syncRoutines(Agent $agent, AgentTemplate $template): array
    {
        $existing = $agent->routines()->whereNull('workflow_id')->get()->keyBy('name');
        $startOn = $existing->isNotEmpty() && $existing->contains('is_active', true);
        $result = ['routines_created' => [], 'routines_updated' => [], 'routines_deactivated' => []];

        foreach ($template->routines as $routine) {
            $current = $existing->get($routine['name']);

            if ($current === null) {
                $agent->routines()->create([...$routine, 'is_active' => $startOn]);
                $result['routines_created'][] = $routine['name'];

                continue;
            }

            $current->fill(['prompt' => $routine['prompt'], 'schedule' => $routine['schedule']]);

            if ($current->isDirty()) {
                $current->save();
                $result['routines_updated'][] = $routine['name'];
            }
        }

        $current = array_column($template->routines, 'name');

        foreach ($template->retiredRoutines as $name) {
            $retired = $existing->get($name);

            if ($retired !== null && $retired->is_active && ! in_array($name, $current, true)) {
                $retired->forceFill(['is_active' => false])->save();
                $result['routines_deactivated'][] = $name;
            }
        }

        return $result;
    }

    /**
     * The template's capabilities the agent could not get: no row, and on a
     * Perfex ERP no read doing the job either.
     *
     * @param  list<string>  $got
     * @return list<string>
     */
    private function missing(AgentTemplate $template, array $got): array
    {
        return array_values(array_filter(array_diff($template->capabilities, $got),
            fn (string $key) => array_intersect(PerfexToolMap::capabilitiesFor($key), $got) === []));
    }

    private function find(AgentTemplate $template): ?Agent
    {
        return Agent::query()->where('key', $template->key)->orWhere('settings->template', $template->key)->first();
    }

    /**
     * Local capabilities are always synced; ERP ones only when the ERP answers.
     */
    private function ensureCapabilities(AgentTemplate $template): void
    {
        $this->catalog->syncLocal();

        $needsErp = collect($template->capabilities)->contains(fn (string $key) => str_starts_with($key, 'erp.'));

        if ($needsErp && Capability::query()->where('key', 'like', 'erp.%')->doesntExist()) {
            try {
                $this->catalog->syncErp();
            } catch (ErpException) {
                // Installed without ERP capabilities; "Sincronizar" in the admin adds them later.
            }
        }
    }

    private function mailbox(Agent $agent, AgentTemplate $template): ?string
    {
        $tenant = Tenant::current();
        $domain = data_get($tenant?->settings, 'mail_domain') ?: $tenant?->domain;

        if (blank($domain)) {
            return null;
        }

        $address = Str::lower($template->mailbox.'@'.$domain);

        if (Mailbox::query()->where('address', $address)->exists()) {
            return null;
        }

        Mailbox::query()->create([
            'agent_id' => $agent->id,
            'address' => $address,
            'display_name' => $agent->name.' · '.$tenant?->name,
            'status' => MailboxStatus::Disabled,
        ]);

        return $address;
    }

    /**
     * The Chief of Staff tops the agent org chart (docs/DECISOES.md): the
     * other template agents report to it, whichever is installed first.
     */
    private function placeInOrgChart(Agent $agent, AgentTemplate $template): void
    {
        if ($template->key === self::CHIEF) {
            Agent::query()->whereKeyNot($agent->id)->whereNull('reports_to_agent_id')->whereNotNull('settings->template')
                ->update(['reports_to_agent_id' => $agent->id]);

            return;
        }

        $chief = Agent::query()->where('settings->template', self::CHIEF)->value('id');

        if ($chief !== null) {
            $agent->forceFill(['reports_to_agent_id' => $chief])->save();
        }
    }
}
