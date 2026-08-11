<?php

namespace App\Providers;

use App\Contracts\Firewall;
use App\Contracts\PhpStack;
use App\Models\User;
use App\Services\Runtime\InstallTracker;
use App\Services\Server\Applications\DeploymentRecorder;
use App\Services\Server\Applications\ProvisionProgress;
use App\Services\Server\Capabilities\ServerCapabilities;
use App\Services\Server\Firewall\UfwFirewall;
use App\Services\Server\Php\PhpStackManager;
use App\Services\Server\Setup\Components\DatabaseComponent;
use App\Services\Server\Setup\Components\Fail2banComponent;
use App\Services\Server\Setup\Components\NodeComponent;
use App\Services\Server\Setup\Components\PhpComponent;
use App\Services\Server\Setup\Components\RedisComponent;
use App\Services\Server\Setup\SetupCatalog;
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

        // Scoped for the same reason: the deployer accumulates output into it
        // step by step, and the job reads the finished row out of it. Two
        // instances would mean the deploy writes its log into an object nobody
        // ever looks at.
        $this->app->scoped(DeploymentRecorder::class);

        // The setup page's component list, in the order it is shown. Registered
        // here rather than discovered, because the order is a product decision:
        // the database first because it is what blocks a first real site.
        $this->app->bind(SetupCatalog::class, fn ($app) => new SetupCatalog(
            [
                $app->make(DatabaseComponent::class),
                $app->make(PhpComponent::class),
                $app->make(NodeComponent::class),
                $app->make(RedisComponent::class),
                $app->make(Fail2banComponent::class),
            ],
            $app->make(InstallTracker::class),
            $app->make(ServerCapabilities::class),
        ));
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

        // Status-polling endpoints: a per-user-per-app bucket, so one app's
        // polling cannot starve another app's.
        //
        // Note what this does NOT do, because the comment here used to claim
        // it: polling still consumes the global `api` budget. That limiter is
        // prepended to every API route in bootstrap/app.php and a second
        // throttle stacks with it rather than replacing it — escaping it takes
        // an explicit `withoutMiddleware('throttle:api')`, as the deploy
        // webhook and the upload endpoints do. Left in place here deliberately:
        // these are ordinary read endpoints, and exempting them would mean the
        // global limit bounds nothing on the busiest routes in the panel.
        //
        // 120/min per app is ~2/sec — enough for 5s polling intervals.
        RateLimiter::for('status', fn (Request $request) => $request->user()
            ? Limit::perMinute(120)->by($request->user()->id.'|'.$request->route('application'))
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
