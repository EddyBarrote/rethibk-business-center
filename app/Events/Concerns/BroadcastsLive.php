<?php

namespace App\Events\Concerns;

use Illuminate\Broadcasting\BroadcastException;

/**
 * Live updates are a convenience: when Reverb is down, the run, approval or
 * audit row is already saved and must not fail because the console could not
 * be told. The failure is reported, and the console catches up on reload.
 */
trait BroadcastsLive
{
    public static function live(mixed ...$arguments): void
    {
        try {
            event(new static(...$arguments));
        } catch (BroadcastException $e) {
            report($e);
        }
    }
}
