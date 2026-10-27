<?php

use App\Calendar\CalendarSync;
use App\Jobs\SyncCalendar;
use App\Models\CalendarConnection;
use App\Models\CalendarEvent;
use App\Models\FollowUp;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

// docs/DECISOES.md, "Google Calendar por pessoa".

beforeEach(function () {
    Queue::fake();
    Carbon::setTestNow('2026-10-27 08:00:00');
    config([
        'services.google_calendar.client_id' => 'client-id',
        'services.google_calendar.client_secret' => 'client-secret',
        'services.google_calendar.redirect' => null,
        'app.url' => 'http://localhost',
    ]);
    $this->tenant = Tenant::factory()->create();

    [$this->barrote, $this->naira] = asTenant($this->tenant, fn () => [
        User::factory()->owner()->create(['name' => 'Barrote', 'email' => 'barrote@example.com']),
        User::factory()->create(['name' => 'Naira']),
    ]);
});

afterEach(fn () => Carbon::setTestNow());

function googleIdToken(string $email): string
{
    return 'x.'.rtrim(strtr(base64_encode((string) json_encode(['email' => $email])), '+/', '-_'), '=').'.y';
}

it('sends the person to Google and keeps their account when Google sends them back', function () {
    $response = $this->actingAs($this->barrote)->get(tenantUrl($this->tenant, 'agenda/google/connect'));

    $location = (string) $response->headers->get('Location');
    expect($location)->toStartWith('https://accounts.google.com/o/oauth2/v2/auth?')
        ->toContain('calendar.events.readonly')->toContain('calendar.app.created')
        ->toContain(urlencode('http://localhost/oauth/google/callback'));
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    Http::fake(['oauth2.googleapis.com/token' => Http::response([
        'access_token' => 'access', 'refresh_token' => 'refresh', 'expires_in' => 3599,
        'scope' => 'openid email https://www.googleapis.com/auth/calendar.events.readonly https://www.googleapis.com/auth/calendar.app.created',
        'id_token' => googleIdToken('barrote@gmail.com'),
    ])]);

    // Google answers on the central domain, outside the tenant and without a session.
    $this->get('http://localhost/oauth/google/callback?code=abc&state='.$query['state'])
        ->assertRedirect($this->tenant->url().'/agenda?google=ligado');

    asTenant($this->tenant, function () {
        $connection = CalendarConnection::query()->sole();
        expect($connection->user_id)->toBe($this->barrote->id)
            ->and($connection->account_email)->toBe('barrote@gmail.com')
            ->and($connection->refresh_token)->toBe('refresh')
            ->and($connection->getRawOriginal('refresh_token'))->not->toBe('refresh');
    });
    Queue::assertPushed(SyncCalendar::class);

    // The state works once.
    $this->get('http://localhost/oauth/google/callback?code=abc&state='.$query['state'])->assertStatus(400);
});

it('refuses a connection without both calendar permissions', function () {
    Cache::put('google-calendar-oauth:state', ['tenant_id' => $this->tenant->id, 'user_id' => $this->barrote->id], now()->addMinutes(10));
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 3599, 'scope' => 'openid email']),
        'oauth2.googleapis.com/revoke' => Http::response(),
    ]);

    $this->get('http://localhost/oauth/google/callback?code=abc&state=state')
        ->assertRedirect($this->tenant->url().'/agenda?google=sem-permissoes');

    expect(asTenant($this->tenant, fn () => CalendarConnection::query()->count()))->toBe(0);
});

