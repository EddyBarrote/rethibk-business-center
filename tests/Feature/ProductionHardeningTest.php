<?php

use App\Providers\AppServiceProvider;
use App\Tenancy\TenantProvisioner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;

// What the production install relies on (docs/DEPLOY.md and deploy/).

it('answers /up when the database is reachable', function () {
    $this->get('/up')
        ->assertOk()
        ->assertSeeText('ok')
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('answers /up with 503 naming the database when it is down', function () {
    $default = config('database.default');
    config([
        'database.connections.broken' => ['driver' => 'sqlite', 'database' => '/nonexistent/micomoc/up.sqlite', 'prefix' => ''],
        'database.default' => 'broken',
    ]);

    try {
        $response = $this->get('/up');
    } finally {
        config(['database.default' => $default]);
    }

    $response->assertStatus(503)->assertSeeText('down: database');
});

it('answers /up with 503 naming Redis when the queues run on it and it is down', function () {
    config([
        'queue.default' => 'redis',
        'database.redis.default.host' => '127.0.0.1',
        'database.redis.default.port' => 1,
        'database.redis.default.timeout' => 1,
    ]);
    Redis::purge('default');

    $this->get('/up')->assertStatus(503)->assertSeeText('down: redis');
});

it('trusts the forwarded scheme and client IP only from the proxies in TRUSTED_PROXIES', function () {
    Route::get('/_proxy-probe', fn (Request $request) => ($request->isSecure() ? 'https' : 'http').' '.$request->ip());
    $headers = ['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '41.220.1.2'];

    $this->get('/_proxy-probe', $headers)->assertSeeText('http 127.0.0.1');

    config(['trustedproxy.proxies' => '127.0.0.1']);
    $this->get('/_proxy-probe', $headers)->assertSeeText('https 41.220.1.2');
});

it('makes https links when APP_URL is https, and leaves them alone otherwise', function () {
    $provider = app()->getProvider(AppServiceProvider::class);

    config(['app.url' => 'http://micomoc.localhost:8000']);
    $provider->configureUrls();
    expect(url('/settings'))->toStartWith('http://');

    config(['app.url' => 'https://plataforma.example.co.mz']);
    $provider->configureUrls();
    expect(url('/settings'))->toStartWith('https://');
});

it('marks the session cookie secure by default when APP_URL is https', function () {
    $saved = [];
    foreach (['APP_URL', 'SESSION_SECURE_COOKIE'] as $name) {
        $saved[$name] = [$_SERVER[$name] ?? null, $_ENV[$name] ?? null, getenv($name)];
        unset($_SERVER[$name], $_ENV[$name]);
        putenv($name);
    }
    $secure = function (?string $appUrl, ?string $flag): mixed {
        foreach (['APP_URL' => $appUrl, 'SESSION_SECURE_COOKIE' => $flag] as $name => $value) {
            if ($value === null) {
                unset($_SERVER[$name], $_ENV[$name]);
            } else {
                $_SERVER[$name] = $_ENV[$name] = $value;
            }
        }

        return (require config_path('session.php'))['secure'];
    };

    try {
        expect($secure('https://plataforma.example.co.mz', null))->toBeTrue()
            ->and($secure('http://micomoc.localhost:8000', null))->toBeFalse()
            ->and($secure('https://plataforma.example.co.mz', 'false'))->toBeFalse()
            ->and($secure('http://micomoc.localhost:8000', 'true'))->toBeTrue();
    } finally {
        foreach ($saved as $name => [$server, $env, $process]) {
            unset($_SERVER[$name], $_ENV[$name]);
            if ($server !== null) {
                $_SERVER[$name] = $server;
            }
            if ($env !== null) {
                $_ENV[$name] = $env;
            }
            putenv($process === false ? $name : "{$name}={$process}");
        }
    }
});

it('never hands a running job to a second worker: retry_after outlasts every job and supervisor timeout', function () {
    $retryAfter = (int) config('queue.connections.redis.retry_after');
    $timeouts = collect(config('horizon.defaults'))->pluck('timeout', 'queue.0')->all();

    foreach (Finder::create()->files()->in(app_path('Jobs'))->name('*.php') as $file) {
        $class = 'App\\Jobs\\'.Str::replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
        $default = (new ReflectionClass($class))->getDefaultProperties()['timeout'] ?? null;
        if (is_int($default)) {
            $timeouts[$class] = $default;
        }
    }

    expect($timeouts)->toHaveKey('App\Jobs\SyncErpSales');
    foreach ($timeouts as $what => $timeout) {
        expect($retryAfter)->toBeGreaterThan($timeout, "retry_after ({$retryAfter} s) must outlast {$what} ({$timeout} s)");
    }
});

it('runs every queue the platform uses in production and in staging', function () {
    $used = ['default', 'email', 'embeddings', 'agents-high', ...array_values(config('agents.queues'))];
    $served = collect(config('horizon.defaults'))->pluck('queue')->flatten()->all();

    expect(array_diff($used, $served))->toBe([]);

    foreach (['production', 'staging'] as $environment) {
        expect(config("horizon.environments.{$environment}"))->toBeArray();
        foreach (config("horizon.environments.{$environment}") as $supervisor => $options) {
            expect(config("horizon.defaults.{$supervisor}"))->toBeArray()
                ->and($options['maxProcesses'] ?? config("horizon.defaults.{$supervisor}.maxProcesses"))->toBeGreaterThan(0);
        }
    }
});

it('keeps ws, the websocket host behind Nginx, out of tenant slugs', function () {
    expect(Validator::make(['name' => 'WS Lda', 'slug' => 'ws'], TenantProvisioner::rules())->errors()->has('slug'))->toBeTrue()
        ->and(Validator::make(['name' => 'Nova Lda', 'slug' => 'nova'], TenantProvisioner::rules())->errors()->has('slug'))->toBeFalse();
});
