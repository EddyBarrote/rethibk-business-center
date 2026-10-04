<?php

namespace App\Ai\Skills;

use App\Enums\AutonomyLevel;
use App\Enums\SkillSource;
use App\Erp\ErpGateway;
use App\Erp\ErpTool;
use App\Models\Skill;

/**
 * Keeps the current tenant's skills table in step with the local registry and
 * the tools the ERP exposes. A risk the super admin changed is never reset.
 */
final class SkillCatalog
{
    public function __construct(
        private readonly SkillRegistry $registry,
        private readonly ErpGateway $erp,
    ) {}

    public function syncLocal(): void
    {
        foreach ($this->registry->all() as $local) {
            $skill = Skill::query()->firstOrNew(['key' => $local->key()]);
            $isNew = ! $skill->exists;

            $skill->fill([
                'name' => $local->name(),
                'description' => $local->description(),
                'source' => SkillSource::Local,
                'is_mutating' => $local->isMutating(),
                'is_available' => true,
            ]);

            if ($isNew) {
                $skill->risk = $local->defaultRisk();
            }

            $skill->save();
        }
    }

    /**
     * Pull the ERP tool list over MCP. Throws ErpException when the ERP
     * cannot be reached; local skills are unaffected.
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

            $skill = Skill::query()->firstOrNew(['key' => $key]);
            $isNew = ! $skill->exists;

            $skill->fill([
                'name' => $tool->title ?: $tool->name,
                'description' => $tool->description,
                'source' => SkillSource::Mcp,
                'mcp_tool_name' => $tool->name,
                'input_schema' => $tool->inputSchema,
                'is_mutating' => ! $tool->readOnly,
                'is_available' => true,
            ]);

            if ($isNew) {
                $skill->risk = self::defaultErpRisk($tool);
            }

            $skill->save();
        }

        Skill::query()->where('source', SkillSource::Mcp)->whereNotIn('key', $seen)->update(['is_available' => false]);

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
