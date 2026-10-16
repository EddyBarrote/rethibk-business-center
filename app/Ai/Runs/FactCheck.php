<?php

namespace App\Ai\Runs;

use App\Ai\Agents\InstructionComposer;
use App\Ai\Capabilities\CapabilityContext;
use App\Enums\ActorType;
use App\Enums\StepType;
use App\Models\AgentQuestion;
use App\Models\AgentRun;
use App\Models\AgentRunStep;
use App\Models\Capability;
use App\Models\TaskMessage;

/**
 * Agents never invent business facts (docs/DECISOES.md, "Perguntar, nunca
 * inventar"). Before an agent produces something for people (a report, a
 * document, a draft, an ERP record, a message), every amount, percentage,
 * email address and phone number in it must appear in what the run had to go
 * on: its instructions and the company memory, the task, the people's
 * messages and answers, and what its tools returned. Totals, quantities and
 * taxes worked out from those count as found. Anything else is a guess.
 */
final class FactCheck
{
    /** Tools that ask instead of producing: checked only for values offered as answers. */
    private const ASKING = ['questions.ask', 'tasks.ask_human'];

    /** Not mutating, but what they write reaches people. */
    private const PRODUCING = ['reports.draft'];

    /** Numeric arguments of ERP tools that carry money. */
    private const MONEY_KEYS = '/(rate|price|preco|amount|valor|total|subtotal|cost|custo|discount|desconto|fee)/i';

    /** Fields of ERP records whose values a total adds up. */
    private const SUMMED_KEYS = '/^(total|amount|valor|balance|amount_due|outstanding|total_left_to_pay|left_to_pay|open_amount)$/i';

    private const CURRENCY = '(?:MZN|MTn|MT|Mt|meticais|USD|US\$|EUR|ZAR|€|\$)';

    private const AMOUNT = '(\d{1,3}(?:[ \x{00A0}\x{202F}.,]\d{3})+(?:[.,]\d{1,2})?|\d+(?:[.,]\d{1,2})?)';

    /**
     * Why the call may not run, or null when every fact in it has a source.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function blocked(Capability $capability, array $arguments, CapabilityContext $context): ?string
    {
        $asking = in_array($capability->key, self::ASKING, true);

        if (! $asking && ! $capability->is_mutating && ! in_array($capability->key, self::PRODUCING, true)) {
            return null;
        }

        $missing = $this->ungrounded($this->text($arguments), $context->run, $this->numericMoney($arguments));

        if ($missing === []) {
            return null;
        }

        // Asking is right; offering made-up values as the answers is not.
        if ($asking) {
            return 'Não enviado: as perguntas propõem '.$this->describe($missing).', que não '.(count($missing) === 1 ? 'aparece' : 'aparecem')
                .' em nenhuma fonte desta conversa. Não sugiras preços, taxas, prazos ou contactos que não tens: pergunta-os em aberto, com opções neutras '
                .'(por exemplo «Indico o valor» e «Ainda não sei»), e a pessoa escreve o valor na resposta. Volta a chamar questions.ask assim.';
        }

        return 'Não executado: '.$this->describe($missing).' não '.(count($missing) === 1 ? 'aparece' : 'aparecem')
            .' em nenhuma fonte desta conversa (as tuas instruções, a memória da empresa, a base de conhecimento, o ERP, os emails, as mensagens e respostas das pessoas, as páginas que leste). '
            .'Não inventes nem estimes preços, condições, prazos ou contactos, nem uses "valores de mercado". '
            .'Se é uma conta tua sobre dados das fontes (um total, uma média), mostra as parcelas no texto. '
            .'Se falta o dado, pergunta-o a quem te pediu com questions.ask (uma pergunta por dado em falta, com resposta livre) e termina a resposta; continuas com as respostas. '
            .'Não cries o documento, o relatório, o rascunho ou o registo com marcadores ou valores provisórios.';
    }

    /**
     * The agent's reply, with a note on the amounts and contacts it holds that
     * no source has. The reply is not blocked: it may be a sum the agent did.
     */
    public function annotate(string $reply, AgentRun $run): string
    {
        $missing = $this->ungrounded($reply, $run, percentages: false);

        return $missing === [] ? $reply
            : rtrim($reply)."\n\n> Nota da plataforma: não encontrei nas fontes desta conversa ".$this->describe($missing).'. Se não '
                .(count($missing) === 1 ? 'é uma conta feita' : 'são contas feitas').' sobre dados das fontes, não '.(count($missing) === 1 ? 'está confirmado' : 'estão confirmados').'.';
    }

