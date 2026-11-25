<?php

namespace App\Services\Server\Applications\Installers;

use App\Contracts\SiteInstaller;
use App\Enums\ApplicationStatus;
use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Services\Applications\SiteTypeManager;
use App\Services\Applications\Types\RecipeSiteType;
use App\Services\Recipes\Exceptions\RecipeRenderException;
use App\Services\Recipes\RecipeRenderer;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Applications\ContainerSupervisor;
use App\Services\Server\ManagedFile;
use App\Services\Server\ServerOps;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns a one-click Docker app into a compose file this site can run.
 *
 * One installer for every Docker app: the differences are entirely in their recipes (template,
 * port, volumes, secrets). It implements {@see SiteInstaller} directly rather than extending
 * `AbstractSiteInstaller`, whose constructor wants a process supervisor, a PHP shim and runtime
 * ownership that a container does not use.
 *
 * It renders the app's compose into `$application->compose` and records the volumes on
 * `volume_mounts`. It does not create the volumes or start anything:
 * `DockerResources::ensureFor()` and `ContainerSupervisor::apply()` do that on the provisioning
 * path right after this.
 *
 * The rendered file is stored per site: ordinary container deploys use the stored compose rather
 * than silently re-rendering current defaults. URL-aware recipes are re-rendered by `syncUrl()` on
 * domain changes and during `sites:resync`, so a recipe template or image-default change can reach
 * existing sites and must preserve compatibility.
 */
class DockerAppInstaller implements SiteInstaller
{
    public function __construct(
        private SiteTypeManager $siteTypes,
        private ContainerSupervisor $containers,
        private ManagedFile $files,
        private ServerOps $serverOps,
        private RecipeRenderer $recipes,
    ) {}

    /**
     * Every Docker app, so the registry maps them all here. The type is resolved per application
     * in `install()`; this value is only read for logging.
     */
    public function siteType(): string
    {
        return 'docker_app';
    }

    /**
     * No. A container brings its own database, so a Docker server manages no engine for the panel
     * to create one in.
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
     * Ghost builds every link and redirect from `url`, so a primary-domain change leaves assets
     * and login pointing at the old host. The compose file holds that value, so reconciling it
     * means re-rendering and bringing the container up again.
     */
    public function syncUrl(Application $application, string $url): void
    {
        $type = $this->typeFor($application);

        // Nothing to reconcile for an app that is never told its URL (Metabase, Wiki.js keep it in
        // their database), so a re-render would restart a container for no reason.
        if ($type === null || $type->urlEnvKey() === null) {
            return;
        }

        // No `str_contains($compose, 'url:')` guard: the key differs per app (`url` vs `URL`), and
        // re-rendering unconditionally is correct since the file derives entirely from the record.
        $secrets = $this->storedSecrets($application);

        // An app that HAS secrets and has none stored predates the column or was not installed by
        // this installer: re-rendering would generate fresh ones and rotate half a working pair,
        // so the URL is left visibly wrong rather than the database broken invisibly. An app that
        // declares no secrets is legitimately empty forever and must still be re-rendered.
        if ($type->generatedSecrets() !== [] && $secrets === []) {
            return;
        }

        $previous = $application->compose;
        $rendered = $this->renderFor($application, $url);

        // Installation publishes later through the provisioner. A disabled
        // application must never be started by a URL or certificate change;
        // enable() publishes its stored compose before restoring the vhost.
        /** @var ApplicationStatus $status The model casts this column to the enum. */
        $status = $application->status;
        if ($status !== ApplicationStatus::Active || $application->disabled_at !== null) {
            if ($rendered !== $previous) {
                $application->forceFill(['compose' => $rendered])->save();
            }

            return;
        }

        $root = app(ApplicationProvisioner::class)->documentRoot($application);
        $disk = $this->files->get($this->containers->composePath($application, $root), [
            'feature' => 'application', 'application' => $application->id,
        ]);

        if ($disk->failed()) {
            throw new ProvisioningFailedException('compose_read', $disk->reference);
        }

        $published = $disk->output();

        // Compare the actual file as well as the DB, so upgrades repair drift between them
        // without recreating healthy sites.
        if ($rendered === $previous && $rendered === $published) {
            return;
        }

        $application->forceFill(['compose' => $rendered])->save();

        try {
            $this->containers->apply($application, $root);
        } catch (Throwable $exception) {
            try {
                // Restore the bytes that were actually serving, not a database
                // render that may already have drifted from them.
                $application->forceFill(['compose' => $published])->save();
                $this->containers->apply($application, $root);
            } catch (Throwable) {
                // Preserve the original publish failure and its reference.
            } finally {
                try {
                    $application->forceFill(['compose' => $previous])->save();
                } catch (Throwable) {
                    // A failed compensation must not hide the publish failure.
                }
            }

            throw $exception;
        }
    }

