<?php

namespace App\Workflows;

use App\Ai\Runs\AgentDirectory;
use App\Enums\EmailCategory;
use App\Models\Agent;
use App\Models\EmailRoute;
use App\Models\User;
use App\Models\Workflow;

/**
 * Who handles a kind of email after triage (docs/DECISOES.md, "Fluxos de
 * trabalho"): the agent of the active flow for it; else the agent of the rule
 * in Definições › Regras de email; else the default for the category
 * (EmailCategory::handlerRole()). A rule without an agent keeps the email with
 * triage and the person it was routed to.
 */
final class EmailRouter
{
    public function __construct(private readonly AgentDirectory $agents) {}

    /**
     * @return array{agent: Agent|null, workflow: Workflow|null, fallback: User|null, source: 'workflow'|'rule'|'default'|'none'}
     */
    public function route(EmailCategory $category): array
    {
        $route = EmailRoute::query()->with(['agent', 'fallbackUser'])->where('category', $category)->first();
        $workflow = Workflow::activeFor($category);
        $fallback = $route?->fallbackUser;

        if ($workflow !== null && $workflow->agent?->isActive()) {
            return ['agent' => $workflow->agent, 'workflow' => $workflow, 'fallback' => $workflow->fallbackUser ?? $fallback, 'source' => 'workflow'];
        }

        if ($route !== null) {
            $agent = $route->agent?->isActive() ? $route->agent : null;

            return ['agent' => $agent, 'workflow' => null, 'fallback' => $fallback, 'source' => $agent !== null ? 'rule' : 'none'];
        }

        $role = $category->handlerRole();
        $agent = $role !== null ? $this->agents->forRole($role) : null;

        return ['agent' => $agent, 'workflow' => null, 'fallback' => null, 'source' => $agent !== null ? 'default' : 'none'];
    }
}
