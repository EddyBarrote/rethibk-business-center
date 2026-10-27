<?php

namespace App\Ai\Autonomy;

/**
 * What must not leave the company without a person looking (docs/DECISOES.md,
 * "Dados sensíveis no que sai", an idea from OpenDuet): card numbers (that
 * pass the Luhn check), passwords written out, API keys and tokens, identity
 * card numbers and health data. Bank account numbers are not on the list:
 * invoices carry them on purpose.
 */
final class SensitiveData
{
    private const HEALTH = '/\b(HIV|SIDA|seropositiv\w*|tuberculose|diagn[óo]stic[oa]s?\s+(de|médic)\w*|doen[çc]a\s+cr[óo]nica|baixa\s+m[ée]dica|atestado\s+m[ée]dico|gravidez|resultados?\s+d[aeo]s?\s+an[áa]lises)\b/iu';

    private const PASSWORD = '/\b(palavra[- ]passe|password|senha|passcode|pin)\s*[:=]\s*\S{4,}/iu';

    private const SECRET = '/\b(sk-[A-Za-z0-9_-]{16,}|sk-ant-[A-Za-z0-9_-]{16,}|AIza[0-9A-Za-z_-]{30,}|ghp_[A-Za-z0-9]{30,}|xox[bap]-[A-Za-z0-9-]{10,}|AKIA[0-9A-Z]{16})\b/';

    private const IDENTITY = '/\b\d{12}[A-Z]\b/';

    /**
     * The kinds found, in words («um número de cartão», …); empty when clean.
     *
     * @param  array<string, mixed>  $arguments
     * @return list<string>
     */
    public function find(array $arguments): array
    {
        $text = $this->text($arguments);
        $found = [];

        if ($this->hasCard($text)) {
            $found[] = 'um número de cartão';
        }

        if (preg_match(self::PASSWORD, $text) === 1) {
            $found[] = 'uma palavra-passe';
        }

        if (preg_match(self::SECRET, $text) === 1) {
            $found[] = 'uma chave de acesso (API)';
        }

        if (preg_match(self::IDENTITY, $text) === 1) {
            $found[] = 'um número de BI';
        }

        if (preg_match(self::HEALTH, $text) === 1) {
            $found[] = 'dados de saúde';
        }

        return $found;
    }

    private function hasCard(string $text): bool
    {
        preg_match_all('/(?<!\d)(?:\d[ -]?){12,18}\d(?!\d)/', $text, $matches);

        foreach ($matches[0] as $candidate) {
            $digits = preg_replace('/\D/', '', $candidate) ?? '';

            if (strlen($digits) >= 13 && strlen($digits) <= 19 && count(array_unique(str_split($digits))) > 1 && $this->luhn($digits)) {
                return true;
            }
        }

        return false;
    }

    private function luhn(string $digits): bool
    {
        $sum = 0;
        $double = false;

        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $d = (int) $digits[$i];
            if ($double) {
                $d *= 2;
                $d = $d > 9 ? $d - 9 : $d;
            }
            $sum += $d;
            $double = ! $double;
        }

        return $sum % 10 === 0;
    }

    /**
     * @param  array<mixed>  $value
     */
    private function text(array $value): string
    {
        $parts = [];
        array_walk_recursive($value, function ($item) use (&$parts): void {
            if (is_string($item)) {
                $parts[] = $item;
            }
        });

        return implode("\n", $parts);
    }
}
