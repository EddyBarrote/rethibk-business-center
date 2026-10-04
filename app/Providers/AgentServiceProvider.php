<?php

namespace App\Providers;

use App\Ai\Runs\AgentRunner;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Events\StepCompleted;

/**
 * Wiring for the agent platform (E02).
 */
class AgentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Holds the cost of the run in progress for the per-run cap.
        $this->app->singleton(AgentRunner::class);
    }

    public function boot(): void
    {
        Event::listen(StepCompleted::class, fn (StepCompleted $event) => $this->app->make(AgentRunner::class)->onStepCompleted($event));
    }
}
