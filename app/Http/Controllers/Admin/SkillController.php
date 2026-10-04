<?php

namespace App\Http\Controllers\Admin;

use App\Ai\Skills\SkillCatalog;
use App\Enums\AutonomyLevel;
use App\Erp\Exceptions\ErpException;
use App\Models\AuditLog;
use App\Models\Skill;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The tenant's skill catalogue: local skills and ERP tools, each with the
 * autonomy level an agent needs to use it without approval.
 */
class SkillController extends AdminController
{
    public function index(Tenant $tenant): Response
    {
        return Inertia::render('Admin/Skills/Index', [
            'tenant' => ['id' => $tenant->id, 'name' => $tenant->name],
            'skills' => Skill::query()->orderBy('source')->orderBy('key')->get()->map(fn (Skill $skill) => [
                'id' => $skill->id,
                'key' => $skill->key,
                'name' => $skill->name,
                'description' => $skill->description,
                'source' => $skill->source->value,
                'is_mutating' => $skill->is_mutating,
                'is_available' => $skill->is_available,
                'risk' => $skill->risk->value,
                'ceiling' => config('autonomy.ceiling.'.$skill->key),
                'agents' => $skill->agents()->count(),
            ]),
            'levels' => AutonomyLevel::options(),
        ]);
    }

    public function update(Request $request, Tenant $tenant, Skill $skill): RedirectResponse
    {
        $data = $request->validate(['risk' => ['required', Rule::enum(AutonomyLevel::class)]]);
        $before = $skill->risk->value;

        $skill->update($data);

        AuditLog::record($this->admin($request), 'skill.risk_updated', ['key' => $skill->key, 'before' => $before, 'after' => $skill->risk->value], subject: $skill);

        return back()->with('success', 'Nível de risco guardado.');
    }

    public function sync(Tenant $tenant, SkillCatalog $catalog): RedirectResponse
    {
        $catalog->syncLocal();

        try {
            $count = $catalog->syncErp();
        } catch (ErpException $e) {
            return back()->with('error', 'Skills locais actualizadas, mas o ERP não respondeu: '.$e->getMessage());
        }

        return back()->with('success', "Catálogo actualizado: {$count} ferramenta(s) do ERP.");
    }
}
