<?php

namespace App\Calendar;

use App\Models\CalendarConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Google's OAuth and Calendar API, over plain HTTP (docs/DECISOES.md, "Google
 * Calendar por pessoa"). The scopes are the least that does the job: read the
 * events of the person's calendars, and manage only the calendars this app
 * creates. Nothing here can change an event of the person's own calendar.
 */
final class GoogleCalendar
{
    private const AUTH = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN = 'https://oauth2.googleapis.com/token';

    private const REVOKE = 'https://oauth2.googleapis.com/revoke';

    private const API = 'https://www.googleapis.com/calendar/v3';

    public const SCOPES = [
        'openid',
        'email',
        'https://www.googleapis.com/auth/calendar.events.readonly',
        'https://www.googleapis.com/auth/calendar.app.created',
    ];

    public static function configured(): bool
    {
        return filled(config('services.google_calendar.client_id')) && filled(config('services.google_calendar.client_secret'));
    }

    /**
     * One address for every tenant, on the central domain (Google wants each one registered).
     */
    public static function redirectUri(): string
    {
        $configured = (string) config('services.google_calendar.redirect');

        if ($configured !== '') {
            return $configured;
        }

        $appUrl = (string) config('app.url');
        $scheme = parse_url($appUrl, PHP_URL_SCHEME) ?: 'https';
        $port = parse_url($appUrl, PHP_URL_PORT);

        return $scheme.'://'.config('tenancy.central_domain').($port ? ':'.$port : '').'/oauth/google/callback';
    }

    public function authUrl(string $state, ?string $email = null): string
    {
        return self::AUTH.'?'.http_build_query(array_filter([
            'client_id' => config('services.google_calendar.client_id'),
            'redirect_uri' => self::redirectUri(),
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'access_type' => 'offline',
            // Always ask, so Google always returns a refresh token.
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'login_hint' => $email,
            'state' => $state,
        ]));
    }

    /**
     * The code from the callback, for tokens and the account's email.
     *
     * @return array{access_token: string, refresh_token: string|null, expires_at: Carbon, email: string|null, scopes: list<string>}
     */
    public function exchange(string $code): array
    {
        $data = Http::asForm()->timeout(20)->post(self::TOKEN, [
            'code' => $code,
            'client_id' => config('services.google_calendar.client_id'),
            'client_secret' => config('services.google_calendar.client_secret'),
            'redirect_uri' => self::redirectUri(),
            'grant_type' => 'authorization_code',
        ])->throw()->json();

        return [
            'access_token' => (string) $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? null,
            'expires_at' => now()->addSeconds((int) ($data['expires_in'] ?? 3600)),
            'email' => $this->emailFrom($data['id_token'] ?? null),
            'scopes' => explode(' ', (string) ($data['scope'] ?? '')),
        ];
    }

    /**
     * A valid access token, refreshed when it is about to expire.
     */
    public function token(CalendarConnection $connection): string
    {
        if ($connection->access_token !== null && $connection->token_expires_at?->isAfter(now()->addMinute())) {
            return $connection->access_token;
        }

        if ($connection->refresh_token === null) {
            throw new CalendarAccessRevoked('Sem autorização guardada.');
        }

        $response = Http::asForm()->timeout(20)->post(self::TOKEN, [
            'client_id' => config('services.google_calendar.client_id'),
            'client_secret' => config('services.google_calendar.client_secret'),
            'refresh_token' => $connection->refresh_token,
            'grant_type' => 'refresh_token',
        ]);

        // The person removed the access in their Google account, or it expired.
        if ($response->json('error') === 'invalid_grant') {
            throw new CalendarAccessRevoked('O Google já não dá acesso a esta conta.');
        }

        $data = $response->throw()->json();
        $connection->forceFill([
            'access_token' => (string) $data['access_token'],
            'token_expires_at' => now()->addSeconds((int) ($data['expires_in'] ?? 3600)),
        ])->save();

        return (string) $data['access_token'];
    }

