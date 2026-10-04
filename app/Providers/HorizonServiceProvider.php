<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Horizon shows every tenant's jobs, so outside local it is limited to the
     * platform operators listed in HORIZON_ALLOWED_EMAILS.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function (?User $user = null) {
            $allowed = array_filter(array_map('trim', explode(',', (string) config('horizon.allowed_emails'))));

            return $user !== null && in_array($user->email, $allowed, true);
        });
    }
}
