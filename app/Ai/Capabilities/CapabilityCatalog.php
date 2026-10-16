<?php

namespace App\Ai\Capabilities;

use App\Connectors\ConnectorException;
use App\Connectors\ConnectorGateway;
use App\Enums\AutonomyLevel;
use App\Enums\CapabilitySource;
use App\Enums\ConnectorKind;
use App\Enums\Scope;
use App\Erp\ErpDialect;
use App\Erp\ErpGateway;
use App\Erp\ErpTool;
use App\Erp\Perfex\PerfexToolMap;
use App\Models\Agent;
use App\Models\Capability;
use App\Models\Connector;
use App\Models\PlatformConnector;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Keeps the current tenant's capabilities table in step with the local registry and
 * the tools the ERP exposes. A risk the super admin changed is never reset.
 */
final class CapabilityCatalog
{
    public function __construct(
        private readonly CapabilityRegistry $registry,
        private readonly ErpGateway $erp,
        private readonly ConnectorGateway $connectors,
    ) {}

    public function syncLocal(): void
    {
        foreach ($this->registry->all() as $local) {
            $capability = Capability::query()->firstOrNew(['key' => $local->key()]);
            $isNew = ! $capability->exists;

            $capability->fill([
                'name' => $local->name(),
                'description' => $local->description(),
                'source' => CapabilitySource::Local,
                'scope' => Scope::Global,
                'is_mutating' => $local->isMutating(),
                'is_available' => true,
            ]);

            if ($isNew) {
                $capability->risk = $local->defaultRisk();
            }

            $capability->save();
        }

        $this->grantOnByDefault();
    }

    /**
     * Switches the on-by-default capabilities on for every agent, once per
     * tenant: an admin who turns one off for an agent later keeps it off.
     */
    private function grantOnByDefault(): void
    {
        $pending = Capability::query()->whereIn('key', CapabilityRegistry::ON_BY_DEFAULT)->whereNull('granted_to_agents_at')->get();

        if ($pending->isEmpty()) {
            return;
        }

        Agent::query()->each(fn (Agent $agent) => $agent->capabilities()->syncWithoutDetaching(
            $pending->mapWithKeys(fn (Capability $capability) => [$capability->id => ['enabled' => true]])->all(),
        ));

        Capability::query()->whereKey($pending->modelKeys())->update(['granted_to_agents_at' => now()]);
    }

    /**
     * Pull the ERP tool list over MCP. Throws ErpException when the ERP
     * cannot be reached; local capabilities are unaffected.
     *
     * @return int the number of ERP tools now available
     */
    public function syncErp(): int
    {
        $tools = $this->erp->tools();
        $dialect = $this->erp->dialect();
        $seen = [];

        foreach ($tools as $tool) {
            $key = self::erpKey($tool->name);
            $seen[] = $key;

            $capability = Capability::query()->firstOrNew(['key' => $key]);
            $isNew = ! $capability->exists;

            $capability->fill([
                'name' => $tool->title ?: $tool->name,
                'description' => $tool->description,
                'source' => CapabilitySource::Mcp,
                'scope' => Scope::Global,
                'mcp_tool_name' => $tool->name,
                'input_schema' => $tool->inputSchema,
                'is_mutating' => ! $tool->readOnly,
                'is_available' => true,
            ]);

            if ($isNew) {
                $capability->risk = self::defaultErpRisk($tool, $dialect);
            }

            $capability->save();
        }

        Capability::query()->where('source', CapabilitySource::Mcp)->whereNotIn('key', $seen)->update(['is_available' => false]);

        if ($dialect === ErpDialect::Perfex) {
            $this->grantPerfexReads();
        }

        return count($tools);
    }

