<?php

namespace App\Services\Server\Applications\Installers;

use App\Contracts\SiteInstaller;
use App\Models\Application;
use App\Services\Applications\SiteTypeManager;
use App\Services\Applications\Types\AbstractDockerAppType;
use App\Services\Server\Applications\ContainerSupervisor;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

/**
 * Turns a one-click Docker app into a compose file this site can run.
 *
 * One installer for every Docker app, not one per app: the differences between
 * Ghost and Strapi are entirely in the site type — template, port, volumes,
 * secrets — and a class per app would be five methods of delegation each.
 * Contrast the PHP one-clicks, where each app genuinely runs different commands.
 *
 * It implements {@see SiteInstaller} directly rather than extending
 * `AbstractSiteInstaller`, whose constructor wants a process supervisor, a PHP
 * shim and runtime ownership. A container needs none of those, and inheriting
 * them to leave them unused would suggest this participates in the host runtime.
 *
 * **What it writes, and what it deliberately does not do.** It renders the
 * app's compose into `$application->compose` and records the volumes on
 * `volume_mounts`. It does not create the volumes and does not start anything:
 * `DockerResources::ensureFor()` creates them and `ContainerSupervisor::apply()`
 * brings the site up, both already on the provisioning path immediately after
 * this. Reaching for them here would be a second place that starts containers.
 *
 * **Why the rendered file is stored per site.** The alternative is rendering it
 * fresh on every deploy, which would silently move a running site to a new image
 * the day the panel bumped a default. A site's compose file is its own; changing
 * the app's version is a deliberate act and needs its own feature. The cost is
 * that a template fix does not reach existing sites, which is the same trade the
 * panel already makes for a vhost.
 */
class DockerAppInstaller implements SiteInstaller
{
    public function __construct(
        private SiteTypeManager $siteTypes,
        private ContainerSupervisor $containers,
    ) {}

    /**
     * Every Docker app, so the registry maps them all here.
     *
     * The interface names ONE type because the PHP installers are one-to-one.
     * This is the type the registry asked for, resolved per application in
     * `install()`, and the value here is only ever read for logging.
     */
    public function siteType(): string
    {
        return 'docker_app';
    }

    /**
     * No. A container brings its own database — that is the premise of the
     * stack, and why a Docker server manages no engine for the panel to create
     * one in.
     */
    public function needsDatabase(): bool
    {
        return false;
    }

    /** @return array<int, string> */
    public function acceptedEngines(): array
    {
        return [];
    }

    /** @return array<string, string> */
    public function minimumEngineVersions(): array
    {
        return [];
    }

    /**
     * Nothing systemd runs. Compose supervises a container, which is the fork
     * `ApplicationProvisioner::startProcess()` already makes on the serving
     * profile.
     */
    public function startCommand(Application $application, string $documentRoot): ?string
    {
        return null;
    }

