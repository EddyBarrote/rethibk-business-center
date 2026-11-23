<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * GET /up for uptime monitors (docs/DEPLOY.md): 200 when the platform can
 * answer, 503 naming what is down. Only the database and Redis (queues,
 * cache and sessions) are checked: both are local and cheap. The AI
 * providers, mailboxes and the ERP are on Definições › Estado da configuração.
 */
final class UptimeController
{
    public function __invoke(): Response
    {
        $down = [];

        try {
            DB::select('select 1');
        } catch (Throwable) {
            $down[] = 'database';
        }

        if (config('queue.default') === 'redis') {
            try {
                Redis::connection((string) config('queue.connections.redis.connection', 'default'))->ping();
            } catch (Throwable) {
                $down[] = 'redis';
            }
        }

        return response($down === [] ? 'ok' : 'down: '.implode(', ', $down), $down === [] ? 200 : 503)
            ->header('Content-Type', 'text/plain; charset=UTF-8')
            ->header('Cache-Control', 'no-store');
    }
}