    /**
     * Claim the first-run setup endpoint, for the apps that have one.
     *
     * Some apps (Chatwoot, n8n, Uptime Kuma) create no admin at install, only a setup page open
     * to whoever asks first, so an install that stopped at "started" would hand the site to the
     * first stranger. Which app needs it is declared by the type's `firstRunClaim()`; everything
     * else returns null and this is a no-op.
     *
     * This class implements `SiteInstaller` directly, so a method added to the interface lands
     * here as a fatal rather than as an inherited no-op.
     */
    public function afterStart(Application $application, string $documentRoot): void
    {
        $claim = $this->typeFor($application)?->firstRunClaim($application);

        if ($claim === null) {
            return;
        }

        $url = 'http://127.0.0.1:'.((int) $application->app_port).$claim['path'];

        // Already claimed: a Retry Setup on a site somebody has since logged into
        // must not try to create a second owner.
        if (! $this->claimEndpointOpen($application, $url)) {
            return;
        }

        // **The body goes on stdin, never in argv.** It carries the generated administrator
        // password: an argument is world-readable through `ps` and recorded verbatim in the
        // server-ops log (`CommandRedactor` matches `--flags` and URL query parameters, not
        // `user[password]=`).
        $body = http_build_query($claim['fields'], '', '&', PHP_QUERY_RFC1738);

        $command = [
            'curl', '-sS', '--fail-with-body', '--max-time', '60', '-X', 'POST',
            '-H', 'Content-Type: application/x-www-form-urlencoded',
            '--data-binary', '@-',
            $url,
        ];

        // `$this->serverOps->run()`, not `$this->run()`: that helper belongs to
        // `AbstractSiteInstaller`, which this class does not extend.
        $posted = $this->serverOps->run(
            $command,
            ['feature' => 'application', 'op' => 'installer.create_admin', 'application' => $application->id],
            timeout: 90,
            input: $body,
        );

        if ($posted->failed()) {
            throw new ProvisioningFailedException('create_admin', $posted->reference);
        }

        // The endpoint closes itself when it succeeds, so asking again is the only honest
        // confirmation that an owner exists: a Rails form post answers a redirect either way.
        if ($this->claimEndpointOpen($application, $url)) {
            // `claim_refused`, not `owner_not_created`: the post was ACCEPTED and no owner exists,
            // so the app rejected the credentials (e.g. Chatwoot refuses disposable email
            // addresses) and says so only in a flash its SPA never renders, with nothing in its
            // log. The message names that cause instead of describing the symptom.
            throw new ProvisioningFailedException('create_admin', $posted->reference, 'claim_refused');
        }
    }

    /**
     * Whether the first-run endpoint is still open, once the app can answer.
     *
     * Open is 200 (the setup page); closed is a redirect, served once an owner exists. Anything
     * else means "not yet": the container refuses connections on its way up, and Chatwoot migrates
     * its database on first boot, so this can be a minute or more of 502s.
     *
     * @throws ProvisioningFailedException
     */
    private function claimEndpointOpen(Application $application, string $url): bool
    {
        $attempts = max(1, (int) config('server.docker.claim_attempts', 90));

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            // Not `run()`, which fails the install on the first non-2xx — and
            // every status this is waiting through is a non-2xx.
            $response = $this->serverOps->run(
                ['curl', '-sS', '-o', '/dev/null', '-w', '%{http_code}', '--max-time', '10', $url],
                ['feature' => 'application', 'op' => 'installer.create_admin_wait', 'application' => $application->id],
                timeout: 20,
            );

            $status = $response->ok ? trim($response->output()) : '';

            if ($status === '200') {
                return true;
            }

            if ($status !== '' && str_starts_with($status, '3')) {
                return false;
            }

            if ($attempt < $attempts) {
                Sleep::for(2)->seconds();
            }
        }

