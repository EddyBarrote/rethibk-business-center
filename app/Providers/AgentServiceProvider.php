<?php

namespace App\Providers;

use App\Ai\Runs\AgentRunner;
use App\Email\ImapMailboxFetcher;
use App\Email\MailboxFetcher;
use App\Enums\EmailStatus;
use App\Enums\RunStatus;
use App\Events\AgentRunFinished;
use App\Models\EmailMessage;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Events\StepCompleted;

/**
 * Wiring for the agent platform (E02) and its email (E03).
 */
class AgentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Holds the cost of the run in progress for the per-run cap.
        $this->app->singleton(AgentRunner::class);
        $this->app->bind(MailboxFetcher::class, ImapMailboxFetcher::class);
    }

    public function boot(): void
    {
        Event::listen(StepCompleted::class, fn (StepCompleted $event) => $this->app->make(AgentRunner::class)->onStepCompleted($event));

        // An email handed to an agent is triaged once its run is over.
        Event::listen(AgentRunFinished::class, function (AgentRunFinished $event): void {
            $source = $event->run->triggerSource;

            if ($source instanceof EmailMessage && $source->status === EmailStatus::Processing) {
                $source->forceFill(['status' => $event->run->status === RunStatus::Failed ? EmailStatus::Failed : EmailStatus::Processed])->save();
            }
        });
    }
}
