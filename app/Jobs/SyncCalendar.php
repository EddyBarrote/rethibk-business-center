<?php

namespace App\Jobs;

use App\Calendar\CalendarSync;
use App\Models\CalendarConnection;
use App\Tenancy\TenantAwareJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Str;
use Throwable;

/**
 * Brings one person's Google Calendar and Agenda into step (docs/DECISOES.md,
 * "Google Calendar por pessoa").
 */
class SyncCalendar extends TenantAwareJob implements ShouldBeUnique
{
    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 120;

    public function __construct(int $tenantId, public int $connectionId)
    {
        $this->tenantId = $tenantId;
    }

    public function uniqueId(): string
    {
        return (string) $this->connectionId;
    }

    public function handle(CalendarSync $sync): void
    {
        $connection = CalendarConnection::query()->with('user')->find($this->connectionId);

        if ($connection === null) {
            return;
        }

        try {
            $sync->run($connection);
        } catch (Throwable $e) {
            $connection->forceFill(['last_error' => Str::limit('Não foi possível sincronizar: '.$e->getMessage(), 480)])->save();
            report($e);
        }
    }
}
