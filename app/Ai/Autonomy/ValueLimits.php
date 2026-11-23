<?php

namespace App\Ai\Autonomy;

use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\Capability;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Support\TenantSettings;
use Illuminate\Validation\Rule;

/**
 * The value limit per agent (docs/DECISOES.md, "Limite de valor por agente";
 * the proposal's «limite de valor» per agent and per type of purchase). A write
 * that commits value (autonomy.value_limit) waits for a person when its amount
 * is above the agent's limit, cannot be read, or is in a currency without a
 * rate, whatever the agent's level or a «Permitir sempre» rule. Below it,
 * nothing changes. The absolute ceiling is checked first and stays above.
 *
 * Which limit: the agent's own for the type (compras, facturação, despesas),
 * then the agent's own, then the company's (business.agent_value_limit_mzn).
 * 0 means no limit; an agent's empty field takes the next one. Kept in
 * agents.settings['value_limit'] as ['amount' => ?int, 'types' => [type => int]].
 */
final class ValueLimits
{
    /** The company's default, in config/business.php and the tenant's settings. */
    public const SETTING = 'agent_value_limit_mzn';

    /** Types an agent may have its own limit for, with their names. */
    public const TYPES = [
        'purchases' => 'Compras',
        'sales' => 'Facturação e vendas',
        'expenses' => 'Despesas',
    ];

    /** The highest limit accepted, in MZN. */
    public const MAX = 1_000_000_000;

    /** The type of a capability that commits value, or null. */
    public static function typeOf(string $capabilityKey): ?string
    {
        foreach ((array) config('autonomy.value_limit', []) as $type => $keys) {
            if (in_array($capabilityKey, (array) $keys, true)) {
                return (string) $type;
            }
        }

        return null;
    }

    public function companyLimit(): int
    {
        return max(0, TenantSettings::int(self::SETTING));
    }

    /**
     * The limit that applies to the agent for this type, and where it comes from.
     *
     * @return array{amount: int, source: 'type'|'agent'|'company'}
     */
    public function limitFor(Agent $agent, string $type): array
    {
        $own = (array) ($agent->settings['value_limit'] ?? []);

        if (isset($own['types'][$type]) && is_numeric($own['types'][$type])) {
            return ['amount' => max(0, (int) $own['types'][$type]), 'source' => 'type'];
        }

        if (isset($own['amount']) && is_numeric($own['amount'])) {
            return ['amount' => max(0, (int) $own['amount']), 'source' => 'agent'];
        }

        return ['amount' => $this->companyLimit(), 'source' => 'company'];
    }

    /**
     * Why a person must decide this call, or null when the limit lets it
     * through (or there is none). The text goes to the approval card, the
     * agent and the audit.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function reason(string $capabilityKey, array $arguments, Agent $agent): ?string
    {
        $type = self::typeOf($capabilityKey);

        if ($type === null) {
            return null;
        }

        ['amount' => $limit, 'source' => $source] = $this->limitFor($agent, $type);

        if ($limit <= 0) {
            return null;
        }

        $value = ValueAmount::read($arguments);

        // An edit that changes no value (a date, a note) is not a commitment.
        if (! $value->carriesValue && in_array($capabilityKey, (array) config('autonomy.value_limit_edits', []), true)) {
            return null;
        }

        $of = $this->limitLabel($limit, $source, $type);

        if ($value->amount === null) {
            return "Valor por determinar (o pedido não traz o total nem o preço de cada linha), com o {$of}";
        }

        $mzn = $value->inMzn();

        if ($mzn === null) {
            return "Valor {$value->label()}, numa moeda sem câmbio para MZN definido na plataforma: conta como acima do {$of}";
        }

        if ($mzn <= $limit) {
            return null;
        }

        $stated = $value->isMzn() ? ValueAmount::format($mzn).' MZN' : $value->label().' ('.ValueAmount::format($mzn).' MZN)';

        return "Valor {$stated} acima do {$of}";
    }

    /**
     * For the agent's instructions: its limits for the types it can write, so
     * it plans with them. Null when it has none.
     */
    public function forPrompt(Agent $agent): ?string
    {
        $types = $this->typesHeldBy($agent);
        $limits = [];

        foreach ($types as $type) {
            $limits[$type] = $this->limitFor($agent, $type)['amount'];
        }

        if (array_filter($limits) === []) {
            return null;
        }

        $words = fn (int $amount) => $amount > 0 ? ValueAmount::format($amount).' MZN' : 'sem limite';
        $distinct = array_unique($limits);
        $line = count($distinct) === 1
            ? $words((int) reset($distinct)).' por documento'
            : implode('; ', array_map(fn (string $type) => mb_strtolower(self::TYPES[$type]).': '.$words($limits[$type]), array_keys($limits))).', por documento';

        return "Limite de valor: {$line}, sem impostos. Facturas, orçamentos, propostas, encomendas e despesas acima do limite, "
            .'sem um valor que a plataforma consiga ler ou noutra moeda ficam à espera da aprovação de uma pessoa, seja qual for o teu nível. '
            .'Põe sempre nos argumentos a quantidade e o preço de cada linha (ou o valor). Se passar do limite, prepara-o na mesma e diz que ficou à espera; '
            .'nunca dividas uma compra, factura ou despesa em várias para ficar abaixo do limite.';
    }

