<?php

namespace App\Http\Controllers;

use App\Agenda\Agenda;
use App\Agenda\IcsFeed;
use App\Models\AuditLog;
use App\Models\FollowUp;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Agenda (docs/DECISOES.md, "Agenda"): one calendar for people and
 * agents, built from reminders, task deadlines and routines; a person adds
 * their own reminders and may subscribe to it from Google or Outlook.
 */
class AgendaController extends Controller
{
    /** The «tipo» filter, as the address writes it. */
    private const KINDS = ['seguimentos' => 'follow_up', 'prazos' => 'task', 'rotinas' => 'routine'];

    public function __construct(private readonly Agenda $agenda) {}

    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $timezone = $this->timezone();
        $view = $request->query('vista') === 'semana' ? 'semana' : 'mes';
        $everyone = $request->query('quem') === 'todos' && Agenda::canSeeEveryone($user);

        try {
            $day = Carbon::createFromFormat('Y-m-d', (string) $request->query('data', ''), $timezone)->startOfDay();
        } catch (\Throwable) {
            $day = now($timezone)->startOfDay();
        }

        [$from, $to] = $view === 'semana'
            ? [$day->copy()->startOfWeek(), $day->copy()->endOfWeek()]
            : [$day->copy()->startOfMonth()->startOfWeek(), $day->copy()->endOfMonth()->endOfWeek()];

        $events = $this->agenda->events($user, $from->copy()->utc(), $to->copy()->utc(), $everyone);
        $filters = [
            'tipo' => in_array($request->query('tipo'), array_keys(self::KINDS), true) ? (string) $request->query('tipo') : null,
            'pessoa' => preg_match('/^(user|agent)-\d+$/', (string) $request->query('pessoa')) === 1 ? (string) $request->query('pessoa') : null,
            'estado' => in_array($request->query('estado'), ['por-fazer', 'em-atraso', 'feitos'], true) ? (string) $request->query('estado') : null,
        ];

        return Inertia::render('Agenda/Index', [
            'events' => $this->filter($events, $filters),
            'filters' => $filters,
            'people' => $this->people($events),
            'view' => $view,
            'date' => $day->toDateString(),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'today' => now($timezone)->toDateString(),
            'everyone' => $everyone,
            'can_see_everyone' => Agenda::canSeeEveryone($user),
            'timezone' => $timezone,
            'has_feed' => User::query()->whereKey($user->id)->whereNotNull('calendar_token')->exists(),
            'feed_url' => $request->session()->get('calendar_feed_url'),
        ]);
    }

    /**
     * A reminder of the person's own.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'date' => 'required|date_format:Y-m-d',
            'time' => 'nullable|date_format:H:i',
            'note' => 'nullable|string|max:4000',
        ]);

        FollowUp::query()->create([
            'user_id' => $user->id,
            'title' => $data['title'],
            'note' => $data['note'] ?? null,
            'due_at' => $this->due($data['date'], $data['time'] ?? null),
        ]);

        return back()->with('success', 'Lembrete criado.');
    }

    /**
     * Done, not done, or another day.
     */
    public function update(Request $request, FollowUp $followUp): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless(Agenda::canEdit($user, $followUp), 403, 'Este seguimento não é seu.');

        $data = $request->validate([
            'done' => 'sometimes|boolean',
            'date' => 'required_with:time|date_format:Y-m-d',
            'time' => 'nullable|date_format:H:i',
        ]);

        if (array_key_exists('done', $data)) {
            $followUp->done_at = $data['done'] ? now() : null;
        }

        if (isset($data['date'])) {
            // Moved to another day, it is due again and is announced again.
            $followUp->due_at = $this->due($data['date'], $data['time'] ?? null);
            $followUp->notified_at = null;
            $followUp->done_at = null;
        }

        $followUp->save();

        AuditLog::record($user, 'follow_up.updated', ['follow_up_id' => $followUp->id, 'done' => $followUp->done_at !== null, 'due_at' => $followUp->due_at->toIso8601String()], subject: $followUp);

        return back()->with('success', $followUp->done_at !== null ? 'Marcado como feito.' : 'Seguimento actualizado.');
    }

    public function destroy(Request $request, FollowUp $followUp): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($followUp->user_id === $user->id && $followUp->agent_id === null, 403, 'Só apaga os lembretes que criou.');

        $followUp->delete();

        return back()->with('success', 'Lembrete apagado.');
    }

    /**
     * A new private link to subscribe to; the old one stops working. Only
     * its hash is kept, so the link is shown this once.
     */
    public function createFeed(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $token = Str::random(48);
        $user->forceFill(['calendar_token' => hash('sha256', $token)])->save();

        AuditLog::record($user, 'agenda.feed_created', [], subject: $user);

        return back()->with('calendar_feed_url', route('agenda.feed', ['token' => $token]));
    }

    public function destroyFeed(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $user->forceFill(['calendar_token' => null])->save();

        AuditLog::record($user, 'agenda.feed_revoked', [], subject: $user);

        return back()->with('success', 'O link deixou de funcionar.');
    }

    /**
     * The feed itself: no session, the link is the key.
     */
    public function feed(Request $request, IcsFeed $ics, string $token): HttpResponse
    {
        $user = User::query()->where('calendar_token', hash('sha256', $token))->where('is_active', true)->first();
        abort_if($user === null, 404);

        return response($ics->render($user, $request->getSchemeAndHttpHost()), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="agenda.ics"',
            'Cache-Control' => 'private, max-age=900',
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @param  array{tipo: string|null, pessoa: string|null, estado: string|null}  $filters
     * @return list<array<string, mixed>>
     */
    private function filter(array $events, array $filters): array
    {
        return array_values(array_filter($events, fn (array $event) => ($filters['tipo'] === null || $event['kind'] === self::KINDS[$filters['tipo']])
            && ($filters['pessoa'] === null || ($event['who'] !== null && "{$event['who']['kind']}-{$event['who']['id']}" === $filters['pessoa']))
            && match ($filters['estado']) {
                'por-fazer' => ! $event['done'] && $event['kind'] !== 'routine',
                'em-atraso' => $event['overdue'],
                'feitos' => $event['done'],
                default => true,
            }));
    }

    /**
     * Who handles something in the period, people first: the «Pessoa ou agente» filter.
     *
     * @param  list<array<string, mixed>>  $events
     * @return list<array{key: string, kind: string, name: string, count: int}>
     */
    private function people(array $events): array
    {
        return collect($events)->pluck('who')->filter()
            ->groupBy(fn (array $who) => "{$who['kind']}-{$who['id']}")
            ->map(fn ($group, string $key) => ['key' => $key, 'kind' => $group[0]['kind'], 'name' => $group[0]['name'], 'count' => $group->count()])
            ->sortBy([['kind', 'desc'], ['name', 'asc']])
            ->values()
            ->all();
    }

    private function due(string $date, ?string $time): Carbon
    {
        return Carbon::createFromFormat('Y-m-d H:i', $date.' '.($time ?? '00:00'), $this->timezone())->utc();
    }

    private function timezone(): string
    {
        return (string) config('agents.schedule_timezone');
    }
}
