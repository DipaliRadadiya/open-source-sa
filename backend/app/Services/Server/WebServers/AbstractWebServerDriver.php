<?php

namespace App\Services\Server\WebServers;

use App\Contracts\WebServerDriver;
use App\Enums\DomainType;
use App\Models\Application;
use App\Services\Server\ManagedFile;
use App\Services\Server\Php\PoolManager;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use Illuminate\Support\Facades\View;

abstract class AbstractWebServerDriver implements WebServerDriver
{
    public function __construct(
        protected ServerOps $serverOps,
        protected ManagedFile $files,
    ) {}

    /**
     * One file in a directory, which is true for nginx and Apache. A driver
     * whose configuration is not one file overrides this.
     */
    public function apply(Application $application, string $documentRoot): ServerOpsResult
    {
        return $this->files->put(
            $this->configPath($application),
            $this->renderConfig($application, $documentRoot),
            ['feature' => 'application', 'op' => 'write_config', 'application' => $application->id],
        );
    }

    public function remove(Application $application): ServerOpsResult
    {
        return $this->files->delete(
            $this->configPath($application),
            ['feature' => 'application', 'op' => 'remove_config', 'application' => $application->id],
        );
    }

    public function configPath(Application $application): string
    {
        $directory = rtrim((string) config("server.web_server_drivers.{$this->name()}.sites_dir"), '/');

        // The domain is validated to a hostname charset before it ever gets
        // here, so it cannot introduce a path separator.
        return "{$directory}/{$application->domain}.conf";
    }

    public function renderConfig(Application $application, string $documentRoot): string
    {
        $profile = $application->serving_profile;
        $view = "server.vhosts.{$this->name()}.{$profile}";

        abort_unless(View::exists($view), 500);

        return View::make($view, $this->viewData($application, $documentRoot))->render();
    }

    /**
     * What a vhost template is given. A driver whose syntax needs more than
     * this adds to it.
     *
     * @return array<string, mixed>
     */
    protected function viewData(Application $application, string $documentRoot): array
    {
        return [
            'application' => $application,
            'domain' => $application->domain,
            // Every name the site answers to, primary first. Templates used to
            // hardcode `www.{domain}` alongside the primary; that guess is now
            // a row in application_domains, backfilled for existing sites so
            // what they serve is unchanged.
            'serverNames' => $application->serverNames(),
            // Redirects get their own server block — they serve nothing, they
            // send a 301 somewhere else, and mixing them into the main block
            // would serve the same content under both names instead.
            'redirects' => $application->domains
                ->filter(fn ($domain) => $domain->type === DomainType::Redirect)
                ->values(),
            // Only a certificate with files behind it. A pending or failed one
            // is deliberately hidden from the template: pointing a server block
            // at a path that is not there fails the config test and takes a
            // working site down over a certificate it never had.
            'certificate' => $application->certificate?->servable() ? $application->certificate : null,
            'forceHttps' => (bool) ($application->certificate?->servable() && $application->certificate->force_https),
            // The shared ACME webroot, aliased into every profile. Per-site
            // document roots cannot work for node and proxy sites — they serve
            // nothing from disk, so there is nowhere for certbot to drop the
            // challenge token.
            'challengeRoot' => rtrim((string) config('server.certificates.challenge_root'), '/'),
            'documentRoot' => $documentRoot,
            'phpVersion' => $application->php_version ?: config('server.default_php_version'),
            // Where PHP actually is for this site. An isolated site has its own
            // pool running as its own user; everything else still shares the
            // server-wide pool, which is what every site did before pools
            // existed. Resolved here so no template has to know the rule.
            'phpSocket' => app(PoolManager::class)->socketFor($application),
            // The OS account the site runs as. nginx and Apache reach PHP
            // through a pool that already knows this; OLS spawns the process
            // itself and has to be told.
            'user' => $application->systemUser?->username,
            // Where a reverse proxy sends traffic, and a name for the backend
            // that is unique per application — OpenLiteSpeed declares external
            // applications by name, and two sites sharing one would have the
            // second quietly overwrite the first.
            'appPort' => $application->app_port,
            'appName' => 'sv-app-'.$application->id,
            // Null when off, so every template gates the whole block behind
            // one `@if` instead of re-checking `basic_auth_enabled` itself.
            // The credential file lives inside `.panel/`, which every profile
            // already denies serving over HTTP — nothing new to hide here.
            'basicAuth' => $application->basic_auth_enabled ? [
                'realm' => 'sv-app-'.$application->id,
                'htpasswdPath' => $documentRoot.'/.panel/.htpasswd',
            ] : null,
        ];
    }

    public function test(): ServerOpsResult
    {
        return $this->serverOps->run(
            $this->testCommand(),
            ['feature' => 'application', 'op' => 'config_test', 'web_server' => $this->name()],
        );
    }

    public function reload(): ServerOpsResult
    {
        return $this->serverOps->run(
            $this->reloadCommand(),
            ['feature' => 'application', 'op' => 'reload', 'web_server' => $this->name()],
        );
    }

    /**
     * The reload command as something else can run.
     *
     * Exposed for certbot's post-renewal hook, which is a shell script run by
     * certbot's own timer rather than by the panel. Without it renewal
     * half-works: a new certificate lands on disk and the web server keeps
     * serving the old one from memory until something unrelated reloads it,
     * which surfaces weeks later as an expired certificate on a healthy site.
     *
     * @return array<int, string>
     */
    public function reloadCommandForHook(): array
    {
        return $this->reloadCommand();
    }

    /**
     * @return array<int, string>
     */
    abstract protected function testCommand(): array;

    /**
     * @return array<int, string>
     */
    abstract protected function reloadCommand(): array;
}