        throw new ProvisioningFailedException('create_admin', (string) Str::uuid(), 'app_not_ready');
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
        $mounts = [];

        foreach ($type->volumeRoles() as $role => $path) {
            $name = $this->containers->project($application)."_{$role}";
            $mounts[] = ['volume' => $name, 'path' => $path];
        }

        // Existing secrets first, and only the missing ones generated: `install()` runs again on
        // every Retry Setup and re-provision, and fresh secrets would rotate the credential in
        // the compose file while the database keeps the one it was initialised with
        // (`POSTGRES_PASSWORD` and `MYSQL_*` apply to an EMPTY data directory only).
        // `$stored + $fresh`, in that order: PHP's `+` keeps the LEFT operand on a shared key.
        $secrets = $this->storedSecrets($application) + $this->generate($type);

        // Files the app cannot start without, bind-mounted into the site's directory. Written
        // before the compose file, so a failure stops provisioning rather than producing a
        // container that crash-loops on a missing config.
        $this->writeStarterFiles($type, $application, $documentRoot);

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
                $documentRoot,
            ),
        ])->save();
    }

    /**
     * One value per declared key, per site.
     *
     * 32 URL-safe characters from `Str::random` (`random_bytes`-backed). No punctuation, because
     * these land in a YAML scalar and a dotenv line, whose quoting rules differ.
     *
     * @return array<string, string>
     */
    private function generate(RecipeSiteType $type): array
    {
        $secrets = [];

        $complex = $type->complexSecrets();

        foreach ($type->generatedSecrets() as $key) {
            $secrets[$key] = in_array($key, $complex, true)
                ? $this->complexSecret()
                : Str::random(32);
        }

        return $secrets;
    }

    /**
     * A password that satisfies a policy, not merely a random one.
     *
     * `Str::random(32)` is alphanumeric only and rejected by an app that requires a symbol. Built
     * from one character of each class plus random filler and shuffled, so the guarantee is
     * structural, not probabilistic. Symbols are restricted to ones that survive a URL-encoded
     * form post, a YAML scalar and a shell argument.
     */
    private function complexSecret(): string
    {
        $password =
            Str::upper(Str::random(1))
            .Str::lower(Str::random(1))
            .(string) random_int(0, 9)
            .collect(str_split('!@#%^*-_=+'))->random()
            .Str::random(28);

        return collect(str_split($password))->shuffle()->implode('');
    }

    /**
     * The secrets this site was installed with.
     *
     * Read from the application, never recovered by scanning the rendered file: a template can
     * write one secret under a different key (Ghost's `GHOST_DB_PASSWORD` lands in
     * `database__connection__password`), so a scan would decide it is absent and generate a new
     * one, rotating the app's half of the pair and not the database's.
     *
     * @return array<string, string>
     */
    private function storedSecrets(Application $application): array
    {
        return array_map('strval', (array) ($application->docker_secrets ?? []));
    }

    /**
     * @param  array<string, string>  $secrets
     */
    private function render(
        RecipeSiteType $type,
        Application $application,
        string $url,
        array $secrets,
        ?string $documentRoot = null,
    ): string {
        return $this->renderRecipe($type, $application, $url, $secrets, $documentRoot);
    }

    /**
     * Render using the site's stored secrets, without changing the record or files.
     */
    public function renderFor(Application $application, string $url): ?string
    {
        $type = $this->typeFor($application);
        if ($type === null) {
            return null;
        }

        $secrets = $this->storedSecrets($application);
        if ($type->generatedSecrets() !== [] && $secrets === []) {
            return null;
        }

        return $this->render($type, $application, $url, $secrets);
    }

    private function renderRecipe(
        RecipeSiteType $type,
        Application $application,
        string $url,
        array $secrets,
        ?string $documentRoot,
    ): string {
        $root = $documentRoot ?? app(ApplicationProvisioner::class)->documentRoot($application);

        try {
            return $this->recipes->render($type->recipe(), $application, $url, $secrets, $root);
        } catch (RecipeRenderException $e) {
            $reference = (string) Str::uuid();
            Log::channel('server-ops')->error('Refused to render a recipe compose file', [
                'reference' => $reference,
                'feature' => 'application',
                'op' => 'recipe_render',
                'application' => $application->id,
                'field' => $e->field,
            ]);

            throw new ProvisioningFailedException('compose_write', $reference);
        }
    }

    /**
     * Write the starter files the recipe's compose template bind-mounts.
     *
     * Inside the site's document root, so `ComposeValidator` accepts the bind and the File Manager
     * can edit the result. Directories rather than single files: Docker creates a missing bind
     * source as a root-owned DIRECTORY, so mounting a not-yet-existing file hands the container a
     * directory where it wanted a config.
     *
     * Never overwrites: a reinstall or Retry Setup must not replace a configuration somebody has
     * edited.
     */
    private function writeStarterFiles(
        RecipeSiteType $type,
        Application $application,
        string $documentRoot,
    ): void {
        $root = rtrim($documentRoot, '/');

        foreach ($type->starterFiles() as $containerPath => $contents) {
            $directory = dirname($containerPath);
            $host = $root.$directory;

            $file = $host.'/'.basename($containerPath);
            $context = ['feature' => 'application', 'op' => 'docker_app_starter', 'application' => $application->id];

            // `get()`, not an `exists()` ManagedFile does not have: a successful read IS the
            // existence check, through the same elevated path as the write.
            if (! $this->files->get($file, $context)->failed()) {
                continue;
            }

            // **The directory first.** `ManagedFile::put()` is `tee <path>` and nothing more, so
            // writing into a missing directory fails.
            $made = $this->serverOps->run(
                ['mkdir', '-p', $host],
                $context + ['op' => 'docker_app_starter_dir'],
                timeout: 15,
            );

            if ($made->failed()) {
                throw new ProvisioningFailedException('docker_app_starter', $made->reference);
            }

            // The result is READ: ignoring it would report success with no config on disk.
            $written = $this->files->put($file, $this->dedent($contents), $context);

            if ($written->failed()) {
                throw new ProvisioningFailedException('docker_app_starter', $written->reference);
            }

            // **After** the write, not before: `tee` runs elevated, so the file it creates is
            // root's, and a chown of the directory beforehand would leave the file root-owned and
            // uneditable in the File Manager.
            $user = (string) ($application->systemUser?->username ?? '');

            if ($user !== '') {
                $this->serverOps->run(
                    ['chown', '-R', "{$user}:{$user}", $host],
                    $context + ['op' => 'docker_app_starter_chown'],
                    timeout: 15,
                );
            }
        }
    }

    /**
     * Strip the indentation a heredoc inside a class body carries.
     *
     * PHP 7.3+ already removes the closing marker's indentation; this is a safety net for a
     * template added with the marker at column zero, where YAML would arrive indented and parse as
     * one long string.
     */
    private function dedent(string $contents): string
    {
        $lines = explode("\n", $contents);
        $indents = [];

        foreach ($lines as $line) {
            if (trim($line) !== '') {
                $indents[] = strlen($line) - strlen(ltrim($line));
            }
        }

        $strip = $indents === [] ? 0 : min($indents);

        return implode("\n", array_map(
            fn (string $line): string => substr($line, $strip),
            $lines,
        ));
    }

    /**
     * HTTPS, always: every app builds links from it, and a site created over http that later gets
     * a certificate would keep serving mixed content.
     */
    private function url(Application $application): string
    {
        return 'https://'.$application->domain;
    }

    private function typeFor(Application $application): ?RecipeSiteType
    {
        $type = $this->siteTypes->find((string) $application->site_type);

        return $type instanceof RecipeSiteType ? $type : null;
    }
}
