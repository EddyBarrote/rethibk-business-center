<?php

namespace App\Audit;

use App\Enums\ActorType;
use App\Enums\AuditResult;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * What Definições › Auditoria is narrowed to, as it travels in the address,
 * so the page and its export read exactly the same set (docs/DECISOES.md,
 * "Auditoria pesquisável e exportável").
 *
 * - from / to: days, inclusive, in the company's time zone.
 * - actor: "user:12", "agent:5", "system" or "platform".
 * - action: an exact action ("erp.tool_call") or a family, written with a
 *   final "." or ".*" ("erp." and "erp.*" are every ERP action).
 * - result: ok, denied, error.
 * - subject: the kind of object (AuditVocabulary), e.g. "task".
 * - q: words in the action or anywhere in the details.
 */
final class AuditFilters
{
    private function __construct(
        public readonly ?string $from,
        public readonly ?string $to,
        public readonly ?string $actor,
        public readonly ?string $action,
        public readonly ?AuditResult $result,
        public readonly ?string $subject,
        public readonly string $q,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'actor' => ['nullable', 'string', 'regex:/^(system|platform|(user|agent):\d{1,18})$/'],
            'action' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9_.]+\*?$/'],
            'result' => ['nullable', Rule::enum(AuditResult::class)],
            'subject' => ['nullable', Rule::in(array_keys(AuditVocabulary::subjectClasses()))],
            'q' => ['nullable', 'string', 'max:200'],
        ]);

        return new self(
            $data['from'] ?? null,
            $data['to'] ?? null,
            $data['actor'] ?? null,
            $data['action'] ?? null,
            isset($data['result']) ? AuditResult::from($data['result']) : null,
            $data['subject'] ?? null,
            trim((string) ($data['q'] ?? '')),
        );
    }

    /**
     * The filters that are on, as the page and the export record show them.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return array_filter([
            'from' => $this->from,
            'to' => $this->to,
            'actor' => $this->actor,
            'action' => $this->action,
            'result' => $this->result?->value,
            'subject' => $this->subject,
            'q' => $this->q,
        ], fn (?string $value) => $value !== null && $value !== '');
    }

    /** A family of actions ("erp."), or null for one exact action or none. */
    public function actionPrefix(): ?string
    {
        if ($this->action === null) {
            return null;
        }

        $action = rtrim($this->action, '*');

        return str_ends_with($action, '.') ? $action : null;
    }

    /**
     * The tenant's entries narrowed to these filters. Who may read what in
     * the details is ConfidentialEntries' part: the free-text search goes
     * through it, so a word only in hidden details finds nothing.
     *
     * @param  Builder<AuditLog>  $query
     * @return Builder<AuditLog>
     */
    public function apply(Builder $query, ConfidentialEntries $confidential, bool $readsConfidential): Builder
    {
        $timezone = (string) config('agents.schedule_timezone');

        if ($this->from !== null) {
            $query->where('created_at', '>=', Carbon::parse($this->from, $timezone)->startOfDay()->utc());
        }

        if ($this->to !== null) {
            $query->where('created_at', '<', Carbon::parse($this->to, $timezone)->addDay()->startOfDay()->utc());
        }

        if ($this->actor !== null) {
            [$type, $id] = array_pad(explode(':', $this->actor, 2), 2, null);

            $query->where('actor_type', match ($type) {
                'user' => ActorType::User,
                'agent' => ActorType::Agent,
                'platform' => ActorType::PlatformAdmin,
                default => ActorType::System,
            });

            if ($id !== null) {
                $query->where('actor_id', (int) $id);
            }
        }

        if ($this->action !== null) {
            $prefix = $this->actionPrefix();
            $prefix !== null
                ? self::whereStartsWith($query, 'action', $prefix)
                : $query->where('action', rtrim($this->action, '*'));
        }

        if ($this->result !== null) {
            $query->where('result', $this->result);
        }

        if ($this->subject !== null) {
            $query->where('subject_type', Relation::getMorphAlias(AuditVocabulary::subjectClasses()[$this->subject]));
        }

        if ($this->q !== '') {
            $this->search($query, $confidential, $readsConfidential);
        }

        return $query;
    }

    /**
     * The words in the action (its key or its name in Portuguese) or in the
     * details. Details are stored as JSON: a word with accents may be stored
     * escaped ("\u00e7" for "ç"), so both spellings are tried.
     *
     * @param  Builder<AuditLog>  $query
     */
    private function search(Builder $query, ConfidentialEntries $confidential, bool $readsConfidential): void
    {
        $needle = '%'.self::escapeLike(mb_strtolower($this->q)).'%';
        $escaped = trim((string) json_encode($this->q), '"');
        $escapedNeedle = '%'.self::escapeLike(mb_strtolower($escaped)).'%';

        $named = array_keys(array_filter(AuditVocabulary::ACTIONS, fn (string $label) => str_contains(mb_strtolower($label), mb_strtolower($this->q))));

        $grammar = $query->getQuery()->getGrammar();
        $action = $grammar->wrap('action');
        $payload = $grammar->wrap('payload');

        $query->where(function (Builder $q) use ($needle, $escapedNeedle, $named, $confidential, $readsConfidential, $action, $payload) {
            $q->whereRaw("lower({$action}) like ? escape '!'", [$needle])
                ->when($named !== [], fn (Builder $w) => $w->orWhereIn('action', $named))
                ->orWhere(function (Builder $details) use ($needle, $escapedNeedle, $confidential, $readsConfidential, $payload) {
                    $details->where(fn (Builder $text) => $text
                        ->whereRaw("lower(cast({$payload} as char)) like ? escape '!'", [$needle])
                        ->orWhereRaw("lower(cast({$payload} as char)) like ? escape '!'", [$escapedNeedle]));

                    // Details a person may not read are not searched for them either.
                    if (! $readsConfidential && ! $confidential->isEmpty()) {
                        $details->whereNotIn('id', $confidential->scope(AuditLog::query())->select('id'));
                    }
                });
        });
    }

    /**
     * @param  Builder<AuditLog>  $query
     */
    public static function whereStartsWith(Builder $query, string $column, string $prefix): void
    {
        $query->whereRaw($query->getQuery()->getGrammar()->wrap($column)." like ? escape '!'", [self::escapeLike($prefix).'%']);
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
