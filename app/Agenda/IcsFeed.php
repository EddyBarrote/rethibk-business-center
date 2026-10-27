<?php

namespace App\Agenda;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * A person's agenda as an iCalendar feed, for Google Calendar or Outlook to
 * subscribe to (docs/DECISOES.md, "Agenda"). Read-only: the calendar reads
 * the link, nothing is written to anyone's account. Only titles, dates and a
 * link back; never the content of an email or a conversation.
 */
final class IcsFeed
{
    public function __construct(private readonly Agenda $agenda) {}

    public function render(User $user, string $baseUrl): string
    {
        $events = $this->agenda->events($user, now()->subDays(60), now()->addYear(), routines: false, google: false);
        $tenant = Tenant::current()->name ?? config('app.name');
        $timezone = (string) config('agents.schedule_timezone');
        $stamp = now()->utc()->format('Ymd\THis\Z');

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//MICOMOC//Agenda//PT',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.$this->text("Agenda · {$tenant}"),
            'X-WR-TIMEZONE:'.$timezone,
            'REFRESH-INTERVAL;VALUE=DURATION:PT1H',
        ];

        foreach ($events as $event) {
            $at = Carbon::parse($event['at']);
            $description = self::description($event, $baseUrl);

            $lines[] = 'BEGIN:VEVENT';
            $lines[] = "UID:{$event['key']}@".parse_url($baseUrl, PHP_URL_HOST);
            $lines[] = "DTSTAMP:{$stamp}";

            if ($event['all_day']) {
                $day = $at->copy()->setTimezone($timezone);
                $lines[] = 'DTSTART;VALUE=DATE:'.$day->format('Ymd');
                $lines[] = 'DTEND;VALUE=DATE:'.$day->copy()->addDay()->format('Ymd');
            } else {
                $lines[] = 'DTSTART:'.$at->copy()->utc()->format('Ymd\THis\Z');
                $lines[] = 'DTEND:'.$at->copy()->utc()->addMinutes(30)->format('Ymd\THis\Z');
            }

            $lines[] = 'SUMMARY:'.$this->text(($event['done'] ? '✓ ' : '').$event['title']);
            $lines[] = 'DESCRIPTION:'.$this->text($description);

            if ($event['link'] !== null) {
                $lines[] = 'URL:'.$baseUrl.$event['link'];
            }

            $lines[] = 'STATUS:CONFIRMED';
            $lines[] = 'TRANSP:TRANSPARENT';
            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map($this->fold(...), $lines))."\r\n";
    }

    /**
     * What a calendar shows under the title: the kind and the task, who
     * handles it, the reminder's note and the link back. Also for Google.
     *
     * @param  array<string, mixed>  $event
     */
    public static function description(array $event, string $baseUrl): string
    {
        $label = $event['kind'] === 'task' ? 'Prazo' : 'Seguimento';

        return collect([
            $event['context'] !== null ? "{$label} · {$event['context']}" : $label,
            ($event['who']['name'] ?? null) !== null ? "Quem trata: {$event['who']['name']}" : null,
            $event['note'] ?? null,
            $event['link'] !== null ? $baseUrl.$event['link'] : null,
        ])->filter()->implode("\n");
    }

    private function text(string $value): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\,', '\n', '\n'], $value);
    }

    /** Lines longer than 75 octets continue on the next line, after a space (RFC 5545). */
    private function fold(string $line): string
    {
        $out = '';
        $current = '';

        foreach (mb_str_split($line) as $char) {
            if (strlen($current.$char) > 75) {
                $out .= $current."\r\n ";
                $current = '';
            }
            $current .= $char;
        }

        return $out.$current;
    }
}
