<?php

namespace App\Http\Controllers\Admin;

use App\Connectors\ConnectorException;
use App\Connectors\ConnectorGateway;
use App\Enums\ConnectorKind;
use App\Erp\ErpTool;
use App\Models\PlatformConnector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Global connectors (docs/CAPACIDADES.md): MCP servers and HTTP actions
 * Rethink offers to every tenant. Each company activates the ones it wants,
 * which copies the tools into its capability catalogue.
 */
class PlatformConnectorController extends AdminController
{
    public function index(): Response
    {
        return Inertia::render('Admin/Connectors/Index', [
            'connectors' => PlatformConnector::query()->orderBy('name')->get()->map(fn (PlatformConnector $connector) => [
                ...$connector->summary(),
                'tool_list' => $connector->tools ?? [],
                'tenants' => DB::table('capabilities')->where('platform_connector_id', $connector->id)->where('is_enabled', true)->distinct()->count('tenant_id'),
            ]),
            'kinds' => array_map(fn (ConnectorKind $kind) => ['value' => $kind->value, 'label' => $kind->label()], ConnectorKind::cases()),
        ]);
    }

    public function store(Request $request, ConnectorGateway $gateway): RedirectResponse
    {
        $connector = PlatformConnector::query()->create($this->validated($request));

        return $this->discover($connector, $gateway, 'Conector global criado');
    }

    public function update(Request $request, PlatformConnector $connector, ConnectorGateway $gateway): RedirectResponse
    {
        $data = $this->validated($request, $connector);

        if (blank($data['secret'] ?? null)) {
            unset($data['secret']);
        }

        if ($request->boolean('clear_secret')) {
            $data['secret'] = null;
        }

        $connector->update($data);

        // Switching it off reaches every tenant at once (the query spans tenants on purpose).
        DB::table('capabilities')->where('platform_connector_id', $connector->id)->update(['is_available' => $connector->is_active]);

        return $this->discover($connector, $gateway, 'Conector global guardado');
    }

    public function test(PlatformConnector $connector, ConnectorGateway $gateway): RedirectResponse
    {
        return $this->discover($connector, $gateway, 'Ligação testada');
    }

    public function destroy(PlatformConnector $connector): RedirectResponse
    {
        $connector->delete();

        return back()->with('success', 'Conector global apagado; as capacidades dele saíram de todas as organizações.');
    }

    /**
     * List the tools now, so a broken URL shows here and not in a tenant.
     */
    private function discover(PlatformConnector $connector, ConnectorGateway $gateway, string $done): RedirectResponse
    {
        try {
            $tools = $gateway->discover($connector);
        } catch (ConnectorException $e) {
            $connector->forceFill(['last_error' => Str::limit($e->getMessage(), 250), 'last_synced_at' => now()])->save();

            return back()->with('error', "{$done}, mas não foi possível ler as ferramentas: {$e->getMessage()}");
        }

        $connector->forceFill(['tools' => array_map(fn (ErpTool $tool) => $tool->summary(), $tools), 'last_error' => null, 'last_synced_at' => now()])->save();

        return back()->with('success', "{$done}: ".count($tools).' ferramenta(s).');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?PlatformConnector $connector = null): array
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9][a-z0-9-]*$/', Rule::unique('platform_connectors', 'key')->ignore($connector)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:2000'],
            'kind' => ['required', Rule::enum(ConnectorKind::class)],
            'url' => ['required', 'url:https,http', 'max:2048'],
            'secret' => ['nullable', 'string', 'max:2000'],
            'http_method' => ['nullable', 'required_if:kind,http', 'in:GET,POST,PUT,PATCH,DELETE'],
            'input_schema' => ['nullable', 'string', 'max:20000'],
            'is_mutating' => ['boolean'],
            'is_active' => ['boolean'],
        ]);

        $schema = blank($data['input_schema'] ?? null) ? null : json_decode((string) $data['input_schema'], true);

        if ($schema !== null && (! is_array($schema) || ($schema['type'] ?? null) !== 'object')) {
            throw ValidationException::withMessages(['input_schema' => 'Tem de ser um JSON Schema com "type": "object".']);
        }

        $data['input_schema'] = $schema ?? ($data['kind'] === ConnectorKind::Http->value ? ['type' => 'object', 'properties' => new \stdClass] : null);

        return $data;
    }
}