    /**
     * The events of a calendar: the whole window the first time, then only
     * what changed since the sync token. A null token in the result means
     * Google forgot the old one and the window must be read again.
     *
     * @return array{items: list<array<string, mixed>>, sync_token: string|null, reset: bool}
     */
    public function events(CalendarConnection $connection, string $calendarId, ?string $syncToken, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $items = [];
        $page = null;

        do {
            $query = array_filter([
                'singleEvents' => 'true',
                'maxResults' => 250,
                'pageToken' => $page,
                'syncToken' => $syncToken,
                'timeMin' => $syncToken === null ? $from->format(DATE_RFC3339) : null,
                'timeMax' => $syncToken === null ? $to->format(DATE_RFC3339) : null,
                'showDeleted' => $syncToken !== null ? 'true' : null,
            ]);
            $response = $this->api($connection)->get(self::API.'/calendars/'.rawurlencode($calendarId).'/events', $query);

            if ($response->status() === 410) {
                return ['items' => [], 'sync_token' => null, 'reset' => true];
            }

            $data = $response->throw()->json();
            $items = [...$items, ...($data['items'] ?? [])];
            $page = $data['nextPageToken'] ?? null;
        } while ($page !== null);

        return ['items' => $items, 'sync_token' => $data['nextSyncToken'] ?? null, 'reset' => false];
    }

    public function createCalendar(CalendarConnection $connection, string $summary, string $timezone): string
    {
        return (string) $this->api($connection)->post(self::API.'/calendars', [
            'summary' => $summary,
            'timeZone' => $timezone,
            'description' => 'Seguimentos e prazos da plataforma de agentes. Escrito pela plataforma: o que mudar aqui é reposto.',
        ])->throw()->json('id');
    }

    /**
     * Writes an event to the app's calendar: a new one, or over the one it
     * had. Null when the calendar itself is gone (the person deleted it).
     *
     * @param  array<string, mixed>  $body
     */
    public function putEvent(CalendarConnection $connection, string $calendarId, ?string $eventId, array $body): ?string
    {
        $base = self::API.'/calendars/'.rawurlencode($calendarId).'/events';

        if ($eventId !== null) {
            $response = $this->api($connection)->put($base.'/'.rawurlencode($eventId), $body);

            if ($response->successful()) {
                return (string) $response->json('id');
            }

            if (! in_array($response->status(), [404, 410], true)) {
                $response->throw();
            }
        }

        $response = $this->api($connection)->post($base, $body);

        if ($response->status() === 404) {
            return null;
        }

        return (string) $response->throw()->json('id');
    }

    public function deleteEvent(CalendarConnection $connection, string $calendarId, string $eventId): void
    {
        $response = $this->api($connection)->delete(self::API.'/calendars/'.rawurlencode($calendarId).'/events/'.rawurlencode($eventId));

        if (! $response->successful() && ! in_array($response->status(), [404, 410], true)) {
            $response->throw();
        }
    }

    public function deleteCalendar(CalendarConnection $connection, string $calendarId): void
    {
        $response = $this->api($connection)->delete(self::API.'/calendars/'.rawurlencode($calendarId));

        if (! $response->successful() && ! in_array($response->status(), [404, 410], true)) {
            $response->throw();
        }
    }

    /** Best effort: the connection is removed here whatever Google answers. */
    public function revoke(CalendarConnection $connection): void
    {
        $token = $connection->refresh_token ?? $connection->access_token;

        if ($token !== null) {
            rescue(fn () => Http::asForm()->timeout(10)->post(self::REVOKE, ['token' => $token]), report: false);
        }
    }

    private function api(CalendarConnection $connection): PendingRequest
    {
        return Http::withToken($this->token($connection))->acceptJson()->timeout(30);
    }

    /** The email in the ID token Google just sent us over TLS (no need to check its signature). */
    private function emailFrom(?string $idToken): ?string
    {
        $parts = explode('.', (string) $idToken);

        if (count($parts) !== 3) {
            return null;
        }

        $claims = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true);

        return is_array($claims) && is_string($claims['email'] ?? null) ? $claims['email'] : null;
    }
}
