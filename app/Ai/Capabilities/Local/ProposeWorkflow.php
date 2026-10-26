<?php

namespace App\Ai\Capabilities\Local;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Capabilities\LocalCapability;
use App\Enums\AgentStatus;
use App\Enums\EmailCategory;
use App\Enums\WorkflowStatus;
use App\Enums\WorkflowTrigger;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\Workflow;
use App\Tasks\TaskThread;
use App\Workflows\WorkflowDrafting;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * The Chief of Staff suggests a flow (docs/DECISOES.md, "Chief of Staff sugere
 * fluxos"): the same drafting as «Descreva o fluxo» in the editor, saved as a
 * draft that does nothing until a person who manages agents opens it in the
 * editor, reviews the blocks and activates it.
 */
final class ProposeWorkflow extends LocalCapability
{
    public function __construct(private readonly WorkflowDrafting $drafting) {}

    public function key(): string
    {
        return 'workflows.propose';
    }

    public function name(): string
    {
        return 'Sugerir fluxo de trabalho';
    }

    public function description(): string
    {
        return 'Desenha um fluxo de trabalho a partir de uma descrição (o que o desencadeia, quem trata, os passos) e guarda-o como rascunho, que não faz nada até uma pessoa o rever no editor e o activar. Devolve a ligação para o editor. Não inventes regras, valores nem aprovações: o que a pessoa não disse, fica nas notas do rascunho.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'description' => $schema->string()->description('O fluxo em linguagem corrente: quando começa, quem trata, os passos, quem aprova e quando se pára.')->required(),
            'email_category' => $schema->string()->enum(array_column(EmailCategory::cases(), 'value'))->description('O tipo de email que o desencadeia, se começa com um email.'),
            'trigger' => $schema->string()->enum(array_column(WorkflowTrigger::cases(), 'value'))->description('email ou schedule (por agenda).'),
            'agent' => $schema->string()->description('Chave do agente que trata (ex.: client_manager), se já se sabe.'),
            'why' => $schema->string()->description('Porque sugeres este fluxo (o que viste: volume, trabalho repetido, pedido da pessoa).'),
        ];
    }

    public function precheck(array $arguments, CapabilityContext $context): ?string
    {
        $category = EmailCategory::tryFrom((string) ($arguments['email_category'] ?? ''));
        $active = $category !== null ? Workflow::activeFor($category) : null;

        return $active !== null
            ? "Já há um fluxo activo para «{$category->label()}»: «{$active->name}» (/settings/workflows/{$active->id}/edit). Sugere mudanças a esse em vez de um novo."
            : null;
    }

    public function execute(array $arguments, CapabilityContext $context): CapabilityResult
    {
        $description = trim((string) ($arguments['description'] ?? ''));

        if ($description === '') {
            return CapabilityResult::error('Falta a descrição do fluxo.');
        }

        $category = EmailCategory::tryFrom((string) ($arguments['email_category'] ?? ''));
        $trigger = WorkflowTrigger::tryFrom((string) ($arguments['trigger'] ?? ''));
        $agent = filled($arguments['agent'] ?? null)
            ? Agent::query()->where('status', AgentStatus::Active)->where(fn ($q) => $q->where('key', $arguments['agent'])->orWhere('name', $arguments['agent']))->first()
            : null;

        $draft = $this->drafting->draft($description, $agent, $category, $trigger);
        $person = $context->run->requestedBy ?? $context->agent->reportsTo;

        $workflow = Workflow::query()->create([
            'name' => $draft['name'],
            'description' => trim($draft['description']."\n\nSugerido por {$context->agent->name}".(filled($arguments['why'] ?? null) ? ': '.trim((string) $arguments['why']) : '.')),
            'trigger' => $draft['trigger'],
            'email_category' => $draft['email_category'],
            'agent_id' => $draft['agent_id'],
            'graph' => $draft['graph'],
            'status' => WorkflowStatus::Draft,
            'created_by_user_id' => $person?->id,
        ]);

        AuditLog::record($context->agent, 'workflow.proposed', ['workflow_id' => $workflow->id], subject: $workflow);

        $link = "/settings/workflows/{$workflow->id}/edit";

        if ($context->run->task !== null) {
            app(TaskThread::class)->note($context->run->task, "Fluxo sugerido (rascunho): [{$workflow->name}]({$link}). Reveja os blocos e active-o no editor.", $context->run);
        }

        return CapabilityResult::data([
            'workflow' => $workflow->name,
            'status' => 'rascunho: não faz nada até uma pessoa o activar no editor',
            'link' => $link,
            'agent' => $workflow->agent?->name,
            'blocks' => count($draft['graph']['nodes']),
            'open_points' => $draft['notes'],
        ]);
    }
}
