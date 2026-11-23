<?php

namespace App\Audit;

use App\Enums\ActorType;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\Capability;
use App\Models\PlatformAdmin;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

/**
 * Audit entries as Definições › Auditoria and its CSV show them: who (by
 * name), the action in Portuguese, the result, the object with its link, and
 * the details, withheld on confidential entries for whoever may not manage
 * people (docs/DECISOES.md, "Auditoria pesquisável e exportável").
 */
final class AuditPresenter
{
    /** What a viewer without «Gerir pessoas e papéis» reads instead of the details. */
    public const WITHHELD = 'Detalhes com dados de pessoal (salários, contratos, documentos de identificação ou processos disciplinares): só quem tem a permissão «Gerir pessoas e papéis» os vê.';

    /** The CSV's columns. */
    public const CSV_HEADERS = ['Data e hora', 'Quem (tipo)', 'Quem', 'Acção', 'Código da acção', 'Resultado', 'Objecto (tipo)', 'Objecto', 'ID do objecto', 'IP', 'Detalhes'];

    /** @var array<string, string>|null */
    private ?array $capabilityNames = null;

    public function __construct(
        private readonly ConfidentialEntries $confidential,
        private readonly bool $readsConfidential,
    ) {}

    /**
     * @param  Collection<int, AuditLog>  $logs
     * @return list<array{id: int, created_at: string, actor: array{type: string, type_label: string, id: int|null, name: string}, action: string, action_label: string, result: string, result_label: string, subject: array{type: string|null, type_label: string, id: int|string, name: string|null, url: string|null}|null, details: array<string, mixed>|null, withheld: bool, ip: string|null}>
     */
    public function rows(Collection $logs): array
    {
        $hidden = $this->readsConfidential ? [] : $this->confidential->among($logs->map(fn (AuditLog $log) => $log->id)->values()->all());
        $actors = $this->actorNames($logs);
        $subjects = $this->subjects($logs);

        return $logs->map(function (AuditLog $log) use ($hidden, $actors, $subjects) {
            $withheld = isset($hidden[$log->id]);

            return [
                'id' => $log->id,
                'created_at' => $log->created_at->toIso8601String(),
                'actor' => $this->actor($log, $actors),
                'action' => $log->action,
                'action_label' => AuditVocabulary::action($log->action, $this->capabilityNames()),
                'result' => $log->result->value,
                'result_label' => AuditVocabulary::result($log->result),
                'subject' => $this->subject($log, $subjects, $withheld),
                'details' => $withheld ? null : ($log->payload ?: null),
                'withheld' => $withheld,
                'ip' => $log->ip,
            ];
        })->values()->all();
    }

    /**
     * The same entries as CSV lines, the time in the company's time zone.
     *
     * @param  Collection<int, AuditLog>  $logs
     * @return list<list<string>>
     */
    public function csv(Collection $logs): array
    {
        $timezone = (string) config('agents.schedule_timezone');
        $times = $logs->mapWithKeys(fn (AuditLog $log) => [$log->id => $log->created_at->copy()->setTimezone($timezone)->format('Y-m-d H:i:s')]);

        return array_map(fn (array $row) => array_map(self::cell(...), [
            $times[$row['id']],
            $row['actor']['type_label'],
            $row['actor']['name'],
            $row['action_label'],
            $row['action'],
            $row['result_label'],
            $row['subject']['type_label'] ?? '',
            $row['subject']['name'] ?? '',
            (string) ($row['subject']['id'] ?? ''),
            (string) $row['ip'],
            $row['withheld'] ? self::WITHHELD : ($row['details'] === null ? '' : (string) json_encode($row['details'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ]), $this->rows($logs));
    }

    /**
     * A cell a spreadsheet will not run: text that starts like a formula
     * gets a leading apostrophe.
     */
    public static function cell(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }

    /**
     * @param  array{users: Collection<int, string>, agents: Collection<int, string>, platform: Collection<int, string>}  $names
     * @return array{type: string, type_label: string, id: int|null, name: string}
     */
    private function actor(AuditLog $log, array $names): array
    {
        $label = AuditVocabulary::actorType($log->actor_type);
        $id = $log->actor_id;
        $name = match ($log->actor_type) {
            ActorType::User => $names['users'][$id] ?? null,
            ActorType::Agent => $names['agents'][$id] ?? null,
            ActorType::PlatformAdmin => $names['platform'][$id] ?? null,
            ActorType::System => 'Sistema',
        };

        return [
            'type' => $log->actor_type->value,
            'type_label' => $label,
            'id' => $id,
            'name' => $name ?? ($id !== null ? "{$label} #{$id}" : $label),
        ];
    }

    /**
     * @param  array<string, array<array-key, Model>>  $subjects
     * @return array{type: string|null, type_label: string, id: int|string, name: string|null, url: string|null}|null
     */
    private function subject(AuditLog $log, array $subjects, bool $withheld): ?array
    {
        $type = $log->getAttribute('subject_type');
        $id = $log->getAttribute('subject_id');

        if (! is_string($type) || $id === null) {
            return null;
        }

        $class = Relation::getMorphedModel($type) ?? $type;
        $model = $subjects[$type][$id] ?? null;
        // On a confidential entry only the agent keeps its name: what it touched may itself be personal.
        $named = $model !== null && (! $withheld || $model instanceof Agent);

        return [
            'type' => AuditVocabulary::subjectKey($class),
            'type_label' => AuditVocabulary::subjectLabel($class),
            'id' => is_numeric($id) ? (int) $id : (string) $id,
            'name' => $named ? AuditVocabulary::subjectName($model) : null,
            'url' => $named ? AuditVocabulary::subjectUrl($class, $id) : null,
        ];
    }

    /**
     * @param  Collection<int, AuditLog>  $logs
     * @return array{users: Collection<int, string>, agents: Collection<int, string>, platform: Collection<int, string>}
     */
    private function actorNames(Collection $logs): array
    {
        $ids = fn (ActorType $type) => $logs->where('actor_type', $type)->pluck('actor_id')->filter()->unique()->values()->all();

        return [
            'users' => User::query()->whereKey($ids(ActorType::User))->pluck('name', 'id'),
            'agents' => Agent::query()->whereKey($ids(ActorType::Agent))->pluck('name', 'id'),
            'platform' => PlatformAdmin::query()->whereKey($ids(ActorType::PlatformAdmin))->pluck('name', 'id'),
        ];
    }

    /**
     * The objects of these entries, by stored type and id. A type the
     * platform no longer has, or an object since removed, has no name.
     *
     * @param  Collection<int, AuditLog>  $logs
     * @return array<string, array<array-key, Model>>
     */
    private function subjects(Collection $logs): array
    {
        $byType = [];

        foreach ($logs->groupBy(fn (AuditLog $log) => (string) $log->getAttribute('subject_type')) as $type => $group) {
            $class = Relation::getMorphedModel($type) ?? $type;

            if ($type === '' || ! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            $ids = $group->pluck('subject_id')->filter()->unique()->values()->all();
            $byType[$type] = $class::query()->whereKey($ids)->get()->getDictionary();
        }

        return $byType;
    }

    /**
     * @return array<string, string>
     */
    private function capabilityNames(): array
    {
        return $this->capabilityNames ??= Capability::query()->pluck('name', 'key')->map(fn ($name) => (string) $name)->all();
    }
}
