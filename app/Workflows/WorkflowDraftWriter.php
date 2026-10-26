<?php

namespace App\Workflows;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

/**
 * WorkflowDrafter's instructions, answered as plain JSON text so it can be
 * streamed: the describe page shows each step as the model writes it
 * (structured output does not stream). WorkflowDrafting falls back to
 * WorkflowDrafter when the text is not valid JSON.
 */
final class WorkflowDraftWriter implements Agent
{
    use Promptable;

    public function __construct(private readonly string $context) {}

    public function instructions(): string
    {
        return (new WorkflowDrafter($this->context))->instructions().<<<'TXT'


        Responde só com um objecto JSON, sem texto à volta nem blocos de código, com as chaves por esta ordem:
        {"name": "...", "summary": "...", "trigger": "email|schedule", "email_category": "... ou null", "agent": "chave", "notes": ["..."],
         "steps": [{"ref": "...", "type": "...", "label": "...", "instruction": "...", "capability": "...", "agent": "...", "items": "...", "until": "...", "max": 5, "hours": 24, "inside": "...", "next": "...", "yes": "...", "no": "...", "approved": "...", "rejected": "..."}]}
        Em cada passo, "label" vem logo a seguir a "type". Omite as chaves que não se aplicam.
        TXT;
    }
}