    /**
     * The facts of a text (the agent's reply, a tool's arguments) that no source of the run has.
     *
     * @param  list<float>  $extraMoney  amounts given as numbers (ERP arguments)
     * @return list<string>
     */
    public function ungrounded(string $text, AgentRun $run, array $extraMoney = [], bool $percentages = true): array
    {
        $claims = $this->claims($text);

        if (! $percentages) {
            $claims['percent'] = [];
        }

        foreach ($extraMoney as $value) {
            $claims['money'][] = ['value' => $value, 'label' => $this->format($value)];
        }

        if ($claims['money'] === [] && $claims['percent'] === [] && $claims['emails'] === [] && $claims['phones'] === []) {
            return [];
        }

        ['text' => $corpus, 'lists' => $lists] = $this->corpus($run);
        $known = $this->numbers($corpus);
        // A rate needs a rate in the sources: any 50 is not 50%.
        preg_match_all('/(\d{1,3}(?:[.,]\d{1,2})?)\s?(?:%|por cento)/u', $corpus, $rateMatches);
        $percents = array_map(fn ($r) => (float) str_replace(',', '.', $r), $rateMatches[1]);
        $lower = mb_strtolower($corpus);
        $phones = array_map(fn ($p) => substr($p, -9), $this->phones($corpus));
        $missing = [];

        // Percentages: in a source, a known tax rate, or the trivial 0 and 100.
        $rates = [];
        foreach ($claims['percent'] as $claim) {
            $ok = in_array($claim['value'], [0.0, 100.0], true) || $this->has($percents, $claim['value'])
                || in_array($claim['value'], array_map('floatval', (array) config('agents.known_rates', [])), true);
            $ok ? $rates[] = $claim['value'] : $missing[] = $claim['label'];
        }
        $rates = [...$rates, ...array_map('floatval', (array) config('agents.known_rates', []))];

        // Amounts: in a source, or worked out from amounts already found (a total,
        // a quantity, a tax or a discount over them).
        $found = [];
        $open = $claims['money'];
        $quantities = array_values(array_filter($this->numbers($text), fn ($n) => $n >= 2 && $n <= 1000 && floor($n) === $n));

        for ($pass = 0; $pass < 3 && $open !== []; $pass++) {
            $still = [];

            foreach ($open as $claim) {
                $v = $claim['value'];

                if ($v == 0.0 || $this->has($known, $v) || $this->derived($v, $found, $quantities, $rates) || $this->totalOf($v, $lists)) {
                    $found[] = $v;
                } else {
                    $still[] = $claim;
                }
            }

            if (count($still) === count($open)) {
                break;
            }

            $open = $still;
        }

        foreach ($open as $claim) {
            $missing[] = $claim['label'];
        }

        foreach ($claims['emails'] as $email) {
            if (! str_contains($lower, $email)) {
                $missing[] = $email;
            }
        }

        foreach ($claims['phones'] as $phone) {
            if (! in_array(substr($phone['digits'], -9), $phones, true)) {
                $missing[] = $phone['label'];
            }
        }

        return array_values(array_unique($missing));
    }

    /**
     * @param  list<string>  $missing
     */
    public function describe(array $missing): string
    {
        $shown = array_slice($missing, 0, 8);

        return (count($missing) === 1 ? 'o dado ' : 'os dados ').implode(', ', $shown).(count($missing) > 8 ? ' e outros' : '');
    }

    /**
     * Everything the run had to go on: the text, and the amounts of each
     * record list it read (a total over them is found too). The agent's own
     * earlier replies are not a source; what earlier runs of the task read is.
     *
     * @return array{text: string, lists: list<list<float>>}
     */
    private function corpus(AgentRun $run): array
    {
        $parts = [app(InstructionComposer::class)->for($run->agent, $run), (string) $run->input];
        $lists = [];

        $runs = $run->task_id !== null
            ? AgentRun::query()->where('task_id', $run->task_id)->latest('id')->limit(30)->pluck('id')->push($run->id)->unique()->all()
            : [$run->id];

        foreach (AgentRunStep::query()->whereIn('agent_run_id', $runs)->where('type', StepType::ToolResult)->pluck('payload') as $payload) {
            $content = is_array($payload) ? (string) ($payload['content'] ?? json_encode($payload)) : (string) $payload;
            $parts[] = $content;
            $decoded = json_decode($content, true);

            if (is_array($decoded) && ($amounts = $this->listAmounts($decoded)) !== []) {
                $lists[] = $amounts;
            }
        }

        foreach (AgentQuestion::query()->where('agent_run_id', $run->id)->get(['questions', 'answers']) as $question) {
            $parts[] = json_encode([$question->questions, $question->answers], JSON_UNESCAPED_UNICODE);
        }

        if ($run->task_id !== null) {
            $parts[] = TaskMessage::query()->where('task_id', $run->task_id)->where('author_type', '!=', ActorType::Agent)->pluck('body')->implode("\n");
        }

        // Built each time: a run reads more as it goes.
        return ['text' => implode("\n", $parts), 'lists' => $lists];
    }

