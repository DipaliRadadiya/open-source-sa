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
use App\Services\Server\Sync\Discoverers\ApplicationDiscoverer;
use App\Services\Server\Sync\Discoverers\CertificateDiscoverer;
use App\Services\Server\Sync\Discoverers\CronjobDiscoverer;
use App\Services\Server\Sync\Discoverers\DatabaseUserDiscoverer;
use App\Services\Server\Sync\Discoverers\FirewallRuleDiscoverer;
use App\Services\Server\Sync\Discoverers\PhpSettingsDiscoverer;
use App\Services\Server\Sync\Discoverers\SshKeyDiscoverer;
use App\Services\Server\Sync\Discoverers\SystemUserDiscoverer;
use App\Services\Server\Sync\Discoverers\WorkerDiscoverer;
use App\Services\Server\Sync\ServerSync;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
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

        // The sync registry. Order here is the intended run order; ServerSync
        // still resolves dependsOn() itself, so adding one in the wrong place
        // is a tidiness problem rather than a correctness one.
        $this->app->bind(ServerSync::class, fn ($app) => new ServerSync([
            $app->make(SystemUserDiscoverer::class),
            $app->make(SshKeyDiscoverer::class),
            $app->make(ApplicationDiscoverer::class),
            $app->make(PhpSettingsDiscoverer::class),
            $app->make(WorkerDiscoverer::class),
            $app->make(DatabaseUserDiscoverer::class),
            $app->make(CertificateDiscoverer::class),
            $app->make(CronjobDiscoverer::class),
            $app->make(FirewallRuleDiscoverer::class),
        ]));

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

        // The budget every authenticated request draws on, unless a route
        // explicitly opts out. Env-tunable because the right number depends on
        // how the panel is used — a dashboard with several polling widgets
        // spends this faster than one person clicking around.
        //
        // Raising it does not make a per-route limit above it work: a second
        // throttle stacks with this one rather than replacing it, so the lower
        // always wins. A route that needs genuine headroom has to drop this
        // one, as the upload endpoints and the deploy webhook do — and
        // RateLimitTest fails the build if one forgets.
        RateLimiter::for('api', fn (Request $request) => $request->user()
            ? Limit::perMinute((int) config('server.rate_limits.api', 180))->by($request->user()->id)
            : Limit::perMinute((int) config('server.rate_limits.guest', 20))->by($request->ip()));

        // Progress-polling endpoints — provisioning, deployments, sync runs,
        // panel updates. These are the screens someone sits and watches, so
        // they poll for as long as the work takes and the work can take
        // minutes. They are exempt from the global limiter (see the routes),
        // and this is the budget that actually bounds them.
        //
        // Deliberately generous: the failure it prevents is a user watching a
        // long install get told "Too Many Attempts" by their own panel, which
        // reads as the install having broken. These are cheap reads of one row.
        //
        // The key is per user AND per polled resource, so watching one
        // deployment cannot exhaust the budget for another.
        //
        // It is built from route parameter KEYS, not the parameters themselves.
        // This previously interpolated the bound model directly — and a model
        // in string context is `toJson()`, so the bucket key contained the
        // whole record. During provisioning `status` and `steps` change on
        // almost every poll, so every request landed in a brand-new bucket and
        // the limit never engaged at all. A limiter that silently stops
        // limiting is worse than no limiter, because it still reads like one.
        RateLimiter::for('progress', fn (Request $request) => $request->user()
            ? Limit::perMinute((int) config('server.rate_limits.progress', 600))
                ->by($request->user()->id.'|'.$this->routeScope($request))
            : Limit::perMinute((int) config('server.rate_limits.guest', 20))->by($request->ip()));

        // Deploy webhooks: keyed on the webhook, not the caller's IP. A provider
        // delivers from shared egress, so an IP bucket would have one busy
        // repository throttle another user's, while doing nothing to bound the
        // one endpoint an attacker can actually aim at. 60/minute is far above
        // any real push rate and far below what would keep the queue busy.
        RateLimiter::for('webhook', fn (Request $request) => Limit::perMinute(60)
            ->by((string) $request->route('identifier')));
    }

    /**
     * A stable identifier for whatever the route is about.
     *
     * Route parameters are resolved models by the time a limiter runs, and a
     * model cast to string is its entire JSON — which changes as the record
     * changes, so a key built that way silently reopens the bucket. Only the
     * primary key is taken, which is the part that identifies the thing and
     * does not move while it is being polled.
     */
    private function routeScope(Request $request): string
    {
        $parameters = $request->route()?->parameters() ?? [];

        return collect($parameters)
            ->map(fn (mixed $parameter): string => $parameter instanceof Model
                ? $parameter::class.':'.$parameter->getKey()
                : (string) $parameter)
            ->implode('|');
    }
}
