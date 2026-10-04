<?php

use Illuminate\Support\Facades\Schedule;

// Scheduled commands (section 14.2 of docs/SPEC.md) are added with the
// deliveries that implement them. Each one iterates active tenants through
// TenantManager::eachActive() and dispatches per tenant.

Schedule::command('erp:health-check')->everyFifteenMinutes()->withoutOverlapping();

Schedule::command('agents:run-routines')->everyMinute()->withoutOverlapping();
Schedule::command('mail:fetch')->everyMinute()->withoutOverlapping();
Schedule::command('mail:prune')->dailyAt('02:30');
