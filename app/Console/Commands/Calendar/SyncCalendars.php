<?php

namespace App\Console\Commands\Calendar;

use App\Calendar\GoogleCalendar;
use App\Jobs\SyncCalendar;
use App\Models\CalendarConnection;
use App\Tenancy\TenantManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('calendar:sync')]
#[Description('Sincroniza o Google Calendar de quem o ligou, em todos os tenants (agendado a cada 5 minutos)')]
class SyncCalendars extends Command
{
    public function handle(TenantManager $tenants): int
    {
        if (! GoogleCalendar::configured()) {
            return self::SUCCESS;
        }

        $count = 0;

        $tenants->eachActive(function () use (&$count): void {
            CalendarConnection::query()->whereNotNull('refresh_token')->each(function (CalendarConnection $connection) use (&$count): void {
                SyncCalendar::dispatch($connection->tenant_id, $connection->id);
                $count++;
            });
        });

        $this->components->info("{$count} calendário(s) em sincronização.");

        return self::SUCCESS;
    }
}
