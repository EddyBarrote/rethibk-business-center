<?php

namespace App\Calendar;

use App\Agenda\Agenda;
use App\Agenda\IcsFeed;
use App\Models\CalendarConnection;
use App\Models\CalendarEvent;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * Keeps a person's Google Calendar and their Agenda in step (docs/DECISOES.md,
 * "Google Calendar por pessoa"):
 *
 * - in: the events of their primary calendar, 60 days back to a year ahead,
 *   shown in their Agenda and to nobody else;
 * - out: their reminders and task deadlines ("O meu", as the .ics link),
 *   written to a calendar the platform creates in their account. Their own
 *   calendar is never written to.
 */
final class CalendarSync
{
    public function __construct(private readonly GoogleCalendar $google, private readonly Agenda $agenda) {}

    public function run(CalendarConnection $connection): void
    {
        try {
            $this->pull($connection);
            $this->push($connection);
            $connection->forceFill(['last_synced_at' => now(), 'last_error' => null])->save();
        } catch (CalendarAccessRevoked $e) {
            $connection->forceFill(['last_error' => $e->getMessage().' Volte a ligar o Google Calendar.'])->save();
        }
    }

    public function pull(CalendarConnection $connection): void
    {
        $result = $this->google->events($connection, 'primary', $connection->sync_token, now()->subDays(60), now()->addYear());

        if ($result['reset']) {
            // Google forgot the token: read the whole window again.
            $connection->events()->where('direction', CalendarEvent::IN)->delete();
            $connection->forceFill(['sync_token' => null])->save();
            $result = $this->google->events($connection, 'primary', null, now()->subDays(60), now()->addYear());
        }

        $timezone = $this->timezone();

        foreach ($result['items'] as $item) {
            $id = (string) ($item['id'] ?? '');

            if ($id === '') {
                continue;
            }

            if (($item['status'] ?? null) === 'cancelled') {
                $connection->events()->where('direction', CalendarEvent::IN)->where('external_id', $id)->delete();

                continue;
            }

            $allDay = isset($item['start']['date']);
            $starts = $allDay ? Carbon::parse($item['start']['date'], $timezone) : Carbon::parse($item['start']['dateTime'] ?? 'now');
            $ends = $allDay
                ? Carbon::parse($item['end']['date'] ?? $item['start']['date'], $timezone)
                : Carbon::parse($item['end']['dateTime'] ?? $starts);

            $connection->events()->updateOrCreate(
                ['direction' => CalendarEvent::IN, 'external_id' => $id],
                [
                    'user_id' => $connection->user_id,
                    'title' => mb_substr((string) ($item['summary'] ?? '(sem título)'), 0, 255),
                    'location' => isset($item['location']) ? mb_substr((string) $item['location'], 0, 255) : null,
                    'starts_at' => $starts->utc(),
                    'ends_at' => $ends->utc(),
                    'all_day' => $allDay,
                    'html_link' => isset($item['htmlLink']) ? mb_substr((string) $item['htmlLink'], 0, 1000) : null,
                ],
            );
        }

        $connection->forceFill(['sync_token' => $result['sync_token']])->save();
    }

    public function push(CalendarConnection $connection): void
    {
        $timezone = $this->timezone();
        $tenant = Tenant::current();
        $baseUrl = $tenant?->url() ?? (string) config('app.url');

        if ($connection->platform_calendar_id === null) {
            $connection->forceFill([
                'platform_calendar_id' => $this->google->createCalendar($connection, 'Agenda · '.($tenant->name ?? config('app.name')), $timezone),
            ])->save();
            $connection->events()->where('direction', CalendarEvent::OUT)->delete();
        }

        $calendar = (string) $connection->platform_calendar_id;
        $items = $this->agenda->events($connection->user, now()->subDays(60), now()->addYear(), routines: false, google: false);
        $sent = $connection->events()->where('direction', CalendarEvent::OUT)->get()->keyBy('source_key');
        $seen = [];

        foreach ($items as $item) {
            $key = (string) $item['key'];
            $seen[$key] = true;
            $body = $this->body($item, $baseUrl, $timezone);
            $hash = hash('sha256', (string) json_encode($body));
            $row = $sent->get($key);

            if ($row !== null && $row->source_hash === $hash) {
                continue;
            }

            $id = $this->google->putEvent($connection, $calendar, $row?->external_id, $body);

            if ($id === null) {
                // The person deleted the platform's calendar: make it again next time.
                $connection->forceFill(['platform_calendar_id' => null])->save();
                $connection->events()->where('direction', CalendarEvent::OUT)->delete();

                return;
            }

            $connection->events()->updateOrCreate(
                ['direction' => CalendarEvent::OUT, 'source_key' => $key],
                [
                    'user_id' => $connection->user_id,
                    'external_id' => $id,
                    'source_hash' => $hash,
                    'title' => mb_substr((string) $item['title'], 0, 255),
                    'starts_at' => Carbon::parse($item['at'])->utc(),
                    'all_day' => (bool) $item['all_day'],
                ],
            );
        }

        // Done, deleted or no longer theirs: off the calendar.
        foreach ($sent as $key => $row) {
            if (! isset($seen[$key])) {
                $this->google->deleteEvent($connection, $calendar, $row->external_id);
                $row->delete();
            }
        }
    }

    /**
     * Takes the connection off: the platform's calendar goes, Google forgets the access.
     */
    public function disconnect(CalendarConnection $connection): void
    {
        if ($connection->platform_calendar_id !== null) {
            rescue(fn () => $this->google->deleteCalendar($connection, (string) $connection->platform_calendar_id), report: false);
        }

        $this->google->revoke($connection);
        $connection->delete();
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function body(array $item, string $baseUrl, string $timezone): array
    {
        $at = Carbon::parse($item['at']);
        [$start, $end] = $item['all_day']
            ? [['date' => $at->copy()->setTimezone($timezone)->toDateString()], ['date' => $at->copy()->setTimezone($timezone)->addDay()->toDateString()]]
            : [['dateTime' => $at->copy()->utc()->toIso8601String()], ['dateTime' => $at->copy()->utc()->addMinutes(30)->toIso8601String()]];

        return array_filter([
            'summary' => ($item['done'] ? '✓ ' : '').$item['title'],
            'description' => IcsFeed::description($item, $baseUrl),
            'start' => $start,
            'end' => $end,
            'transparency' => 'transparent',
            'source' => $item['link'] !== null ? ['title' => 'Abrir na plataforma', 'url' => $baseUrl.$item['link']] : null,
        ]);
    }

    private function timezone(): string
    {
        return (string) config('agents.schedule_timezone');
    }
}
