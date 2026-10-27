<?php

namespace App\Http\Controllers\Settings;

use App\Enums\Permission;
use App\Enums\RuleEffect;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\ApprovalRule;
use App\Models\AuditLog;
use App\Models\Capability;
use App\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Definições › Regras de aprovação (docs/DECISOES.md, "Regras de aprovação"):
 * the standing rules people left with «Permitir sempre», and new ones, for one
 * agent or all of them. Who configures agents manages them.
 */
class ApprovalRuleController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($this->user($request)->hasPermission(Permission::ManageAgents), 403);
        $names = Capability::query()->pluck('name', 'key');

        return Inertia::render('Settings/ApprovalRules', [
            'rules' => ApprovalRule::query()->with(['agent:id,name', 'createdBy:id,name'])->latest('id')->get()->map(fn (ApprovalRule $rule) => [
                'id' => $rule->id,
                'agent' => $rule->agent?->name,
                'capability_key' => $rule->capability_key,
                'capability' => $names[$rule->capability_key] ?? $rule->capability_key,
                'effect' => $rule->effect->value,
                'created_by' => $rule->createdBy?->name,
                'from_approval' => $rule->from_approval_id !== null,
                'updated_at' => $rule->updated_at->toIso8601String(),
            ]),
            'agents' => Agent::query()->where('status', '!=', 'draft')->orderBy('name')->get(['id', 'name']),
            // Only what changes something is worth a rule; reading passes anyway.
            'capabilities' => Capability::query()->where('is_mutating', true)->where('is_available', true)->orderBy('name')->get(['key', 'name']),
            'effects' => collect(RuleEffect::cases())->map(fn (RuleEffect $e) => ['value' => $e->value, 'label' => $e->label()]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($user->hasPermission(Permission::ManageAgents), 403);
        $data = $request->validate([
            'agent_id' => ['nullable', 'integer', TenantRule::exists('agents', 'id')],
            'capability_key' => ['required', 'string', TenantRule::exists('capabilities', 'key')],
            'effect' => ['required', Rule::enum(RuleEffect::class)],
        ]);

        $rule = ApprovalRule::query()->updateOrCreate(
            ['agent_id' => $data['agent_id'] ?? null, 'capability_key' => $data['capability_key']],
            ['effect' => $data['effect'], 'created_by_user_id' => $user->id],
        );
        AuditLog::record($user, 'approval_rule.saved', ['rule_id' => $rule->id, 'rule' => $rule->describe()]);

        return back()->with('success', 'Regra guardada: '.$rule->describe().'.');
    }

    public function update(Request $request, ApprovalRule $rule): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($user->hasPermission(Permission::ManageAgents), 403);
        $data = $request->validate(['effect' => ['required', Rule::enum(RuleEffect::class)]]);

        $rule->update(['effect' => $data['effect'], 'created_by_user_id' => $user->id]);
        AuditLog::record($user, 'approval_rule.saved', ['rule_id' => $rule->id, 'rule' => $rule->describe()]);

        return back()->with('success', 'Regra mudada: '.$rule->describe().'.');
    }

    public function destroy(Request $request, ApprovalRule $rule): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($user->hasPermission(Permission::ManageAgents), 403);

        AuditLog::record($user, 'approval_rule.removed', ['rule_id' => $rule->id, 'rule' => $rule->describe()]);
        $rule->delete();

        return back()->with('success', 'Regra retirada: volta a valer o nível do agente.');
    }
}
