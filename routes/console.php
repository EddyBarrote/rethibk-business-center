<?php

use Illuminate\Support\Facades\Schedule;

// Scheduled commands (section 14.2 of docs/SPEC.md) are added with the
// deliveries that implement them. Each one iterates active tenants through
// TenantManager::eachActive() and dispatches per tenant.

Schedule::command('erp:health-check')->everyFifteenMinutes()->withoutOverlapping();

Schedule::command('agents:run-routines')->everyMinute()->withoutOverlapping();
Schedule::command('mail:fetch')->everyMinute()->withoutOverlapping();
Schedule::command('mail:prune')->dailyAt('02:30');

// E03 to E08: watchers that hand work to the agent playing each role.
Schedule::command('agents:scan-tenders')->hourly()->withoutOverlapping();
Schedule::command('agents:watch-deadlines')->hourly()->withoutOverlapping();
Schedule::command('followups:notify')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('agents:daily-briefing')->weekdays()->at('06:30')->timezone('Africa/Maputo');
Schedule::command('agents:daily-briefing --weekly')->mondays()->at('07:00')->timezone('Africa/Maputo');
Schedule::command('agents:chase-receivables')->weekdays()->at('09:00')->timezone('Africa/Maputo');
Schedule::command('agents:watch-contracts')->dailyAt('07:00')->timezone('Africa/Maputo');
Schedule::command('agents:watch-sla')->hourly()->withoutOverlapping();
Schedule::command('agents:cost-rollup')->dailyAt('23:50')->timezone('Africa/Maputo');