    /**
     * The agent's own limits and the company's, for the form.
     *
     * @return array{amount: int|null, types: array<string, int|null>, company: int, labels: array<string, string>, held: list<string>, max: int}
     */
    public function present(Agent $agent): array
    {
        $own = (array) ($agent->settings['value_limit'] ?? []);

        $types = [];

        foreach (array_keys(self::TYPES) as $type) {
            $types[$type] = isset($own['types'][$type]) && is_numeric($own['types'][$type]) ? (int) $own['types'][$type] : null;
        }

        return [
            'amount' => isset($own['amount']) && is_numeric($own['amount']) ? (int) $own['amount'] : null,
            'types' => $types,
            'company' => $this->companyLimit(),
            'labels' => self::TYPES,
            'held' => $this->typesHeldBy($agent),
            'max' => self::MAX,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        $amount = ['nullable', 'integer', 'between:0,'.self::MAX];

        return [
            'amount' => $amount,
            'types' => ['nullable', 'array:'.implode(',', array_keys(self::TYPES))],
            ...collect(array_keys(self::TYPES))->mapWithKeys(fn (string $type) => ["types.{$type}" => $amount])->all(),
        ];
    }

    /**
     * Saves the agent's own limits (validated with rules()) and audits the change.
     *
     * @param  array<string, mixed>  $data
     */
    public function save(Agent $agent, array $data, User|PlatformAdmin $actor): void
    {
        $before = $agent->settings['value_limit'] ?? null;
        $types = array_map('intval', array_filter((array) ($data['types'] ?? []), fn ($value) => $value !== null && $value !== ''));
        $after = array_filter([
            'amount' => isset($data['amount']) && $data['amount'] !== '' ? (int) $data['amount'] : null,
            'types' => array_intersect_key($types, self::TYPES) ?: null,
        ], fn ($value) => $value !== null) ?: null;

        if ($after === $before) {
            return;
        }

        $settings = $agent->settings ?? [];
        unset($settings['value_limit']);

        $agent->forceFill(['settings' => $after === null ? $settings : [...$settings, 'value_limit' => $after]])->save();

        AuditLog::record($actor, 'agent.value_limit_updated', [
            'key' => $agent->key,
            'before' => $before,
            'after' => $after,
            'company' => $this->companyLimit(),
        ], subject: $agent);
    }

    /**
     * The types of value the agent can commit with its capabilities.
     *
     * @return list<string>
     */
    private function typesHeldBy(Agent $agent): array
    {
        $keys = $agent->capabilities()->wherePivot('enabled', true)->where('is_available', true)->pluck('key')->all();

        return array_values(array_filter(array_keys(self::TYPES), fn (string $type) => array_intersect($keys, (array) config("autonomy.value_limit.{$type}", [])) !== []));
    }

    /** "limite de 50 000 MZN deste agente", "limite de compras de 100 000 MZN deste agente", "limite de 50 000 MZN da empresa". */
    private function limitLabel(int $limit, string $source, string $type): string
    {
        $amount = ValueAmount::format($limit).' MZN';

        return match ($source) {
            'type' => 'limite de '.mb_strtolower(self::TYPES[$type])." de {$amount} deste agente",
            'agent' => "limite de {$amount} deste agente",
            default => "limite de {$amount} da empresa",
        };
    }
}
