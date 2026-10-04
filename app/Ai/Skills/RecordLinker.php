<?php

namespace App\Ai\Skills;

use App\Models\EmailMessage;
use App\Models\Skill;
use App\Models\Tender;

/**
 * Keeps platform records pointing at what an agent created in the ERP: a lead
 * created while triaging an email is linked to that email (E03).
 */
final class RecordLinker
{
    /**
     * @param  array<string, mixed>|null  $data
     */
    public function afterErpWrite(Skill $skill, ?array $data, SkillContext $context): void
    {
        if ($skill->mcp_tool_name !== 'leads.create' || ! is_string($leadId = $data['lead']['id'] ?? null)) {
            return;
        }

        $source = $context->run->triggerSource;

        if ($source instanceof EmailMessage && $source->erp_lead_id === null) {
            $source->forceFill(['erp_lead_id' => $leadId])->save();
            Tender::query()->where('email_message_id', $source->id)->whereNull('erp_lead_id')->update(['erp_lead_id' => $leadId]);
        }

        if ($source instanceof Tender && $source->erp_lead_id === null) {
            $source->forceFill(['erp_lead_id' => $leadId])->save();
        }
    }
}