    /**
     * An agent set up with contract tools ("erp.crm.search_accounts") gets the
     * Perfex reads that do the same job ("erp.list_customers"). Perfex writes
     * change the live ERP, so an admin gives those to agents explicitly.
     */
    private function grantPerfexReads(): void
    {
        $reads = Capability::query()->where('source', CapabilitySource::Mcp)->where('is_available', true)->where('is_mutating', false)->pluck('id', 'key');

        Agent::query()->with('capabilities')->get()->each(function (Agent $agent) use ($reads): void {
            $wanted = $agent->capabilities
                ->filter(fn (Capability $capability) => $capability->source === CapabilitySource::Mcp && ! $capability->is_available)
                ->flatMap(fn (Capability $capability) => PerfexToolMap::capabilitiesFor($capability->key))
                ->map(fn (string $key) => $reads[$key] ?? null)
                ->filter()
                ->unique()
                ->diff($agent->capabilities->pluck('id'))
                ->values();

            if ($wanted->isNotEmpty()) {
                $agent->capabilities()->syncWithoutDetaching(array_fill_keys($wanted->all(), ['enabled' => true]));
            }
        });
    }

    /**
     * Discover a connector's tools and copy them into the current tenant's
     * catalogue: the tenant's own connectors, or a global one the tenant
     * activates. Tools that disappeared become unavailable. Throws
     * ConnectorException when the connector cannot be reached.
     *
     * @return int the number of tools now available
     */
    public function syncConnector(PlatformConnector|Connector $connector, ?Model $actor = null): int
    {
        try {
            $tools = $this->connectors->discover($connector, $actor);
        } catch (ConnectorException $e) {
            $connector->forceFill(['last_error' => Str::limit($e->getMessage(), 250), 'last_synced_at' => now()])->save();

            throw $e;
        }

        $global = $connector instanceof PlatformConnector;
        $link = $global ? 'platform_connector_id' : 'connector_id';
        $seen = [];

        foreach ($tools as $tool) {
            $key = self::connectorKey($connector, $tool->name);
            $seen[] = $key;

            $capability = Capability::query()->firstOrNew(['key' => $key]);
            $isNew = ! $capability->exists;
            $mutating = $connector->kind === ConnectorKind::Http ? $connector->is_mutating : ! $tool->readOnly;

            $capability->fill([
                'name' => $tool->title ?: $tool->name,
                'description' => $tool->description ?: $connector->description,
                'source' => CapabilitySource::Connector,
                'scope' => $global ? Scope::Global : Scope::Tenant,
                'mcp_tool_name' => $tool->name,
                $link => $connector->id,
                'input_schema' => $tool->inputSchema,
                'is_mutating' => $mutating,
                'is_available' => true,
                'is_enabled' => true,
            ]);

            if ($isNew) {
                // Someone else's service: writes wait for a person until an admin decides otherwise.
                $capability->risk = $mutating ? AutonomyLevel::ExecuteAndReport : AutonomyLevel::Observe;
            }

            $capability->save();
        }

        Capability::query()->where($link, $connector->id)->whereNotIn('key', $seen)->update(['is_available' => false]);

        if (! $global) {
            $connector->forceFill(['tools' => array_map(fn (ErpTool $tool) => $tool->summary(), $tools), 'last_error' => null, 'last_synced_at' => now()])->save();
        }

        return count($tools);
    }

    /**
     * A tenant switches a global connector off: its capabilities stay (with
     * their risk) but no agent can use them until it is activated again.
     */
    public function deactivate(PlatformConnector $connector): void
    {
        Capability::query()->where('platform_connector_id', $connector->id)->update(['is_enabled' => false]);
    }

    public static function connectorKey(PlatformConnector|Connector $connector, string $tool): string
    {
        $tool = (string) preg_replace('/[^a-z0-9_]+/', '_', Str::lower($tool));

        return $connector->kind === ConnectorKind::Http ? $connector->capabilityPrefix() : $connector->capabilityPrefix().'.'.trim($tool, '_');
    }

    public static function erpKey(string $tool): string
    {
        return 'erp.'.$tool;
    }

    /**
     * The contract's writes only create drafts, so agents may make them within
     * limits. A Perfex write changes the live ERP: it waits for a person
     * unless an admin decides otherwise (docs/DECISOES.md, ERP real).
     */
    private static function defaultErpRisk(ErpTool $tool, ErpDialect $dialect): AutonomyLevel
    {
        return match (true) {
            $tool->readOnly => AutonomyLevel::Observe,
            $dialect === ErpDialect::Perfex => AutonomyLevel::ExecuteAndReport,
            default => AutonomyLevel::ExecuteWithinLimits,
        };
    }
}
