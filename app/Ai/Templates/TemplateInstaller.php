<?php

namespace App\Ai\Templates;

use App\Ai\Skills\SkillCatalog;
use App\Enums\AgentStatus;
use App\Enums\MailboxStatus;
use App\Enums\Role;
use App\Erp\Exceptions\ErpException;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Mailbox;
use App\Models\Skill;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates an agent from a template in the current tenant: department,
 * skills, routines and a disabled mailbox waiting for credentials. An agent
 * that already exists for the template is left as the admin configured it.
 */
final class TemplateInstaller
{
    private const CHIEF = 'chief_of_staff';

    public function __construct(private readonly SkillCatalog $catalog) {}

    /**
     * @return array{agent: Agent, created: bool, missing_skills: list<string>, mailbox: string|null}
     */
    public function install(AgentTemplate $template, ?Model $actor = null, AgentStatus $status = AgentStatus::Active): array
    {
        $existing = Agent::query()->where('key', $template->key)->orWhere('settings->template', $template->key)->first();

        if ($existing !== null) {
            return ['agent' => $existing, 'created' => false, 'missing_skills' => [], 'mailbox' => Mailbox::query()->where('agent_id', $existing->id)->value('address')];
        }

        $this->ensureSkills($template);

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

            $skills = Skill::query()->whereIn('key', $template->skills)->pluck('id', 'key');
            $agent->skills()->sync(array_fill_keys($skills->values()->all(), ['enabled' => true]));

            foreach ($template->routines as $routine) {
                $agent->routines()->create([...$routine, 'is_active' => true]);
            }

            $address = $this->mailbox($agent, $template);

            AuditLog::record($actor, 'agent.installed_from_template', ['template' => $template->key, 'skills' => $skills->keys()->all()], subject: $agent);

            return [
                'agent' => $agent,
                'created' => true,
                'missing_skills' => array_values(array_diff($template->skills, $skills->keys()->all())),
                'mailbox' => $address,
            ];
        });
    }

    /**
     * Local skills are always synced; ERP ones only when the ERP answers.
     */
    private function ensureSkills(AgentTemplate $template): void
    {
        $this->catalog->syncLocal();

        $needsErp = collect($template->skills)->contains(fn (string $key) => str_starts_with($key, 'erp.'));

        if ($needsErp && Skill::query()->where('key', 'like', 'erp.%')->doesntExist()) {
            try {
                $this->catalog->syncErp();
            } catch (ErpException) {
                // Installed without ERP skills; "Sincronizar" in the admin adds them later.
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