    /**
     * The totals of the records in a tool's answer (invoices, payments, lines).
     *
     * @param  array<array-key, mixed>  $data
     * @return list<float>
     */
    private function listAmounts(array $data): array
    {
        $amounts = [];
        array_walk_recursive($data, function ($value, $key) use (&$amounts): void {
            if (is_string($key) && preg_match(self::SUMMED_KEYS, $key) && is_numeric($value) && (float) $value > 0) {
                $amounts[] = (float) $value;
            }
        });

        return $amounts;
    }

    /**
     * Whether the amount is a total of some of the records of one list.
     * A list too long to check is given the benefit of the doubt.
     *
     * @param  list<list<float>>  $lists
     */
    private function totalOf(float $v, array $lists): bool
    {
        $target = (int) round($v * 100);

        foreach ($lists as $list) {
            if (count($list) < 2) {
                continue;
            }

            $reachable = [0 => true];

            foreach ($list as $amount) {
                $cents = (int) round($amount * 100);

                foreach (array_keys($reachable) as $sum) {
                    $next = $sum + $cents;

                    if (abs($next - $target) <= 1) {
                        return true;
                    }

                    if ($next < $target) {
                        $reachable[$next] = true;
                    }
                }

                if (count($reachable) > 200_000) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array{money: list<array{value: float, label: string}>, percent: list<array{value: float, label: string}>, emails: list<string>, phones: list<array{digits: string, label: string}>}
     */
    private function claims(string $text): array
    {
        $money = [];
        $patterns = [
            '/'.self::AMOUNT.'\s?'.self::CURRENCY.'(?![A-Za-z])/u',
            '/(?<![A-Za-z])'.self::CURRENCY.'\s?'.self::AMOUNT.'/u',
            // 25 000,00 or 25.000,00: a price even without the currency.
            '/(?<![\d.,])(\d{1,3}(?:[ \x{00A0}\x{202F}.]\d{3})+,\d{2}|\d{4,},\d{2})(?![\d%])/u',
        ];

        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $text, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $money[] = ['value' => $this->parse($match[1]), 'label' => trim($match[0])];
            }
        }

        $percent = [];
        preg_match_all('/(?<![\d.,])(\d{1,3}(?:[.,]\d{1,2})?)\s?%/u', $text, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $percent[] = ['value' => (float) str_replace(',', '.', $match[1]), 'label' => trim($match[0])];
        }

        preg_match_all('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $text, $matches);
        $emails = array_values(array_unique(array_map('mb_strtolower', $matches[0])));

        $phones = [];
        preg_match_all('/(?:\+?258[\s-]?)?(?:8[2-7]|2\d)[\s-]?\d{3}[\s-]?\d{3,4}(?!\d)/', $text, $matches);
        foreach ($matches[0] as $label) {
            $digits = (string) preg_replace('/\D/', '', $label);
            if (strlen($digits) >= 8) {
                $phones[] = ['digits' => $digits, 'label' => trim($label)];
            }
        }

        // A money match inside a phone number is the phone.
        $money = array_values(array_filter($money, fn ($m) => ! collect($phones)->contains(fn ($p) => str_contains($p['label'], $m['label']))));

        return ['money' => $this->unique($money), 'percent' => $this->unique($percent), 'emails' => $emails, 'phones' => $phones];
    }

    /**
     * Every number a text holds, read every way it could be meant (25.000 is 25000 or 25).
     *
     * @return list<float>
     */
    private function numbers(string $text): array
    {
        preg_match_all('/\d[\d \x{00A0}\x{202F}.,]*\d|\d/u', $text, $matches);
        $values = [];

        foreach ($matches[0] as $token) {
            $token = rtrim($token, ' .,');

            foreach ([$token, ...preg_split('/[ \x{00A0}\x{202F}]+/u', $token)] as $piece) {
                $plain = (string) preg_replace('/[ \x{00A0}\x{202F}]/u', '', $piece);

                if ($plain === '') {
                    continue;
                }

                $values[] = $this->parse($plain);
                // The other reading of a lone separator.
                $values[] = (float) str_replace([',', '.'], ['', ''], $plain);
                if (preg_match('/^\d+[.,]\d+$/', $plain)) {
                    $values[] = (float) str_replace(',', '.', $plain);
                }
            }
        }

        return array_values(array_unique($values, SORT_REGULAR));
    }

    /**
     * A number as written in Mozambique or in English.
     */
    private function parse(string $amount): float
    {
        $s = (string) preg_replace('/[ \x{00A0}\x{202F}]/u', '', $amount);
        $comma = strrpos($s, ',');
        $dot = strrpos($s, '.');

        if ($comma !== false && $dot !== false) {
            $decimal = $comma > $dot ? ',' : '.';
            $s = str_replace($decimal === ',' ? '.' : ',', '', $s);

            return (float) str_replace(',', '.', $s);
        }

        $separator = $comma !== false ? ',' : ($dot !== false ? '.' : null);

        if ($separator === null) {
            return (float) $s;
        }

        // 25.000 and 1,250,000 are thousands; 3,5 and 12.50 are decimals.
        return preg_match('/^\d{1,3}(\\'.$separator.'\d{3})+$/', $s)
            ? (float) str_replace($separator, '', $s)
            : (float) str_replace(',', '.', str_replace($separator === ',' ? '.' : ',', '', $s));
    }

    /**
     * @param  list<float>  $found
     * @param  list<float>  $quantities
     * @param  list<float>  $rates
     */
    private function derived(float $v, array $found, array $quantities, array $rates): bool
    {
        $n = count($found);

        for ($i = 0; $i < $n; $i++) {
            $a = $found[$i];

            foreach ($quantities as $k) {
                if ($this->same($v, $a * $k)) {
                    return true;
                }
            }

            foreach ($rates as $p) {
                if ($this->same($v, $a * $p / 100) || $this->same($v, $a * (1 + $p / 100)) || $this->same($v, $a * (1 - $p / 100))) {
                    return true;
                }
            }

            for ($j = $i + 1; $j < $n; $j++) {
                if ($this->same($v, $a + $found[$j]) || $this->same($v, abs($a - $found[$j]))) {
                    return true;
                }

                for ($l = $j + 1; $l < $n; $l++) {
                    if ($this->same($v, $a + $found[$j] + $found[$l])) {
                        return true;
                    }
                }
            }
        }

        // A total of every amount found so far.
        return $n > 3 && $this->same($v, array_sum($found));
    }

    /**
     * @param  list<float>  $known
     */
    private function has(array $known, float $v): bool
    {
        foreach ($known as $k) {
            if ($this->same($v, $k)) {
                return true;
            }
        }

        return false;
    }

    private function same(float $a, float $b): bool
    {
        return abs($a - $b) <= max(0.01, abs($b) * 0.0005);
    }

    /**
     * @return list<string>
     */
    private function phones(string $text): array
    {
        preg_match_all('/(?:\+?258[\s-]?)?(?:8[2-7]|2\d)[\s-]?\d{3}[\s-]?\d{3,4}(?!\d)/', $text, $matches);

        return array_map(fn ($p) => (string) preg_replace('/\D/', '', $p), $matches[0]);
    }

    /**
     * The text of the arguments. Recipients are left to the autonomy gate,
     * which already shows where each address came from (RecipientSources).
     *
     * @param  array<array-key, mixed>  $arguments
     */
    private function text(array $arguments): string
    {
        $strings = [];

        foreach ($arguments as $key => $value) {
            if (in_array($key, ['to', 'cc', 'bcc'], true)) {
                continue;
            }

            $strings[] = is_array($value) ? $this->text($value) : (is_string($value) ? $value : '');
        }

        return implode("\n", array_filter($strings));
    }

    /**
     * @param  array<array-key, mixed>  $arguments
     * @return list<float>
     */
    private function numericMoney(array $arguments, string $key = ''): array
    {
        $values = [];

        foreach ($arguments as $k => $value) {
            if (is_array($value)) {
                $values = [...$values, ...$this->numericMoney($value, is_string($k) ? $k : $key)];
            } elseif ((is_int($value) || is_float($value)) && preg_match(self::MONEY_KEYS, is_string($k) ? $k : $key)) {
                $values[] = (float) $value;
            }
        }

        return $values;
    }

    /**
     * @param  list<array{value: float, label: string}>  $claims
     * @return list<array{value: float, label: string}>
     */
    private function unique(array $claims): array
    {
        return array_values(collect($claims)->unique(fn ($c) => round($c['value'], 2))->all());
    }

    private function format(float $value): string
    {
        return number_format($value, 2, ',', ' ');
    }
}