it('brings the person\'s events in, only for them, and writes their reminders to the platform\'s calendar', function () {
    $connection = asTenant($this->tenant, function () {
        FollowUp::factory()->create(['user_id' => $this->barrote->id, 'title' => 'Ligar à TVCabo', 'due_at' => Carbon::parse('2026-10-28 10:00')]);
        FollowUp::factory()->create(['user_id' => $this->naira->id, 'title' => 'Lembrete da Naira', 'due_at' => Carbon::parse('2026-10-28 11:00')]);

        return CalendarConnection::factory()->create(['user_id' => $this->barrote->id]);
    });

    Http::fake([
        'www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response([
            'items' => [
                ['id' => 'ev1', 'status' => 'confirmed', 'summary' => 'Reunião com a Olankha', 'location' => 'Maputo',
                    'start' => ['dateTime' => '2026-10-28T09:00:00+02:00'], 'end' => ['dateTime' => '2026-10-28T10:00:00+02:00'], 'htmlLink' => 'https://calendar.google.com/ev1'],
                ['id' => 'ev2', 'status' => 'confirmed', 'summary' => 'Feriado', 'start' => ['date' => '2026-10-29'], 'end' => ['date' => '2026-10-30']],
            ],
            'nextSyncToken' => 'sync-1',
        ]),
        'www.googleapis.com/calendar/v3/calendars' => Http::response(['id' => 'platform-cal']),
        'www.googleapis.com/calendar/v3/calendars/platform-cal/events' => Http::response(['id' => 'out-1']),
    ]);

    asTenant($this->tenant, fn () => app(CalendarSync::class)->run($connection->fresh()));

    asTenant($this->tenant, function () use ($connection) {
        $connection->refresh();
        expect($connection->sync_token)->toBe('sync-1')
            ->and($connection->platform_calendar_id)->toBe('platform-cal')
            ->and($connection->last_error)->toBeNull()
            ->and(CalendarEvent::query()->where('direction', 'in')->pluck('title')->all())->toEqualCanonicalizing(['Reunião com a Olankha', 'Feriado'])
            // Only Barrote's own reminder goes out; Google's events never go back.
            ->and(CalendarEvent::query()->where('direction', 'out')->pluck('title')->all())->toBe(['Ligar à TVCabo']);
    });

    Http::assertSent(fn (Request $request) => $request->url() === 'https://www.googleapis.com/calendar/v3/calendars/platform-cal/events'
        && $request['summary'] === 'Ligar à TVCabo' && str_contains((string) $request['description'], '/agenda') === false);

    $titles = fn (User $user) => collect($this->actingAs($user)->get(tenantUrl($this->tenant, 'agenda?data=2026-10-28'))
        ->viewData('page')['props']['events'])->pluck('title')->all();

    expect($titles($this->barrote))->toContain('Reunião com a Olankha', 'Feriado')
        ->and($titles($this->naira))->not->toContain('Reunião com a Olankha');
});

it('takes an event off Google when the reminder is done and gone, and drops cancelled events', function () {
    [$connection, $followUp] = asTenant($this->tenant, function () {
        $connection = CalendarConnection::factory()->create(['user_id' => $this->barrote->id, 'platform_calendar_id' => 'platform-cal', 'sync_token' => 'sync-1']);
        CalendarEvent::factory()->create(['calendar_connection_id' => $connection->id, 'external_id' => 'ev1']);
        $followUp = FollowUp::factory()->create(['user_id' => $this->barrote->id, 'title' => 'Ligar à TVCabo']);
        CalendarEvent::factory()->create(['calendar_connection_id' => $connection->id, 'direction' => 'out', 'external_id' => 'out-1', 'source_key' => "follow_up-{$followUp->id}"]);

        return [$connection, $followUp];
    });
    asTenant($this->tenant, fn () => $followUp->delete());

    Http::fake([
        'www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response(['items' => [['id' => 'ev1', 'status' => 'cancelled']], 'nextSyncToken' => 'sync-2']),
        'www.googleapis.com/calendar/v3/calendars/platform-cal/events/out-1' => Http::response(null, 204),
    ]);

    asTenant($this->tenant, fn () => app(CalendarSync::class)->run($connection->fresh()));

    expect(asTenant($this->tenant, fn () => CalendarEvent::query()->count()))->toBe(0);
    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/events/out-1'));
});

it('says when Google no longer gives access', function () {
    $connection = asTenant($this->tenant, fn () => CalendarConnection::factory()->create(['user_id' => $this->barrote->id, 'token_expires_at' => now()->subMinute()]));
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);

    asTenant($this->tenant, fn () => app(CalendarSync::class)->run($connection->fresh()));

    expect(asTenant($this->tenant, fn () => $connection->fresh()->last_error))->toContain('Volte a ligar');
});

it('disconnects: the platform\'s calendar goes and Google forgets the access', function () {
    asTenant($this->tenant, fn () => CalendarConnection::factory()->create(['user_id' => $this->barrote->id, 'platform_calendar_id' => 'platform-cal']));
    Http::fake([
        'www.googleapis.com/calendar/v3/calendars/platform-cal' => Http::response(null, 204),
        'oauth2.googleapis.com/revoke' => Http::response(),
    ]);

    $this->actingAs($this->barrote)->delete(tenantUrl($this->tenant, 'agenda/google'))->assertRedirect();

    expect(asTenant($this->tenant, fn () => CalendarConnection::query()->count()))->toBe(0);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'oauth2.googleapis.com/revoke'));
});

it('hides the option when the platform has no Google client', function () {
    config(['services.google_calendar.client_id' => null]);

    $this->actingAs($this->barrote)->get(tenantUrl($this->tenant, 'agenda'))
        ->assertInertia(fn (Assert $page) => $page->where('google', null));
    $this->actingAs($this->barrote)->get(tenantUrl($this->tenant, 'agenda/google/connect'))->assertNotFound();
});
