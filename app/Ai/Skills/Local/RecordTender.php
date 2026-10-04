<?php

namespace App\Ai\Skills\Local;

use App\Ai\Skills\LocalSkill;
use App\Ai\Skills\SkillContext;
use App\Ai\Skills\SkillResult;
use App\Enums\TenderStatus;
use App\Models\EmailMessage;
use App\Models\Tender;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Records a tender found in an email or on a monitored portal, or updates
 * its status. Platform data only (N0).
 */
final class RecordTender extends LocalSkill
{
    public function key(): string
    {
        return 'tenders.record';
    }

    public function name(): string
    {
        return 'Registar concurso';
    }

    public function description(): string
    {
        return 'Regista um concurso (título, entidade, referência, prazo, ligação) ou actualiza o estado de um já registado.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'tender_id' => $schema->integer()->description('Para actualizar um concurso existente.'),
            'title' => $schema->string(),
            'entity' => $schema->string()->description('Entidade contratante.'),
            'reference' => $schema->string(),
            'url' => $schema->string(),
            'deadline' => $schema->string()->description('Prazo de submissão, AAAA-MM-DD HH:MM.'),
            'summary' => $schema->string(),
            'status' => $schema->string()->enum(array_column(TenderStatus::cases(), 'value')),
            'email_id' => $schema->integer(),
            'source' => $schema->string()->description('Portal ou origem; por omissão "email".'),
        ];
    }

    public function execute(array $arguments, SkillContext $context): SkillResult
    {
        $data = Validator::make($arguments, [
            'tender_id' => 'nullable|integer',
            'title' => 'required_without:tender_id|nullable|string|max:255',
            'entity' => 'nullable|string|max:255',
            'reference' => 'nullable|string|max:255',
            'url' => 'nullable|url|max:2000',
            'deadline' => 'nullable|date',
            'summary' => 'nullable|string|max:5000',
            'status' => ['nullable', Rule::enum(TenderStatus::class)],
            'email_id' => 'nullable|integer',
            'source' => 'nullable|string|max:255',
        ])->validate();

        $tender = isset($data['tender_id']) ? Tender::query()->find($data['tender_id']) : null;

        if (isset($data['tender_id']) && $tender === null) {
            return SkillResult::error('concurso não encontrado.');
        }

        $email = isset($data['email_id']) ? EmailMessage::query()->find($data['email_id']) : null;
        $urlHash = filled($data['url'] ?? null) ? hash('sha256', (string) $data['url']) : null;

        $tender ??= ($urlHash !== null ? Tender::query()->where('url_hash', $urlHash)->first() : null) ?? new Tender([
            'source' => $data['source'] ?? ($email ? 'email' : 'agente'),
            'status' => TenderStatus::New,
        ]);

        $tender->fill(array_filter([
            'title' => $data['title'] ?? null,
            'entity' => $data['entity'] ?? null,
            'reference' => $data['reference'] ?? null,
            'url' => $data['url'] ?? null,
            'url_hash' => $urlHash,
            'summary' => $data['summary'] ?? null,
            'status' => $data['status'] ?? null,
            'email_message_id' => $email?->id,
            'erp_lead_id' => $email?->erp_lead_id,
            'deadline_at' => isset($data['deadline']) ? Carbon::parse($data['deadline'], (string) config('agents.schedule_timezone'))->utc() : null,
        ], fn ($value) => $value !== null))->save();

        return SkillResult::data(['tender_id' => $tender->id, 'status' => $tender->status->value, 'deadline_at' => $tender->deadline_at?->toIso8601String()]);
    }
}
