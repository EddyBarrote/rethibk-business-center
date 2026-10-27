<?php

namespace App\Tasks;

use Illuminate\Support\Carbon;

/**
 * Reads the day out of a task written in one line (docs/DECISOES.md,
 * "Tarefas: criação rápida"): «Ligar à Mozal sexta» is «Ligar à Mozal» due on
 * the next Friday. It knows hoje, amanhã, depois de amanhã, the days of the
 * week (with or without «na», «-feira»), «dia 5» and 5/11; anything else
 * stays in the title and the task has no date.
 */
class QuickDate
{
    private const WEEKDAYS = ['segunda' => 1, 'terça' => 2, 'terca' => 2, 'quarta' => 3, 'quinta' => 4, 'sexta' => 5, 'sábado' => 6, 'sabado' => 6, 'domingo' => 0];

    /**
     * @return array{0: string, 1: Carbon|null}
     */
    public static function parse(string $text, Carbon $today): array
    {
        $text = trim($text);
        $day = null;
        $patterns = [
            '/\b(?:para\s+)?depois\s+de\s+amanh[ãa]\b/iu' => fn () => $today->copy()->addDays(2),
            '/\b(?:para\s+)?amanh[ãa]\b/iu' => fn () => $today->copy()->addDay(),
            '/\b(?:para\s+)?hoje\b/iu' => fn () => $today->copy(),
            '/\b(?:na\s+|no\s+|at[ée]\s+|para\s+)?(segunda|ter[çc]a|quarta|quinta|sexta|s[áa]bado|domingo)(?:-feira)?\b/iu' => function (array $m) use ($today) {
                $target = self::WEEKDAYS[mb_strtolower($m[1])];
                $days = ($target - $today->dayOfWeek + 7) % 7;

                return $today->copy()->addDays($days === 0 ? 7 : $days);
            },
            '/\b(?:no\s+|at[ée]\s+|para\s+)?dia\s+(\d{1,2})\b/iu' => function (array $m) use ($today) {
                $date = $today->copy()->day(min((int) $m[1], $today->daysInMonth));

                return $date->lt($today) ? $today->copy()->addMonthNoOverflow()->day(min((int) $m[1], $today->copy()->addMonthNoOverflow()->daysInMonth)) : $date;
            },
            '/\b(?:at[ée]\s+|para\s+|a\s+)?(\d{1,2})\/(\d{1,2})(?:\/(\d{2,4}))?\b/u' => function (array $m) use ($today) {
                $year = isset($m[3]) ? (int) (strlen($m[3]) === 2 ? '20'.$m[3] : $m[3]) : $today->year;
                if (! checkdate((int) $m[2], (int) $m[1], $year)) {
                    return null;
                }
                $date = Carbon::create($year, (int) $m[2], (int) $m[1], 0, 0, 0, $today->timezone);

                return ! isset($m[3]) && $date->lt($today) ? $date->addYear() : $date;
            },
        ];

        foreach ($patterns as $pattern => $resolve) {
            if (preg_match($pattern, $text, $match) === 1 && ($found = $resolve($match)) !== null) {
                $day = $found;
                $text = trim((string) preg_replace('/\s{2,}/', ' ', str_replace($match[0], ' ', $text)), " \t,.-");
                break;
            }
        }

        return [$text === '' ? 'Sem título' : $text, $day?->startOfDay()];
    }
}
