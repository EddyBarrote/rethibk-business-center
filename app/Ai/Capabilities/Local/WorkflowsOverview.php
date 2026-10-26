<?php

namespace App\Ai\Capabilities\Local;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Capabilities\LocalCapability;
use App\Enums\EmailCategory;
use App\Models\EmailMessage;
use App\Models\Workflow;
use App\Workflows\EmailRouter;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * What the Chief of Staff looks at before suggesting a flow (docs/DECISOES.md,
 * "Chief of Staff sugere fluxos"): how many emails of each kind came in, who
 * handles them, which have a flow, and the flows there are.
 */
final class WorkflowsOverview extends LocalCapability
{
    public function __construct(private readonly EmailRouter $router) {}

    public function key(): string
    {
        return 'workflows.overview';
    }

    public function name(): string
    {
        return 'Ver fluxos de trabalho';
    }

    public function description(): string
    {
        return 'Os tipos de email que chegaram nos últimos dias (quantos), quem trata cada um e se já tem fluxo activo, e a lista dos fluxos (activos, pausados e rascunhos). Usa antes de sugerir um fluxo com workflows.propose.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'days' => $schema->integer()->min(1)->max(180)->description('Quantos dias olhar para trás (por omissão 30).'),
        ];
    }

    public function execute(array $arguments, CapabilityContext $context): CapabilityResult
    {
        $days = max(1, min(180, (int) ($arguments['days'] ?? 30)));
        $counts = EmailMessage::query()->where('direction', 'inbound')->whereNotNull('classification')
            ->where('created_at', '>=', now()->subDays($days))
            ->selectRaw('classification, count(*) as total')->groupBy('classification')->pluck('total', 'classification');

        $kinds = [];

        foreach (EmailCategory::cases() as $category) {
            $route = $this->router->route($category);

            if ($route['agent'] === null && ! isset($counts[$category->value])) {
                continue;
            }

            $kinds[] = [
                'email_category' => $category->value,
                'label' => $category->label(),
                'emails' => (int) ($counts[$category->value] ?? 0),
                'handled_by' => $route['agent']?->name,
                'active_flow' => $route['workflow']?->name,
            ];
        }

        usort($kinds, fn (array $a, array $b) => $b['emails'] <=> $a['emails']);

        return CapabilityResult::data([
            'days' => $days,
            'email_kinds' => $kinds,
            'flows' => Workflow::query()->with('agent:id,name')->orderBy('name')->get()->map(fn (Workflow $workflow) => [
                'name' => $workflow->name,
                'status' => $workflow->status->label(),
                'trigger' => $workflow->trigger->label(),
                'email_category' => $workflow->email_category?->label(),
                'agent' => $workflow->agent?->name,
                'link' => "/settings/workflows/{$workflow->id}/edit",
            ])->all(),
        ]);
    }
}
