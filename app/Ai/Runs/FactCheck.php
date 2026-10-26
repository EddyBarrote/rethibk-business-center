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
 *
 * A reply's own sums are checked too: an equation that does not add up, a
 * gross that is not net plus tax, or listed lines that do not make the total
 * next to them are pointed out, so a wrong sum is never passed as validated.
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

    /** Records of a list beyond which a total over some of them proves nothing. */
    private const SUBSET_LIMIT = 16;

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
        $wrong = $this->inconsistencies($reply);
        $notes = [];

        if ($wrong !== []) {
            $notes[] = 'Nota da plataforma: há contas nesta resposta que não batem: '.implode('; ', array_slice($wrong, 0, 6))
                .(count($wrong) > 6 ? '; e outras' : '').'. Não dê estes valores como validados sem os conferir.';
        }

        if ($missing !== []) {
            $notes[] = 'Nota da plataforma: não encontrei nas fontes desta conversa '.$this->describe($missing).'. Se não '
                .(count($missing) === 1 ? 'é uma conta feita' : 'são contas feitas').' sobre dados das fontes, não '.(count($missing) === 1 ? 'está confirmado' : 'estão confirmados').'.';
        }

        return $notes === [] ? $reply : rtrim($reply)."\n\n".implode("\n\n", array_map(fn (string $note) => "> {$note}", $notes));
    }

    /**
     * The sums of a text that do not add up: an equation, a gross that is not
     * net plus tax, listed lines that do not make the total next to them.
     *
     * @return list<string>
     */
    public function inconsistencies(string $text): array
    {
        $lines = preg_split('/\R/u', $this->plain($text)) ?: [];
        $found = [];

        foreach ($lines as $line) {
            $found = [...$found, ...$this->wrongEquations($line)];

            if (($gross = $this->wrongGross($line)) !== null) {
                $found[] = $gross;
            }
        }

        return array_values(array_unique([...$found, ...$this->wrongTotals($lines)]));
    }

    /**
     * The facts of a text (the agent's reply, a tool's arguments) that no source of the run has.
     *
     * @param  list<float>  $extraMoney  amounts given as numbers (ERP arguments)
     * @return list<string>
     */
    public function ungrounded(string $text, AgentRun $run, array $extraMoney = [], bool $percentages = true): array
    {
        $text = $this->plain($text);
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
        // The totals per name of a table the run read (a client's invoices added up).
        $known = [...$this->numbers($corpus), ...$this->groupTotals($text, $corpus)];
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
     * Whether the amount is a total of some of the records of one list. In a
     * long list almost any amount is the sum of some of its records, which
     * proves nothing: there only the total of the whole list counts.
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

            if (count($list) > self::SUBSET_LIMIT) {
                if (abs((int) round(array_sum($list) * 100) - $target) <= count($list)) {
                    return true;
                }

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
        // Rounding, not a different amount: at most 50 centavos.
        return abs($a - $b) <= max(0.01, min(abs($b) * 0.0005, 0.5));
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

    /**
     * For each line of the text that names something (a client, a supplier),
     * the column totals of the source rows with that name: what adding up its
     * rows of a table gives.
     *
     * @return list<float>
     */
    private function groupTotals(string $text, string $corpus): array
    {
        $rows = preg_split('/\R/u', mb_strtolower($corpus)) ?: [];
        $totals = [];

        foreach (preg_split('/\R/u', $this->plain($text)) ?: [] as $line) {
            $label = trim((string) preg_replace('/[*_`#|>\-•]+/u', ' ', (string) preg_split('/[:(\d]/u', $line)[0]));
            $label = mb_strtolower((string) preg_replace('/\s+/u', ' ', $label));

            // A name, not a heading word that every table has.
            if (mb_strlen($label) < 4 || ! preg_match('/\p{L}{3}/u', $label)
                || preg_match('/^(total|subtotal|soma|grande|iva|valor|montante|campo|vendas|compras|imposto|base|resumo|saldo)\b/u', $label)) {
                continue;
            }

            $columns = [];
            foreach ($rows as $row) {
                if (($at = mb_strpos($row, $label)) === false) {
                    continue;
                }

                $numbers = array_values(array_map(fn ($t) => $this->parse($t), array_filter(
                    preg_split('/\s+/u', mb_substr($row, $at + mb_strlen($label))) ?: [],
                    fn ($t) => preg_match('/^\d[\d.,]*$/', $t) === 1,
                )));

                // Rows of the same table have the same columns.
                $columns[count($numbers)][] = $numbers;
            }

            foreach ($columns as $width => $group) {
                if (count($group) < 2) {
                    continue;
                }

                for ($k = 0; $k < $width; $k++) {
                    $totals[] = array_sum(array_column($group, $k));
                }
            }
        }

        return $totals;
    }

    /**
     * The text with LaTeX maths spelled out: ×, thin spaces as spaces, no braces.
     */
    private function plain(string $text): string
    {
        // $…$ and $$…$$ with commands in them are maths, not dollars.
        $text = (string) preg_replace_callback('/\$\$?([^$]*\\\\[A-Za-z]+[^$]*)\$\$?/u', fn ($m) => $m[1], $text);
        $text = str_replace(['\times', '\cdot', '\,', '\;', '\%', '\rightarrow', '\to'], ['×', '×', ' ', ' ', '%', '→', '→'], $text);

        return (string) preg_replace(['/\\\\(?:mathbf|text|textbf|mathrm|operatorname)\s*/', '/[{}]/'], ['', ''], $text);
    }

    /**
     * The amounts of a line, in the order written: with a currency, decimals
     * after a comma or thousands grouped. A rate (16%) and a code (FT 248147) are not.
     *
     * @return list<float>
     */
    private function amounts(string $line): array
    {
        preg_match_all('/(?<![\d.,\p{L}])(\d{1,3}(?:[ \x{00A0}\x{202F}.]\d{3})+(?:,\d{1,4})?|\d+(?:,\d{1,4})?)(\s?(?:MZN|MTn|MT|Mt|meticais|USD|EUR|ZAR)(?![A-Za-z]))?(?![\d%])/u', $line, $matches, PREG_SET_ORDER);
        $amounts = [];

        foreach ($matches as $match) {
            if (($match[2] ?? '') !== '' || str_contains($match[1], ',') || preg_match('/[ \x{00A0}\x{202F}.]\d{3}/u', $match[1])) {
                $amounts[] = $this->parse($match[1]);
            }
        }

        return $amounts;
    }

    /**
     * Equations written in the line (a + b = c, a × 16% = c, a − b = c) whose sides differ.
     *
     * @return list<string>
     */
    private function wrongEquations(string $line): array
    {
        $number = '\d{1,3}(?:[ \x{00A0}\x{202F}]\d{3})+(?:[.,]\d{1,4})?|\d{1,3}(?:\.\d{3})+,\d{1,4}|\d+(?:[.,]\d{1,4})?';
        $term = '(?:'.$number.')\s*%?\s*(?:MZN|MTn|MT|meticais)?';
        preg_match_all('/((?:'.$term.')(?:\s*[+−–\-×*]\s*(?:'.$term.'))+)\s*=\s*('.$number.')/u', $line, $matches, PREG_SET_ORDER);
        $wrong = [];

        foreach ($matches as $match) {
            preg_match_all('/([+−–\-×*])?\s*('.$number.')\s*(%)?/u', $match[1], $parts, PREG_SET_ORDER);
            $sum = 0.0;
            $product = null;
            $sign = 1;

            foreach ($parts as $part) {
                $value = $this->parse($part[2]) / (($part[3] ?? '') === '%' ? 100 : 1);
                $op = $part[1];

                if (in_array($op, ['×', '*'], true) && $product !== null) {
                    $product *= $value;

                    continue;
                }

                if ($product !== null) {
                    $sum += $sign * $product;
                }

                $sign = in_array($op, ['−', '–', '-'], true) ? -1 : 1;
                $product = $value;
            }

            $result = $sum + $sign * (float) $product;
            $stated = $this->parse($match[2]);

            if (abs($result - $stated) > 0.01 * count($parts) + abs($stated) * 0.0001) {
                $wrong[] = trim(preg_replace('/\s+/u', ' ', $match[1]) ?? '').' dá '.$this->format($result).', não '.$this->format($stated);
            }
        }

        return $wrong;
    }

    /**
     * A line with a gross, a net and a tax (IVA) where the gross is not net plus tax.
     */
    private function wrongGross(string $line): ?string
    {
        $amounts = array_values(array_unique($this->amounts($line), SORT_REGULAR));

        if (count($amounts) !== 3 || str_contains($line, '=') || ! preg_match('/\b(IVA|imposto)/iu', $line)
            || ! preg_match('/l[ií]quid|base|sem IVA|brut|fatur|factur|com IVA/iu', $line)) {
            return null;
        }

        rsort($amounts);
        [$gross, $a, $b] = $amounts;

        return $this->closes($a + $b, $gross, 2) ? null
            : $this->format($gross).' não é '.$this->format($a).' + '.$this->format($b).' (dá '.$this->format($a + $b).')';
    }

    /**
     * Listed lines (bullets or table rows) whose amounts do not add up to the
     * total stated beside them, in the same section.
     *
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function wrongTotals(array $lines): array
    {
        $isTotal = fn (string $line) => preg_match('/\b(total|soma|subtotal)\b/iu', $line) === 1;
        $isItem = fn (string $line) => preg_match('/^\s*(?:[*\-•]\s|\d+[.)]\s|\|)/u', $line) === 1 && ! preg_match('/^\s*\|[\s:|-]+\|?\s*$/', $line);
        $wrong = [];

        // Sections: between headings and rules.
        $sections = [[]];
        foreach ($lines as $line) {
            if (preg_match('/^\s*(#{1,6}\s|-{3,}\s*$|\*{3,}\s*$)/u', $line)) {
                $sections[] = [];
            } else {
                $sections[count($sections) - 1][] = $line;
            }
        }

        foreach ($sections as $section) {
            // Runs of consecutive items, and the total lines of the section.
            $runs = [[]];
            foreach ($section as $line) {
                if ($isItem($line) && ! $isTotal($line)) {
                    $runs[count($runs) - 1][] = $line;
                } elseif ($runs[count($runs) - 1] !== []) {
                    $runs[] = [];
                }
            }

            $totals = [];
            foreach ($section as $line) {
                if ($isTotal($line)) {
                    $totals = [...$totals, ...$this->amounts($line)];
                }
            }

            if ($totals === []) {
                continue;
            }

            foreach ($runs as $run) {
                $rows = array_values(array_filter(array_map(fn ($line) => $this->amounts($line), $run)));
                $width = count($rows[0] ?? []);

                // Only a list whose lines all carry the same columns.
                if (count($rows) < 2 || $width === 0 || collect($rows)->contains(fn ($row) => count($row) !== $width)) {
                    continue;
                }

                // A total that one column makes is that column's; the others are compared with what is left.
                $sums = array_map(fn ($k) => array_sum(array_column($rows, $k)), range(0, $width - 1));
                $left = $totals;

                foreach ($sums as $k => $sum) {
                    foreach ($left as $i => $total) {
                        if ($this->closes($sum, $total, count($rows))) {
                            unset($left[$i], $sums[$k]);

                            break;
                        }
                    }
                }

                // Each total left goes with the largest column near it (a gross before its net), once.
                rsort($left);
                foreach ($left as $total) {
                    $near = collect($sums)->filter(fn ($sum) => $total > 0 && abs($total - $sum) <= $total * 0.25)->sortDesc();

                    if ($near->isNotEmpty()) {
                        $wrong[] = 'as linhas listadas somam '.$this->format($near->first()).' e o total indicado é '.$this->format($total);
                        unset($sums[$near->keys()->first()]);
                    }
                }
            }
        }

        return $wrong;
    }

    /**
     * Two amounts equal but for the rounding of the parts that make one.
     */
    private function closes(float $a, float $b, int $parts): bool
    {
        return abs($a - $b) <= 0.01 * max(1, $parts) + abs($b) * 0.00001;
    }

    private function format(float $value): string
    {
        return number_format($value, 2, ',', ' ');
    }
}
