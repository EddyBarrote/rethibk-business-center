<?php

namespace App\Ai\Capabilities;

use App\Enums\AutonomyLevel;
use App\Enums\CapabilitySource;
use App\Erp\ErpGateway;
use App\Erp\ErpTool;
use App\Models\Capability;

/**
 * Keeps the current tenant's capabilities table in step with the local registry and
 * the tools the ERP exposes. A risk the super admin changed is never reset.
 */
final class CapabilityCatalog
{
    public function __construct(
        private readonly CapabilityRegistry $registry,
        private readonly ErpGateway $erp,
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
                'is_mutating' => $local->isMutating(),
                'is_available' => true,
            ]);

            if ($isNew) {
                $capability->risk = $local->defaultRisk();
            }

            $capability->save();
        }
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
                'mcp_tool_name' => $tool->name,
                'input_schema' => $tool->inputSchema,
                'is_mutating' => ! $tool->readOnly,
                'is_available' => true,
            ]);

            if ($isNew) {
                $capability->risk = self::defaultErpRisk($tool);
            }

            $capability->save();
        }

        Capability::query()->where('source', CapabilitySource::Mcp)->whereNotIn('key', $seen)->update(['is_available' => false]);

        return count($tools);
    }

    public static function erpKey(string $tool): string
    {
        return 'erp.'.$tool;
    }

    private static function defaultErpRisk(ErpTool $tool): AutonomyLevel
    {
        return $tool->readOnly ? AutonomyLevel::Observe : AutonomyLevel::ExecuteWithinLimits;
    }
}