    /**
     * Rewrite the URL the app was told about, when its domain changes.
     *
     * Ghost builds every link and redirect from `url`, so a primary-domain
     * change leaves a site serving pages whose assets and login point at the old
     * host — which reads as a broken theme, not a stale setting. The compose file
     * holds that value, so reconciling it means re-rendering and bringing the
     * container up again.
     */
    public function syncUrl(Application $application, string $url): void
    {
        $type = $this->typeFor($application);

        // Nothing to reconcile for an app that is never told its URL — Metabase
        // and Wiki.js ask for theirs in their own setup wizard and keep it in the
        // database, so a re-render would rewrite the file to say the same thing
        // and restart a container for no reason.
        if ($type === null || $type->urlEnvKey() === null) {
            return;
        }

        // No `str_contains($compose, 'url:')` guard. That was the first version
        // and it was wrong for the second app added: Ghost's key is `url` and
        // Strapi's is `URL`, so the check silently skipped Strapi and a renamed
        // site kept pointing its admin panel at the old host. Re-rendering
        // unconditionally is also simply correct — the file is derived entirely
        // from the record, so rendering it again cannot lose anything.
        $secrets = $this->storedSecrets($application);

        // Nothing stored means this site predates the column or was not installed
        // by this installer. Re-rendering with fresh secrets would rotate one half
        // of a working pair, so leave the file alone and let the URL be wrong
        // visibly rather than break the database invisibly.
        if ($secrets === []) {
            return;
        }

        $application->forceFill([
            'compose' => $this->render($type, $application, $url, $secrets),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function install(Application $application, string $documentRoot, array $context): void
    {
        $type = $this->typeFor($application);

        if ($type === null) {
            return;
        }

        // Named `sv-app-<id>_<role>`, the convention Compose itself uses for a
        // project's volumes — so a reader of `docker volume ls` sees one naming
        // scheme, and `DockerResources` can already attribute them to a site.
        $volumes = [];
        $mounts = [];

        foreach ($type->volumeRoles() as $role => $path) {
            $name = $this->containers->project($application)."_{$role}";
            $volumes[$role] = $name;
            $mounts[] = ['volume' => $name, 'path' => $path];
        }

        // Existing secrets first, and only the missing ones generated. `install()`
        // runs again on every Retry Setup and on a re-provision, and generating
        // afresh each time rotates the credential in the compose file while the
        // database keeps the one it was initialised with — `POSTGRES_PASSWORD`
        // and `MYSQL_*` apply to an EMPTY data directory and are ignored after
        // that. Measured: two apps crash-looping on "password authentication
        // failed for user" after a re-provision, with a correct-looking file.
        // `$stored + $fresh`, in that order. PHP's `+` keeps the LEFT operand
        // wherever a key exists in both, so stored secrets win and any key the app
        // has gained since it was installed still gets generated. Written the
        // other way round it rotates everything — which is the bug this fixes, and
        // which I wrote here first and caught only by running the union.
        $secrets = $this->storedSecrets($application) + $this->generate($type);

        $application->forceFill([
            'container_port' => $type->containerPort(),
            'volume_mounts' => $mounts,
            // Stored before the render that uses them, so a failure part-way
            // leaves the panel knowing what the file on disk contains.
            'docker_secrets' => $secrets,
            'compose' => $this->render(
                $type,
                $application,
                $this->url($application),
                $secrets,
                $volumes,
            ),
        ])->save();
    }

    /**
     * One value per declared key, per site.
     *
     * 32 URL-safe characters from `Str::random`, which is
     * `random_bytes`-backed. Long enough that it is not worth attacking and
     * short enough to paste; no punctuation, because these land in a YAML scalar
     * and in a dotenv line, and quoting rules differ between them.
     *
     * @return array<string, string>
     */
    private function generate(AbstractDockerAppType $type): array
    {
        $secrets = [];

        foreach ($type->generatedSecrets() as $key) {
            $secrets[$key] = Str::random(32);
        }

        return $secrets;
    }

    /**
     * The secrets this site was installed with.
     *
     * Read from the application, never recovered from the rendered file. Scanning
     * the compose file for the key name is the obvious approach and it is
     * silently wrong: Ghost's template writes `GHOST_DB_PASSWORD` into a key
     * called `database__connection__password`, so the scan finds nothing, decides
     * the secret is absent, and generates a new one — rotating the password in
     * the app's half of the file and not in MySQL's. The site comes back up
     * unable to reach its own database and nothing says why.
     *
     * @return array<string, string>
     */
    private function storedSecrets(Application $application): array
    {
        return array_map('strval', (array) ($application->docker_secrets ?? []));
    }

    /**
     * @param  array<string, string>  $secrets
     * @param  array<string, string>  $volumes
     */
    private function render(
        AbstractDockerAppType $type,
        Application $application,
        string $url,
        array $secrets,
        array $volumes = [],
    ): string {
        if ($volumes === []) {
            foreach ($type->volumeRoles() as $role => $path) {
                $volumes[$role] = $this->containers->project($application)."_{$role}";
            }
        }

        return View::make($type->composeTemplate(), [
            'project' => $this->containers->project($application),
            'appPort' => (int) $application->app_port,
            'containerPort' => $type->containerPort(),
            // The user's explicit choice, then the app's own floor, then the
            // server default. An app that needs 2g to start would otherwise get
            // 512m and fail as a 502 with nothing about memory in the panel.
            'memoryLimit' => (string) ($application->memory_limit
                ?: $type->defaultMemoryLimit()
                ?: config('server.docker.default_memory_limit', '512m')),
            // A database is not the application, and giving them one budget
            // means the app's ceiling is really the pair's. MySQL's own default
            // buffer pool alone is 128M.
            'dbMemoryLimit' => (string) config('server.docker.default_db_memory_limit', '512m'),
            'url' => $url,
            'secrets' => $secrets,
            'volumes' => $volumes,
            'image' => (string) config("server.docker_apps.{$type->name()}.image"),
            'dbImage' => (string) config("server.docker_apps.{$type->name()}.db_image"),
        ])->render();
    }

    /**
     * HTTPS, always — every app here builds links from it, and a site created
     * over http that later gets a certificate would keep serving mixed content
     * from a value nothing revisits.
     */
    private function url(Application $application): string
    {
        return 'https://'.$application->domain;
    }

    private function typeFor(Application $application): ?AbstractDockerAppType
    {
        $type = $this->siteTypes->find((string) $application->site_type);

        return $type instanceof AbstractDockerAppType ? $type : null;
    }
}
