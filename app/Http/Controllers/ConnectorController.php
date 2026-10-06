<?php

namespace App\Http\Controllers;

use App\Ai\Capabilities\CapabilityCatalog;
use App\Connectors\ConnectorException;
use App\Enums\ConnectorKind;
use App\Models\AuditLog;
use App\Models\Connector;
use App\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The company's own connectors: a remote MCP server (all its tools) or an
 * HTTP action, each becoming capabilities agents can be given.
 */
class ConnectorController extends Controller
{
    public function store(Request $request, CapabilityCatalog $catalog): RedirectResponse
    {
        Gate::authorize('manage-catalog');

        $connector = new Connector($this->validated($request));
        $connector->forceFill(['created_by_user_id' => $this->user($request)->id])->save();
        AuditLog::record($this->user($request), 'connector.created', ['key' => $connector->key, 'kind' => $connector->kind->value, 'url' => $connector->url], subject: $connector);

        return $this->sync($request, $connector, $catalog, 'Conector criado');
    }

    public function update(Request $request, Connector $connector, CapabilityCatalog $catalog): RedirectResponse
    {
        Gate::authorize('manage-catalog');

        $data = $this->validated($request, $connector);

        // A blank secret keeps the stored one; "clear_secret" removes it.
        if (blank($data['secret'] ?? null)) {
            unset($data['secret']);
        }

        if ($request->boolean('clear_secret')) {
            $data['secret'] = null;
        }

        $connector->update($data);
        AuditLog::record($this->user($request), 'connector.updated', ['key' => $connector->key, 'url' => $connector->url, 'is_active' => $connector->is_active], subject: $connector);

        return $this->sync($request, $connector, $catalog, 'Conector guardado');
    }

    public function refresh(Request $request, Connector $connector, CapabilityCatalog $catalog): RedirectResponse
    {
        Gate::authorize('manage-catalog');

        return $this->sync($request, $connector, $catalog, 'Ferramentas actualizadas');
    }

    public function destroy(Request $request, Connector $connector): RedirectResponse
    {
        Gate::authorize('manage-catalog');

        AuditLog::record($this->user($request), 'connector.deleted', ['key' => $connector->key, 'url' => $connector->url]);
        $connector->delete();

        return back()->with('success', 'Conector apagado, com as capacidades dele.');
    }

    private function sync(Request $request, Connector $connector, CapabilityCatalog $catalog, string $done): RedirectResponse
    {
        try {
            $count = $catalog->syncConnector($connector, $this->user($request));
        } catch (ConnectorException $e) {
            return back()->with('error', "{$done}, mas não foi possível ler as ferramentas: {$e->getMessage()}");
        }

        return back()->with('success', "{$done}: {$count} capacidade(s).");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Connector $connector = null): array
    {
        $key = TenantRule::unique('connectors', 'key');

        if ($connector !== null) {
            $key->ignore($connector->id);
        }

        $data = $request->validate([
            'key' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9][a-z0-9-]*$/', $key],
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

        $data['input_schema'] = $this->schema($data['input_schema'] ?? null, $data['kind'] === ConnectorKind::Http->value);

        return $data;
    }

    /**
     * The HTTP action's arguments, as a JSON Schema object the admin pastes.
     *
     * @return array<string, mixed>|null
     */
    private function schema(?string $json, bool $required): ?array
    {
        if (blank($json)) {
            return $required ? ['type' => 'object', 'properties' => new \stdClass] : null;
        }

        $schema = json_decode((string) $json, true);

        if (! is_array($schema) || ($schema['type'] ?? null) !== 'object') {
            throw ValidationException::withMessages(['input_schema' => 'Tem de ser um JSON Schema com "type": "object".']);
        }

        return $schema;
    }
}
