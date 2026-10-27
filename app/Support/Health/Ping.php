<?php

namespace App\Support\Health;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

/** The smallest real request to the AI provider, for the health check. */
final class Ping implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'Responde apenas com a palavra OK.';
    }
}
