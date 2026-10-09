<?php

namespace App\Support;

/**
 * A routine's cron schedule in words, for people ("Dias úteis às 08:30").
 * The Rotinas page builds the same shapes (resources/js/lib/schedule.ts);
 * anything else is shown as written.
 */
final class ScheduleLabel
{
    private const DAYS = ['domingos', 'segundas', 'terças', 'quartas', 'quintas', 'sextas', 'sábados'];

    public static function for(string $cron): string
    {
        $parts = preg_split('/\s+/', trim($cron)) ?: [];

        if (count($parts) !== 5) {
            return "Personalizado: {$cron}";
        }

        [$minute, $hour, $day, $month, $weekday] = $parts;

        if (! ctype_digit($minute) || $month !== '*') {
            return "Personalizado: {$cron}";
        }

        if ($hour === '*' && $day === '*' && $weekday === '*') {
            return (int) $minute === 0 ? 'De hora a hora' : sprintf('De hora a hora, aos %d minutos', (int) $minute);
        }

        if (! ctype_digit($hour)) {
            return "Personalizado: {$cron}";
        }

        $at = sprintf('%02d:%02d', (int) $hour, (int) $minute);

        if ($day === '*' && $weekday === '*') {
            return "Todos os dias às {$at}";
        }

        if ($day === '*' && $weekday === '1-5') {
            return "Dias úteis às {$at}";
        }

        if ($day === '*' && preg_match('/^[0-7](,[0-7])*$/', $weekday) === 1) {
            $days = array_values(array_unique(array_map(fn (string $d) => (int) $d % 7, explode(',', $weekday))));
            sort($days);

            if ($days === [0, 6]) {
                return "Fins de semana às {$at}";
            }

            $names = array_map(fn (int $d) => self::DAYS[$d], $days);
            $last = array_pop($names);

            return 'Às '.($names === [] ? $last : implode(', ', $names)." e {$last}").", às {$at}";
        }

        if ($weekday === '*' && ctype_digit($day)) {
            return sprintf('Dia %d de cada mês, às %s', (int) $day, $at);
        }

        return "Personalizado: {$cron}";
    }
}
