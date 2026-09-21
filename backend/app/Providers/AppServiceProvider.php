<?php

namespace App\Providers;

use App\Contracts\Firewall;
use App\Contracts\PhpStack;
use App\Models\User;
use App\Services\Runtime\InstallTracker;
use App\Services\Server\Applications\DeploymentRecorder;
use App\Services\Server\Applications\ProvisionProgress;
use App\Services\Server\Backups\Storage\GoogleHttpClient;
use App\Services\Server\Backups\Storage\GoogleOauthTokens;
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
use App\Support\PasswordPolicy;
use Google\Client as GoogleClient;
use Google\Service\Drive as GoogleDrive;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use League\Flysystem\Filesystem as Flysystem;
use Masbug\Flysystem\GoogleDriveAdapter;

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

        // Laravel ships no Google Drive driver, so `Storage::build()` cannot
        // resolve one without this. Registered here rather than inside
        // `GoogleDriveDriver` so that class stays a description of the
        // provider rather than a place adapters get constructed.
        //
        // Built per call and never registered as a named disk: a destination's
        // service-account key must not leak into `filesystems.disks`, where
        // any later code resolving a disk by name could reach it — the same
        // isolation rule every other storage driver follows.
        Storage::extend('google', function ($app, array $config): FilesystemAdapter {
            $client = new GoogleClient;
            // Low-speed abort, so a dead upload fails instead of occupying the
            // only queue worker until the job timeout. See GoogleHttpClient.
            $client->setHttpClient(GoogleHttpClient::make());
            $client->setAuthConfig(json_decode((string) ($config['service_account'] ?? ''), true, 512, JSON_THROW_ON_ERROR));
            $client->setScopes([GoogleDrive::DRIVE]);

            $adapter = new GoogleDriveAdapter(
                new GoogleDrive($client),
                (string) ($config['folder_id'] ?? ''),
                [
                    // Drive is not a key-value store: the same display name can
                    // exist twice in one folder. Path translation is what makes
                    // `backups/site/2026-09-14/x.tar.gz` mean one file rather
                    // than a name that silently collides with an existing one.
                    'useDisplayPaths' => true,
                    // Ask the API to see Shared Drives. Without it every
                    // operation is scoped to My Drive, where a service account
                    // has no quota — which is the one place these destinations
                    // cannot work.
                    'parameters' => [
                        'supportsAllDrives' => true,
                        'includeItemsFromAllDrives' => true,
                    ],
                ],
            );

            return new FilesystemAdapter(
                new Flysystem($adapter, $config),
                $adapter,
                $config,
            );
        });

        /*
         * The same Drive, authenticated as the user rather than as a service
         * account.
         *
         * Everything below the client is identical to `google` above — same
         * adapter, same path translation, same streaming. Only the two lines
         * that authenticate differ, which is the whole reason this is a
         * separate disk rather than a branch inside that closure: the client
         * is built from a refresh token, and the scope is `drive.file`.
         *
         * `supportsAllDrives` is deliberately absent. It exists up there
         * because a service account can only write where a Shared Drive owns
         * the files; here the user owns them, in their own My Drive, and
         * asking to see Shared Drives would widen reach for no purpose.
         */
        Storage::extend('google_oauth', function ($app, array $config): FilesystemAdapter {
            $client = new GoogleClient;
            // Same low-speed abort as the service-account disk above; this is
            // the one that actually carries multi-gigabyte backups.
            $client->setHttpClient(GoogleHttpClient::make());
            $client->setClientId((string) ($config['client_id'] ?? ''));
            $client->setClientSecret((string) ($config['client_secret'] ?? ''));
            // The same constant the consent request uses. Asking for one scope
            // and being granted another is a failure that surfaces only on the
            // first real upload, so the two cannot be allowed to drift.
            $client->setScopes([GoogleOauthTokens::SCOPE]);

            // The library refreshes on demand and caches for the request, so
            // a long restore does not re-auth per object. A dead grant throws
            // here and `GoogleDriveOauthDriver::classify()` names it — revoked,
            // or an app left in "Testing" past Google's ~7-day token life.
            $client->fetchAccessTokenWithRefreshToken((string) ($config['refresh_token'] ?? ''));

            $folderId = trim((string) ($config['folder_id'] ?? ''));

            $adapter = new GoogleDriveAdapter(
                new GoogleDrive($client),
                // **Null, never the folder id.** With `useDisplayPaths` on, the
                // adapter reads this argument as a *display name* and — because
                // it passes `createDirsIfNeeded: true` — makes a folder with
                // that literal text when it cannot find one
                // (`GoogleDriveAdapter::__construct`, the `toSingleVirtualPath`
                // call). Handing it an id therefore created a folder actually
                // named `1f5v8y369-o8pIvZse_VXpIbgbdY3POXu` in somebody's
                // personal Drive, beside the properly named one the panel had
                // already made. A hash appearing in your own Drive reads as a
                // compromise, and it was reported as one.
                null,
                [
                    // The id belongs here instead: this branch assigns it as the
                    // root verbatim and skips the name lookup entirely, which is
                    // the only way to address a folder by id while display paths
                    // are on. Omitted when empty — an unconnected destination has
                    // no folder yet, and `sharedFolderId => ''` would root the
                    // disk at nothing rather than falling back to the account
                    // root.
                    ...($folderId !== '' ? ['sharedFolderId' => $folderId] : []),

                    // Same reason as the service-account disk: Drive allows two
                    // files with one display name in a folder, and without path
                    // translation `site/2026-09-19/x.tar.gz` is a name that can
                    // silently collide rather than one file.
                    'useDisplayPaths' => true,
                ],
            );

            return new FilesystemAdapter(
                new Flysystem($adapter, $config),
                $adapter,
                $config,
            );
        });

        // One definition of what a password has to be.
        //
        // The same `Password::min(10)->mixedCase()->numbers()` was spelled out
        // in six FormRequests — registration, admin create, self-change, admin
        // reset, and both system-user paths. Six copies of a rule is six places
        // to change it and five places to forget, and nothing could *state*
        // the policy to a caller, so the sign-up form had to hardcode its own
        // description of a rule it could not read.
        //
        // `Password::defaults()` is what the requests use now, and
        // `PasswordPolicy` describes the same numbers for the API.
        Password::defaults(fn () => PasswordPolicy::rule());

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
        RateLimiter::for('api', function (Request $request) {
            // Central signs in as one machine account (see CentralSystemGuard),
            // so the per-user branch below would hand the vendor a single
            // budget for everything it does on this server — ten managed sites
            // share the allowance of one person clicking around. And a machine
            // answers a 429 with a retry, which spends the next window too, so
            // the limit that bites once tends to keep biting.
            //
            // High rather than absent. `Limit::none()` would also stop the
            // errors, but this token sits in a settings row, and if it ever
            // leaks the ceiling is the only thing between it and the whole API
            // at line speed. Same trade as the deploy webhook: far above any
            // real rate, far below anything harmful.
            if ($request->attributes->get('central_authenticated') === true) {
                return Limit::perMinute((int) config('server.rate_limits.central', 3000))
                    ->by('central');
            }

            return $request->user()
                ? Limit::perMinute((int) config('server.rate_limits.api', 180))->by($request->user()->id)
                : Limit::perMinute((int) config('server.rate_limits.guest', 20))->by($request->ip());
        });

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

        // Starting an update, and asking about one, must not share a bucket.
        //
        // `throttle:3,1` and `throttle:30,1` look like two independent limits.
        // They are not: Laravel keys an inline throttle on the route URI and
        // the user, with no reference to the HTTP method or the limit itself —
        // and GET and POST here are both `/panel-update`. One counter, two
        // readings of it.
        //
        // So every poll of the update page spent one of the update button's
        // three attempts, and after three requests of any kind in a minute the
        // button answered 429 "Too Many Requests". Measured on a real server:
        // GET remaining 29/30, then POST reading 1/3, then GET remaining 27/30
        // — a single counter at 1, 2, 3.
        //
        // Named limiters include the limiter name in the key, so these two are
        // finally separate. The numbers are unchanged: checking is cheap and
        // may reach the release host, starting takes the panel down and
        // rebuilds it.
        RateLimiter::for('panel-update-check', fn (Request $request) => $request->user()
            ? Limit::perMinute(30)->by($request->user()->id)
            : Limit::perMinute((int) config('server.rate_limits.guest', 20))->by($request->ip()));

        RateLimiter::for('panel-update-start', fn (Request $request) => $request->user()
            ? Limit::perMinute(3)->by($request->user()->id)
            : Limit::perMinute(3)->by($request->ip()));

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
