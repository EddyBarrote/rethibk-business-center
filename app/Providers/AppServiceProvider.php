<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Tenancy\TenantManager;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantManager::class);
    }

    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());

        // The tenant's catalogue of capabilities, connectors and skills (docs/CAPACIDADES.md).
        Gate::define('manage-catalog', fn (User $user): bool => $user->hasPermission(Permission::ManageCatalog));

        // A long-running worker must never carry one job's tenant into the next.
        // A sync job runs inside its caller, whose tenant must survive it.
        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            if ($event->connectionName !== 'sync') {
                $this->app->make(TenantManager::class)->forget();
            }
        });

        // `composer dev` also runs the scheduler: mail fetch, routines, briefings and watchers.
        DevCommands::artisan('schedule:work', 'scheduler');

        // The reset link points at the host it was asked from, so it lands on the right tenant.
        ResetPassword::createUrlUsing(fn (User|PlatformAdmin $user, string $token) => route(
            $user instanceof PlatformAdmin ? 'admin.password.reset' : 'password.reset',
            ['token' => $token, 'email' => $user->email],
        ));
        ResetPassword::toMailUsing(fn (User|PlatformAdmin $user, string $token) => (new MailMessage)
            ->subject('Definir uma nova palavra-passe')
            ->greeting('Olá '.$user->name.',')
            ->line('Recebemos um pedido para definir uma nova palavra-passe para a sua conta.')
            ->action('Definir palavra-passe', (string) call_user_func(ResetPassword::$createUrlCallback, $user, $token))
            ->line('O link é válido durante '.config('auth.passwords.users.expire').' minutos. Se não fez este pedido, ignore este email.')
            ->salutation('Rethink Business Center'));

        RateLimiter::for('login', function (Request $request) {
            $email = strtolower((string) $request->input('email'));

            return [
                Limit::perMinute(5)->by($request->getHost().'|'.$email.'|'.$request->ip()),
                Limit::perMinute(20)->by($request->ip()),
            ];
        });
    }
}
