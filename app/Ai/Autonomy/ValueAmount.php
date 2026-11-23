<?php

namespace App\Ai\Autonomy;

/**
 * The amount a write commits, read from the tool's arguments (docs/DECISOES.md,
 * "Limite de valor por agente"): the stated total or amount, or the lines
 * (quantity × price, less the line discount), less the document's discount,
 * plus its adjustment and shipping. Taxes stay out: the ERP works them out
 * from tax ids the platform cannot read. When both a total and lines are
 * given, the larger counts; a line without a price leaves the amount unknown.
 */
final readonly class ValueAmount
{
    /** Fields that state the whole amount. */
    private const TOTALS = ['total', 'total_amount', 'grand_total', 'amount', 'contract_value'];

    /** Fields that hold the lines of a document. */
    private const LINES = ['items', 'lines', 'newitems'];

    private const QUANTITY = ['qty', 'quantity'];

    private const PRICE = ['rate', 'unit_price', 'price'];

    /** Fields that change a document's amount without stating it. */
    private const ADJUSTERS = ['adjustment', 'discount_fixed', 'discount_percent', 'shipping_fee'];

    private const CURRENCY = ['currency', 'currency_code'];

    /** Ways of writing meticais. */
    private const MZN = ['MZN', 'MT', 'MTN', 'METICAL', 'METICAIS'];

    public function __construct(
        /** In the document's currency; null when it cannot be read. */
        public ?float $amount,
        /** "MZN", a code ("USD") or the ERP's currency id ("2"). Not stated means MZN. */
        public string $currency,
        /** The arguments carry a value at all (a total, lines, a discount, a currency). */
        public bool $carriesValue,
    ) {}

    /**
     * @param  array<string, mixed>  $arguments
     */
    public static function read(array $arguments): self
    {
        $carries = array_intersect(array_keys($arguments), [...self::TOTALS, ...self::LINES, ...self::ADJUSTERS, ...self::CURRENCY]) !== [];

        return new self(self::amountOf($arguments), self::currencyOf($arguments), $carries);
    }

    public function isMzn(): bool
    {
        return $this->currency === 'MZN';
    }

    /**
     * In MZN, at a fixed rate of autonomy.value_limit_rates; null when the
     * amount is unknown or the currency has no rate.
     */
    public function inMzn(): ?float
    {
        if ($this->amount === null) {
            return null;
        }

        if ($this->isMzn()) {
            return $this->amount;
        }

        $rate = ((array) config('autonomy.value_limit_rates', []))[$this->currency] ?? null;

        return is_numeric($rate) && (float) $rate > 0 ? round($this->amount * (float) $rate, 2) : null;
    }

    /** "2 000 USD", or "2 000 (moeda 2 do ERP)" for an ERP currency id. */
    public function label(): string
    {
        $amount = self::format((float) $this->amount);

        return ctype_digit($this->currency) ? "{$amount} (moeda {$this->currency} do ERP)" : "{$amount} {$this->currency}";
    }

    /** 125 000 or 1 234,50, as amounts are written in Mozambique. */
    public static function format(float $amount): string
    {
        $decimals = fmod($amount, 1.0) === 0.0 ? 0 : 2;

        return number_format($amount, $decimals, ',', ' ');
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private static function amountOf(array $arguments): ?float
    {
        $stated = null;

        foreach (self::TOTALS as $field) {
            if (isset($arguments[$field]) && is_numeric($arguments[$field])) {
                $stated = max($stated ?? 0.0, abs((float) $arguments[$field]));
            }
        }

        $lines = null;

        foreach (self::LINES as $field) {
            if (isset($arguments[$field]) && is_array($arguments[$field]) && $arguments[$field] !== []) {
                $lines = $arguments[$field];
            }
        }

        if ($lines === null) {
            return $stated;
        }

        $sum = 0.0;

        foreach ($lines as $line) {
            $price = is_array($line) ? self::first($line, self::PRICE) : null;

            // The ERP fills the price from its catalogue: what it will be is not in the arguments.
            if ($price === null) {
                return null;
            }

            $quantity = self::first($line, self::QUANTITY) ?? 1.0;
            $discount = self::first($line, ['discount_percent']) ?? 0.0;
            $sum += $quantity * $price * (1 - $discount / 100);
        }

        $sum = $sum * (1 - (self::first($arguments, ['discount_percent']) ?? 0.0) / 100)
            - (self::first($arguments, ['discount_fixed']) ?? 0.0)
            + (self::first($arguments, ['adjustment']) ?? 0.0)
            + (self::first($arguments, ['shipping_fee']) ?? 0.0);

        return max($stated ?? 0.0, round(abs($sum), 2));
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private static function currencyOf(array $arguments): string
    {
        foreach (self::CURRENCY as $field) {
            $value = $arguments[$field] ?? null;

            if (is_int($value) || (is_string($value) && trim($value) !== '')) {
                $code = strtoupper(trim((string) $value));

                return in_array($code, self::MZN, true) ? 'MZN' : $code;
            }
        }

        return 'MZN';
    }

    /**
     * @param  array<mixed>  $values
     * @param  list<string>  $keys
     */
    private static function first(array $values, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (isset($values[$key]) && is_numeric($values[$key])) {
                return (float) $values[$key];
            }
        }

        return null;
    }
}
