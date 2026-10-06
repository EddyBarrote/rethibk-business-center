<?php

namespace App\Http\Controllers;

use App\Ai\Capabilities\CapabilityCatalog;
use App\Ai\Capabilities\CapabilityRegistry;
use App\Connectors\ConnectorException;
use App\Enums\AutonomyLevel;
use App\Enums\Scope;
use App\Erp\Exceptions\ErpException;
use App\Models\AuditLog;
use App\Models\Capability;
use App\Models\PlatformConnector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The tenant's catalogue of capabilities (docs/CAPACIDADES.md): what the
 * platform, the ERP and the connectors offer. Owners and admins switch
 * capabilities on and off and set the risk of their own connectors' ones.
 * The connectors themselves, and activating the global ones, live under
 * Definições › Integrações (IntegrationController); the routes stay here.
 */
class CapabilityController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('manage-catalog');

        $capabilities = Capability::query()->whereNotIn('key', CapabilityRegistry::hidden())->withCount('agents')->orderBy('key')->get();

        return Inertia::render('Capabilities/Index', [
            'capabilities' => $capabilities->map(fn (Capability $capability) => [
                'id' => $capability->id,
                'key' => $capability->key,
                'name' => $capability->name,
                'description' => $capability->description,
                'source' => $capability->source->value,
                'source_label' => $capability->source->label(),
                'scope' => $capability->scope->value,
                'connector_id' => $capability->connector_id,
                'platform_connector_id' => $capability->platform_connector_id,
                'is_mutating' => $capability->is_mutating,
                'is_available' => $capability->is_available,
                'is_enabled' => $capability->is_enabled,
                'risk' => $capability->risk->value,
                'ceiling' => config('autonomy.ceiling.'.$capability->key) !== null,
                'agents' => $capability->agents_count,
            ])->values(),
            'levels' => AutonomyLevel::options(),
        ]);
    }

    /**
     * Switch a capability on or off for the whole company; the risk can be
     * changed only on the company's own connectors (the platform's stay with
     * the super admin).
     */
    public function update(Request $request, Capability $capability): RedirectResponse
    {
        Gate::authorize('manage-catalog');

        $data = $request->validate([
            'is_enabled' => ['sometimes', 'boolean'],
            'risk' => ['sometimes', Rule::enum(AutonomyLevel::class)],
        ]);

        if (array_key_exists('risk', $data) && $capability->scope !== Scope::Tenant) {
            abort(403, 'O risco das capacidades globais é definido pela Rethink.');
        }

        $before = ['is_enabled' => $capability->is_enabled, 'risk' => $capability->risk->value];
        $capability->update($data);

        AuditLog::record($this->user($request), 'capability.updated', ['key' => $capability->key, 'before' => $before, 'after' => ['is_enabled' => $capability->is_enabled, 'risk' => $capability->risk->value]], subject: $capability);

        return back()->with('success', 'Capacidade guardada.');
    }

    /**
     * Platform capabilities and ERP tools, as the super admin's button does.
     */
    public function sync(CapabilityCatalog $catalog): RedirectResponse
    {
        Gate::authorize('manage-catalog');

        $catalog->syncLocal();

        try {
            $count = $catalog->syncErp();
        } catch (ErpException $e) {
            return back()->with('error', 'Capacidades da plataforma actualizadas, mas o ERP não respondeu: '.$e->getMessage());
        }

        return back()->with('success', "Catálogo actualizado: {$count} ".($count === 1 ? 'ferramenta' : 'ferramentas').' do ERP.');
    }

    public function activate(Request $request, PlatformConnector $connector, CapabilityCatalog $catalog): RedirectResponse
    {
        Gate::authorize('manage-catalog');
        abort_unless($connector->is_active, 404);

        try {
            $count = $catalog->syncConnector($connector, $this->user($request));
        } catch (ConnectorException $e) {
            return back()->with('error', $e->getMessage());
        }

        AuditLog::record($this->user($request), 'connector.global_activated', ['connector' => $connector->key, 'tools' => $count]);

        return back()->with('success', "{$connector->name} activado: {$count} capacidade(s).");
    }

    public function deactivate(Request $request, PlatformConnector $connector, CapabilityCatalog $catalog): RedirectResponse
    {
        Gate::authorize('manage-catalog');

        $catalog->deactivate($connector);
        AuditLog::record($this->user($request), 'connector.global_deactivated', ['connector' => $connector->key]);

        return back()->with('success', "{$connector->name} desligado.");
    }
}
