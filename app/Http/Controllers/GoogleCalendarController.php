<?php

namespace App\Http\Controllers;

use App\Calendar\CalendarSync;
use App\Calendar\GoogleCalendar;
use App\Jobs\SyncCalendar;
use App\Models\AuditLog;
use App\Models\CalendarConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * A person connects their own Google Calendar (docs/DECISOES.md, "Google
 * Calendar por pessoa"). Google sends everyone back to one address on the
 * central domain; the state names the tenant and the person, once, for ten
 * minutes, so the callback needs no session.
 */
class GoogleCalendarController extends Controller
{
    private const STATE = 'google-calendar-oauth:';

    public function __construct(private readonly GoogleCalendar $google) {}

    public function connect(Request $request): RedirectResponse|Response
    {
        $user = $this->user($request);
        abort_unless(GoogleCalendar::configured(), 404);

        $state = Str::random(48);
        Cache::put(self::STATE.$state, ['tenant_id' => Tenant::current()?->id, 'user_id' => $user->id], now()->addMinutes(10));

        return redirect()->away($this->google->authUrl($state, $user->email));
    }

    /**
     * Google's answer, on the central domain: outside any tenant until the state says which.
     */
    public function callback(Request $request, TenantManager $tenants): RedirectResponse|Response
    {
        $pending = Cache::pull(self::STATE.$request->string('state'));

        if (! is_array($pending) || ($tenant = Tenant::query()->find($pending['tenant_id'] ?? null)) === null) {
            return response('Este pedido de ligação ao Google expirou. Volte à Agenda e tente de novo.', 400);
        }

        $back = fn (string $result) => redirect()->away($tenant->url().'/agenda?google='.$result);

        if ($request->filled('error') || ! $request->filled('code')) {
            return $back('cancelado');
        }

        return $tenants->run($tenant, function () use ($request, $pending, $back) {
            $user = User::query()->find($pending['user_id']);

            if ($user === null) {
                return $back('erro');
            }

            try {
                $tokens = $this->google->exchange((string) $request->string('code'));
            } catch (Throwable $e) {
                report($e);

                return $back('erro');
            }

            // Both calendar permissions are needed; Google lets the person untick them.
            if (array_diff(array_slice(GoogleCalendar::SCOPES, 2), $tokens['scopes']) !== []) {
                $this->google->revoke(new CalendarConnection(['access_token' => $tokens['access_token'], 'refresh_token' => $tokens['refresh_token']]));

                return $back('sem-permissoes');
            }

            $connection = CalendarConnection::query()->firstOrNew(['user_id' => $user->id, 'provider' => 'google']);
            $connection->fill([
                'account_email' => $tokens['email'],
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'] ?? $connection->refresh_token,
                'token_expires_at' => $tokens['expires_at'],
                'last_error' => null,
            ])->save();

            AuditLog::record($user, 'calendar.connected', ['provider' => 'google', 'account' => $tokens['email']], subject: $user);
            SyncCalendar::dispatch($connection->tenant_id, $connection->id);

            return $back('ligado');
        });
    }

    public function sync(Request $request): RedirectResponse
    {
        $connection = $this->connection($request);
        SyncCalendar::dispatch($connection->tenant_id, $connection->id);

        return back()->with('success', 'A sincronizar com o Google Calendar. Os eventos aparecem dentro de momentos.');
    }

    public function destroy(Request $request, CalendarSync $sync): RedirectResponse
    {
        $user = $this->user($request);
        $connection = $this->connection($request);
        $account = $connection->account_email;
        $sync->disconnect($connection);

        AuditLog::record($user, 'calendar.disconnected', ['provider' => 'google', 'account' => $account], subject: $user);

        return back()->with('success', 'Google Calendar desligado. O calendário da plataforma foi apagado da sua conta.');
    }

    private function connection(Request $request): CalendarConnection
    {
        return CalendarConnection::query()
            ->where('user_id', $this->user($request)->id)
            ->where('provider', 'google')
            ->firstOrFail();
    }
}
