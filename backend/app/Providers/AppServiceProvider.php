<?php

namespace App\Providers;

use App\Contracts\Firewall;
use App\Contracts\PhpStack;
use App\Models\User;
use App\Services\Server\Applications\ProvisionProgress;
use App\Services\Server\Capabilities\ServerCapabilities;
use App\Services\Server\Firewall\UfwFirewall;
use App\Services\Server\Php\PhpStackManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The firewall engine — UFW today; swap the binding for firewalld later.
        $this->app->bind(Firewall::class, UfwFirewall::class);

        // How PHP is served here. Resolved from the web server this box runs,
        // because nginx and Apache use PHP-FPM and OpenLiteSpeed cannot —
        // it runs LSPHP, with different packages, paths and no per-version
        // service. Everything that needs a PHP fact asks this rather than
        // assuming one.
        $this->app->scoped(PhpStack::class, fn ($app) => $app->make(PhpStackManager::class)->stack());

        // One registry per request. Nearly every server feature reads it, and
        // on a box with no record yet the first read shells out to detect —
        // a fresh instance per consumer turns that into one detection each.
        $this->app->scoped(ServerCapabilities::class);

        // One progress recorder per job, shared by the provisioner and the
        // installer it calls — they are recording steps of the same run, and
        // two instances would each keep half the list.
        $this->app->scoped(ProvisionProgress::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('access-admin', fn (User $user): bool => $user->isAdmin());

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)
            ->by($request->string('username').'|'.$request->ip()));

        RateLimiter::for('api', fn (Request $request) => $request->user()
            ? Limit::perMinute(120)->by($request->user()->id)
            : Limit::perMinute(20)->by($request->ip()));

        // Deploy webhooks: keyed on the webhook, not the caller's IP. A provider
        // delivers from shared egress, so an IP bucket would have one busy
        // repository throttle another user's, while doing nothing to bound the
        // one endpoint an attacker can actually aim at. 60/minute is far above
        // any real push rate and far below what would keep the queue busy.
        RateLimiter::for('webhook', fn (Request $request) => Limit::perMinute(60)
            ->by((string) $request->route('identifier')));
    }
}
