<?php

namespace App\Ai\Skills\Local;

use App\Ai\Skills\LocalSkill;
use App\Ai\Skills\SkillContext;
use App\Ai\Skills\SkillResult;
use App\Models\Report;
use App\Models\User;
use App\Support\Notifier;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;

/**
 * Saves a document for people to review (section 7.3, DraftReport): the
 * month-close pack, a quote comparison, a payroll check, a client sheet,
 * a pre-meeting brief. It never leaves the platform.
 */
final class DraftReport extends LocalSkill
{
    public const TYPES = [
        'month_close' => 'Fecho do mês',
        'margin_review' => 'Margens por projecto',
        'reconciliation' => 'Reconciliação bancária',
        'quote_comparison' => 'Mapa comparativo',
        'supplier_review' => 'Avaliação de fornecedores',
        'payroll_check' => 'Folha de salários',
        'attendance' => 'Assiduidade',
        'candidate_screening' => 'Triagem de candidaturas',
        'client_sheet' => 'Ficha de cliente',
        'meeting_brief' => 'Briefing de reunião',
        'tender_analysis' => 'Análise de concurso',
        'proposal' => 'Proposta',
        'other' => 'Outro',
    ];

    public function __construct(private readonly Notifier $notifier) {}

    public function key(): string
    {
        return 'reports.draft';
    }

    public function name(): string
    {
        return 'Redigir documento';
    }

    public function description(): string
    {
        return 'Guarda um documento em markdown para uma pessoa rever (fecho do mês, mapa comparativo, folha de salários, ficha de cliente, briefing de reunião, proposta...). Pode notificar quem tem de o rever.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->enum(array_keys(self::TYPES))->required(),
            'title' => $schema->string()->required(),
            'content' => $schema->string()->description('Markdown. Tabelas são bem-vindas.')->required(),
            'data' => $schema->object()->description('Números estruturados que suportam o documento (opcional).'),
            'subject_ref' => $schema->string()->description('Referência do assunto: id do cliente, do projecto, do pedido de cotação...'),
            'period_start' => $schema->string()->format('date'),
            'period_end' => $schema->string()->format('date'),
            'notify' => $schema->string()->description('Email da conta de quem deve rever; "chefia" para a chefia do agente.'),
        ];
    }

    public function execute(array $arguments, SkillContext $context): SkillResult
    {
        $data = Validator::make($arguments, [
            'type' => 'required|in:'.implode(',', array_keys(self::TYPES)),
            'title' => 'required|string|max:255',
            'content' => 'required|string|max:200000',
            'data' => 'nullable|array',
            'subject_ref' => 'nullable|string|max:255',
            'period_start' => 'nullable|date',
            'period_end' => 'nullable|date',
            'notify' => 'nullable|string|max:255',
        ])->validate();

        $report = Report::query()->create([
            'agent_id' => $context->agent->id,
            'agent_run_id' => $context->run->id,
            'type' => $data['type'],
            'title' => $data['title'],
            'content' => $data['content'],
            'data' => $data['data'] ?? null,
            'subject_ref' => $data['subject_ref'] ?? null,
            'period_start' => $data['period_start'] ?? null,
            'period_end' => $data['period_end'] ?? null,
            'status' => 'draft',
        ]);

        $reviewer = match (true) {
            blank($data['notify'] ?? null) => null,
            $data['notify'] === 'chefia' => $context->agent->reportsTo,
            default => User::query()->where('email', mb_strtolower((string) $data['notify']))->where('is_active', true)->first(),
        };

        if ($reviewer !== null) {
            $this->notifier->notify($reviewer, self::TYPES[$data['type']].': '.$report->title, 'Documento pronto para rever.', "/reports/{$report->id}", $context->agent->name);
        }

        return SkillResult::data(['report_id' => $report->id, 'link' => "/reports/{$report->id}", 'notified' => $reviewer?->name]);
    }
}
