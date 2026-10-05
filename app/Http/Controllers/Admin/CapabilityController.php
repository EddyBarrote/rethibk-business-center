<?php

namespace App\Http\Controllers\Admin;

use App\Ai\Capabilities\CapabilityCatalog;
use App\Enums\AutonomyLevel;
use App\Erp\Exceptions\ErpException;
use App\Models\AuditLog;
use App\Models\Capability;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The tenant's capability catalogue: local capabilities and ERP tools, each with the
 * autonomy level an agent needs to use it without approval.
 */
class CapabilityController extends AdminController
{
    public function index(Tenant $tenant): Response
    {
        return Inertia::render('Admin/Capabilities/Index', [
            'tenant' => ['id' => $tenant->id, 'name' => $tenant->name],
            'capabilities' => Capability::query()->orderBy('source')->orderBy('key')->get()->map(fn (Capability $capability) => [
                'id' => $capability->id,
                'key' => $capability->key,
                'name' => $capability->name,
                'description' => $capability->description,
                'source' => $capability->source->value,
                'scope' => $capability->scope->value,
                'is_mutating' => $capability->is_mutating,
                'is_available' => $capability->is_available,
                'risk' => $capability->risk->value,
                'ceiling' => config('autonomy.ceiling.'.$capability->key),
                'agents' => $capability->agents()->count(),
            ]),
            'levels' => AutonomyLevel::options(),
        ]);
    }

    public function update(Request $request, Tenant $tenant, Capability $capability): RedirectResponse
    {
        $data = $request->validate(['risk' => ['required', Rule::enum(AutonomyLevel::class)]]);
        $before = $capability->risk->value;

        $capability->update($data);

        AuditLog::record($this->admin($request), 'capability.risk_updated', ['key' => $capability->key, 'before' => $before, 'after' => $capability->risk->value], subject: $capability);

        return back()->with('success', 'Nível de risco guardado.');
    }

    public function sync(Tenant $tenant, CapabilityCatalog $catalog): RedirectResponse
    {
        $catalog->syncLocal();

        try {
            $count = $catalog->syncErp();
        } catch (ErpException $e) {
            return back()->with('error', 'Capacidades locais actualizadas, mas o ERP não respondeu: '.$e->getMessage());
        }

        return back()->with('success', "Catálogo actualizado: {$count} ".($count === 1 ? 'ferramenta' : 'ferramentas').' do ERP.');
    }
}
