<?php

namespace App\Providers;

use App\Contracts\Firewall;
use App\Contracts\PhpStack;
use App\Models\Application;
use App\Models\SystemUser;
use App\Models\User;
use App\Observers\AppShellMapObserver;
use App\Services\Admin\ApiErrorLogWriter;
use App\Services\Recipes\RecipeRegistry;
use App\Services\Runtime\InstallTracker;
use App\Services\Server\Applications\ContainerLiveness;
use App\Services\Server\Applications\DeploymentRecorder;
use App\Services\Server\Applications\ProvisionProgress;
use App\Services\Server\Backups\Storage\GoogleHttpClient;
use App\Services\Server\Backups\Storage\GoogleOauthTokens;
use App\Services\Server\Capabilities\ServerCapabilities;
use App\Services\Server\Cron\CronPhpBinary;
use App\Services\Server\Firewall\UfwFirewall;
use App\Services\Server\Php\PhpStackManager;
use App\Services\Server\Php\ServerDefaultPhp;
use App\Services\Server\Setup\Components\BuildToolsComponent;
use App\Services\Server\Setup\Components\DatabaseComponent;
use App\Services\Server\Setup\Components\DockerComponent;
use App\Services\Server\Setup\Components\Fail2banComponent;
use App\Services\Server\Setup\Components\NodeComponent;
use App\Services\Server\Setup\Components\PhpComponent;
use App\Services\Server\Setup\Components\RedisComponent;
use App\Services\Server\Setup\Components\WpCliComponent;
use App\Services\Server\Setup\SetupCatalog;
use App\Services\Server\Sync\Discoverers\ApplicationDiscoverer;
use App\Services\Server\Sync\Discoverers\CertificateDiscoverer;
use App\Services\Server\Sync\Discoverers\CronjobDiscoverer;
use App\Services\Server\Sync\Discoverers\DatabaseUserDiscoverer;
use App\Services\Server\Sync\Discoverers\FirewallRuleDiscoverer;
use App\Services\Server\Sync\Discoverers\PhpSettingsDiscoverer;
use App\Services\Server\Sync\Discoverers\Pm2Discoverer;
use App\Services\Server\Sync\Discoverers\SshKeyDiscoverer;
use App\Services\Server\Sync\Discoverers\SystemUserDiscoverer;
use App\Services\Server\Sync\Discoverers\WorkerDiscoverer;
use App\Services\Server\Sync\ServerSync;
use App\Support\PasswordPolicy;
use App\Support\Scheduling\BootScopedEventMutex;
use Google\Client as GoogleClient;
use Google\Service\Drive as GoogleDrive;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
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
        $this->app->singleton(RecipeRegistry::class);

        // The firewall engine — UFW today; swap the binding for firewalld later.
        $this->app->bind(Firewall::class, UfwFirewall::class);

        // Scheduler locks that a reboot cannot strand — see the class.
        $this->app->bind(EventMutex::class, BootScopedEventMutex::class);

        // The sync registry, in intended run order (ServerSync resolves dependsOn() itself).
        $this->app->bind(ServerSync::class, fn ($app) => new ServerSync([
            $app->make(SystemUserDiscoverer::class),
            $app->make(SshKeyDiscoverer::class),
            $app->make(ApplicationDiscoverer::class),
            $app->make(PhpSettingsDiscoverer::class),
            $app->make(Pm2Discoverer::class),
            $app->make(WorkerDiscoverer::class),
            $app->make(DatabaseUserDiscoverer::class),
            $app->make(CertificateDiscoverer::class),
            $app->make(CronjobDiscoverer::class),
            $app->make(FirewallRuleDiscoverer::class),
        ]));

        // How PHP is served here, resolved from the web server: nginx and Apache use PHP-FPM,
        // OpenLiteSpeed runs LSPHP (different packages and paths, no per-version service).
        $this->app->scoped(PhpStack::class, fn ($app) => $app->make(PhpStackManager::class)->stack());

        // One registry per request: with no record yet the first read shells out to detect,
        // so a fresh instance per consumer would detect once each.
        $this->app->scoped(ServerCapabilities::class);

        // One `docker ps` per request, however many container sites a list shows.
        $this->app->scoped(ContainerLiveness::class);

        // Asked once per type by the site-type catalog; one answer per request.
        $this->app->scoped(ServerDefaultPhp::class);

        // A cron list asks every row which PHP it runs; read the server default once per request.
        $this->app->scoped(CronPhpBinary::class);

        // One progress recorder per job, shared by the provisioner and the installer it calls
        // (two instances would each keep half the steps).
        $this->app->scoped(ProvisionProgress::class);

        // Scoped for the same reason: the deployer and the job must share one instance.
        $this->app->scoped(DeploymentRecorder::class);

        // The setup page's component list, in display order (a product decision, so registered
        // rather than discovered).
        $this->app->bind(SetupCatalog::class, fn ($app) => new SetupCatalog(
            [
                // First: the other rows are about what runs on top of the stack.
                $app->make(DockerComponent::class),
                $app->make(DatabaseComponent::class),
                $app->make(PhpComponent::class),
                $app->make(NodeComponent::class),
                // Directly after Node: it exists for npm.
                $app->make(BuildToolsComponent::class),
                $app->make(RedisComponent::class),
                $app->make(Fail2banComponent::class),
                $app->make(WpCliComponent::class),
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
        // Background failures for the admin Error Log screen (it reads the server-ops log).
        Queue::failing(function (JobFailed $event): void {
            app(ApiErrorLogWriter::class)->recordBackground('job.failed', $event->job->resolveName(), $event->exception);
        });

        Event::listen(ScheduledTaskFailed::class, function (ScheduledTaskFailed $event): void {
            app(ApiErrorLogWriter::class)->recordBackground('schedule.failed', (string) ($event->task->description ?: $event->task->command), $event->exception);
        });

        // A scheduled artisan command runs in its own process: it exits non-zero rather than
        // throwing, and only Finished says so.
        Event::listen(ScheduledTaskFinished::class, function (ScheduledTaskFinished $event): void {
            if (($event->task->exitCode ?? 0) !== 0) {
                app(ApiErrorLogWriter::class)->recordBackground('schedule.failed', (string) ($event->task->description ?: $event->task->command), exitCode: (int) $event->task->exitCode);
            }
        });

        Gate::define('access-admin', fn (User $user): bool => $user->isAdmin());

        // Container login: the access map follows every change to a Docker app or its owner.
        Application::observe(AppShellMapObserver::class);
        SystemUser::observe(AppShellMapObserver::class);

        // Laravel ships no Google Drive driver, so `Storage::build()` needs this.
        //
        // Built per call and never registered as a named disk: a destination's service-account
        // key must not leak into `filesystems.disks`, where code resolving a disk by name could
        // reach it.
        Storage::extend('google', function ($app, array $config): FilesystemAdapter {
            $client = new GoogleClient;
            // Low-speed abort, so a dead upload fails instead of occupying the only queue
            // worker until the job timeout. See GoogleHttpClient.
            $client->setHttpClient(GoogleHttpClient::make());
            $client->setAuthConfig(json_decode((string) ($config['service_account'] ?? ''), true, 512, JSON_THROW_ON_ERROR));
            $client->setScopes([GoogleDrive::DRIVE]);

            $adapter = new GoogleDriveAdapter(
                new GoogleDrive($client),
                (string) ($config['folder_id'] ?? ''),
                [
                    // Drive allows the same display name twice in one folder; path translation
                    // makes `backups/site/x.tar.gz` mean one file.
                    'useDisplayPaths' => true,
                    // See Shared Drives: a service account has no quota in My Drive.
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
         * The same Drive, authenticated as the user (refresh token, `drive.file` scope) rather
         * than as a service account; everything below the client matches `google` above.
         *
         * `supportsAllDrives` is deliberately absent: the user owns the files in their own My
         * Drive, and asking for Shared Drives would widen reach for no purpose.
         */
        Storage::extend('google_oauth', function ($app, array $config): FilesystemAdapter {
            $client = new GoogleClient;
            // Same low-speed abort as the service-account disk above.
            $client->setHttpClient(GoogleHttpClient::make());
            $client->setClientId((string) ($config['client_id'] ?? ''));
            $client->setClientSecret((string) ($config['client_secret'] ?? ''));
            // The same constant the consent request uses, so the two scopes cannot drift.
            $client->setScopes([GoogleOauthTokens::SCOPE]);

            // The library refreshes on demand and caches for the request. A dead grant throws
            // here and `GoogleDriveOauthDriver::classify()` names it.
            $client->fetchAccessTokenWithRefreshToken((string) ($config['refresh_token'] ?? ''));

            $folderId = trim((string) ($config['folder_id'] ?? ''));

            $adapter = new GoogleDriveAdapter(
                new GoogleDrive($client),
                // Null, never the folder id: with `useDisplayPaths` the adapter reads this as a
                // display name and creates a folder with that literal text when it finds none.
                null,
                [
                    // The id belongs here: it is assigned as the root verbatim, the only way to
                    // address a folder by id while display paths are on. Omitted when empty
                    // (`''` would root the disk at nothing instead of the account root).
                    ...($folderId !== '' ? ['sharedFolderId' => $folderId] : []),

                    // Same reason as the service-account disk.
                    'useDisplayPaths' => true,
                ],
            );

            return new FilesystemAdapter(
                new Flysystem($adapter, $config),
                $adapter,
                $config,
            );
        });

        // One definition of what a password has to be: `Password::defaults()` is what the
        // FormRequests use, and `PasswordPolicy` describes the same numbers for the API.
        Password::defaults(fn () => PasswordPolicy::rule());

        // Three limits, all applied: the pair limit alone lets one address try every username in
        // turn; per address stops that, and per name stops a password list spread over many machines.
        RateLimiter::for('login', function (Request $request): array {
            $username = mb_strtolower(trim((string) $request->string('username')));

            return [
                Limit::perMinute(5)->by('pair:'.$username.'|'.$request->ip()),
                Limit::perMinute(20)->by('ip:'.$request->ip()),
                Limit::perMinute(10)->by('user:'.$username),
            ];
        });

        // The budget every authenticated request draws on, unless a route opts out. Env-tunable.
        //
        // A second per-route throttle stacks with this one (the lower wins), so a route that
        // needs headroom must drop this one (as the upload endpoints and deploy webhook do;
        // RateLimitTest fails the build if one forgets).
        RateLimiter::for('api', function (Request $request) {
            // Central signs in as one machine account (see CentralSystemGuard), so the per-user
            // branch would give it a single small budget (and it retries 429s).
            //
            // High rather than absent: the token sits in a settings row, and if it leaks the
            // ceiling is the only thing between it and the whole API.
            if ($request->attributes->get('central_authenticated') === true) {
                return Limit::perMinute((int) config('server.rate_limits.central', 3000))
                    ->by('central');
            }

            return $request->user()
                ? Limit::perMinute((int) config('server.rate_limits.api', 180))->by($request->user()->id)
                : Limit::perMinute((int) config('server.rate_limits.guest', 20))->by($request->ip());
        });

        // Progress-polling endpoints (provisioning, deployments, sync runs, panel updates). They
        // are exempt from the global limiter (see the routes); this budget bounds them. Generous
        // on purpose: these are cheap reads, and a user watching a long install must not be told
        // "Too Many Attempts".
        //
        // Keyed per user AND per polled resource. Built from route parameter KEYS, not the bound
        // models: a model in string context is its whole JSON, which changes on every poll, so
        // every request would land in a new bucket and the limit would never engage.
        RateLimiter::for('progress', fn (Request $request) => $request->user()
            ? Limit::perMinute((int) config('server.rate_limits.progress', 600))
                ->by($request->user()->id.'|'.$this->routeScope($request))
            : Limit::perMinute((int) config('server.rate_limits.guest', 20))->by($request->ip()));

        // Starting an update and asking about one must not share a bucket: Laravel keys an inline
        // `throttle:N,M` on route URI and user only (not method or limit), and GET and POST are
        // both `/panel-update`, so polls would spend the start button's attempts. Named limiters
        // include their name in the key.
        RateLimiter::for('panel-update-check', fn (Request $request) => $request->user()
            ? Limit::perMinute(30)->by($request->user()->id)
            : Limit::perMinute((int) config('server.rate_limits.guest', 20))->by($request->ip()));

        RateLimiter::for('panel-update-start', fn (Request $request) => $request->user()
            ? Limit::perMinute(3)->by($request->user()->id)
            : Limit::perMinute(3)->by($request->ip()));

        // Deploy webhooks: keyed on the webhook, not the caller's IP (a provider delivers from
        // shared egress, so an IP bucket would throttle other users' repositories). 60/minute is
        // far above any real push rate.
        RateLimiter::for('webhook', fn (Request $request) => Limit::perMinute(60)
            ->by((string) $request->route('identifier')));
    }

    /**
     * A stable identifier for whatever the route is about.
     *
     * Route parameters are resolved models by the time a limiter runs, and a model cast to
     * string is its entire JSON, which changes as the record does. Only the primary key is
     * taken, which does not move while it is polled.
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
