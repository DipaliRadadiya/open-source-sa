<?php

namespace App\Services\Applications\Types;

use App\Contracts\CloneStrategy;
use App\Contracts\SiteType;
use App\Contracts\StagingStrategy;
use App\Models\Application;
use App\Rules\SupportedPhpVersion;
use App\Services\Server\Php\PhpVersionManager;
use App\Services\Server\Php\ServerDefaultPhp;

/**
 * Shared defaults and field helpers, so a concrete site type is mostly just
 * its field list.
 */
abstract class AbstractSiteType implements SiteType
{
    /**
     * Just the declared profile: for most types the product fixes the rendering. Only a
     * type that lets the user decide how it is served overrides this (`GitSiteType`).
     *
     * @return array<int, string>
     */
    public function servingProfiles(): array
    {
        return [$this->servingProfile()];
    }

    /**
     * Derived rather than listed; a type overrides this only for its differences.
     *
     * @return array<int, string>
     */
    public function features(): array
    {
        // True of every site. No `app_setting`: enable/disable lives on the Dashboard.
        $features = [
            'app_dashboard',
            'app_domain',
            'app_log',
            'app_backup',
            'app_file',
            'app_security',
            'app_firewall',
            'app_bot_blocker',
            'app_fail2ban',
        ];

        // Cloning copies the served files; a database-backed type needs its own recipe on
        // top. `CloneManager` refuses that combination only inside the queued job, so
        // offering the feature here would accept the POST and fail later.
        //
        // The cheap half of the condition is asked here (resolving `cloneStrategy()` is a
        // deep container resolution on every permissions request); a type with a recipe adds
        // `app_clone` back in its own `features()`. `ApplicationFeaturesTest` pins the set.
        if (! $this->needsDatabase()) {
            $features[] = 'app_clone';
        }

        // Deployment is a git screen; a one-click install has no repository.
        if ($this->method() === 'git') {
            $features[] = 'app_deployment';
        }

        // PHP settings edit a pool and a php.ini; static and Node sites have neither.
        if ($this->servingProfile() === 'php') {
            $features[] = 'app_php';
        }

        // Background processes: a Node process or a deployed application's queue workers.
        // A marketplace PHP app manages its own scheduling.
        if ($this->servingProfile() === 'node' || $this->method() === 'git') {
            $features[] = 'app_worker';
        }

        // An environment file the panel owns; a marketplace PHP app keeps its configuration
        // in its own file (wp-config.php).
        if ($this->method() === 'git' || $this->servingProfile() === 'node') {
            $features[] = 'app_environment';
        }

        // Anything a container serves (BYO-image and one-click apps alike) has a container
        // and compose file to configure. Declared here, not on `DockerSiteType` only, or the
        // credentials endpoint 404s for one-click apps. A container gets no `app_environment`:
        // its variables live in the compose file.
        if ($this->servingProfile() === 'docker') {
            $features[] = 'app_container';
            $features[] = 'app_compose';
        }

        return $features;
    }

    public function popular(): bool
    {
        return false;
    }

    /**
     * Most applications use a cookie or session, which does not collide with Basic Auth;
     * only ones that put a token in the `Authorization` header override this.
     */
    public function authorizationHeaderAuth(): bool
    {
        return false;
    }

    public function needsDatabase(): bool
    {
        return false;
    }

    public function needsInternalPort(): bool
    {
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * Fields every application has, whatever its type.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function commonFields(): array
    {
        return [
            $this->field('name', 'text', required: true),
            $this->field('domain', 'domain', required: true),
            $this->field('system_user_id', 'select', required: true, extra: ['source' => 'system_users']),
        ];
    }

    /**
     * Runtime + document-root fields for anything served by PHP.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function phpFields(): array
    {
        return [
            $this->field('php_version', 'select', extra: [
                'source' => 'php_versions',
                'default' => $this->defaultPhpVersion(),
            ]),
            $this->field('web_root', 'text', advanced: true, extra: ['default' => $this->defaultWebRoot()]),
        ];
    }

    /**
     * Runtime + port fields for anything that runs its own process.
     *
     * The port is optional and advanced: the panel allocates a free one. There is no
     * start-command field; the installer writes it.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function nodeFields(): array
    {
        return [
            $this->field('node_version', 'select', extra: ['source' => 'node_versions']),
            $this->field('app_port', 'number', advanced: true, extra: [
                'help' => __('application.help.app_port'),
            ]),
        ];
    }

    /**
     * The PHP version the create form opens on.
     *
     * Must be a version that is installed (the form would otherwise pre-select one the server
     * rejects). The server's default wins when installed and in range ({@see ServerDefaultPhp});
     * otherwise the newest installed one; null when nothing was detected.
     *
     * Read fresh, not memoized: it is only a `glob`, and a cached answer in a long-lived
     * queue worker would keep offering a removed version.
     */
    protected function defaultPhpVersion(): ?string
    {
        $installed = $this->installedPhpVersionsInRange();

        if ($installed === []) {
            return null;
        }

        $default = app(ServerDefaultPhp::class)->version();

        return in_array($default, $installed, true) ? $default : $installed[0];
    }

    /**
     * Installed PHP versions this type can actually run on, newest first.
     *
     * `versions()` sorts descending, so without the narrowing the newest PHP wins even when
     * the application has never run on it.
     *
     * Falls back to the unfiltered list when nothing matches: an empty select cannot be
     * submitted, and the validation rule can explain the problem instead.
     *
     * @return array<int, string>
     */
    protected function installedPhpVersionsInRange(): array
    {
        $installed = app(PhpVersionManager::class)->versions();
        $range = $this->supportedPhpRange();

        if ($installed === [] || $range === null) {
            return $installed;
        }

        $within = array_values(array_filter(
            $installed,
            fn (string $version): bool => SupportedPhpVersion::within($range['min'] ?? null, $range['max'] ?? null, $version),
        ));

        return $within === [] ? $installed : $within;
    }

    /**
     * Nothing by default: most types keep private files outside the web root or narrow it to
     * a public folder.
     *
     * @return array<int, string>
     */
    public function deniedPaths(): array
    {
        return [];
    }

    /**
     * @return array<int, array{directory: string, script: string}>
     */
    public function subdirectoryFrontControllers(): array
    {
        return [];
    }

    /**
     * @return array<int, string>
     */
    public function serverOnlyPaths(): array
    {
        return [];
    }

    /**
     * @return array{redirects: array<string, string>, fallback: string|null}
     */
    public function wellKnownRoutes(): array
    {
        return ['redirects' => [], 'fallback' => null];
    }

    /**
     * @return array<string, string>
     */
    public function mimeTypes(): array
    {
        return [];
    }

    /**
     * None by default (a git site's code is the user's own).
     */
    public function lockedEnvironmentKeys(): array
    {
        return [];
    }

    public function panelManagedEnvironmentKeys(): array
    {
        return [];
    }

    public function defaultWebRoot(): string
    {
        return '/';
    }

    /**
     * The only web root this type can be served from, or null when any works.
     *
     * Distinct from {@see defaultWebRoot()}: that pre-fills the field, this is what the site
     * can actually run on. Craft ships `index.php` inside `web/`; any other root 403s while
     * serving the application's source and `.env`.
     */
    public function fixedWebRoot(): ?string
    {
        return null;
    }

    /**
     * The server default (`server.applications.memory_max`), for types with no figure of
     * their own. A type whose application documents a higher minimum overrides it:
     * `MemoryMax` kills rather than throttles, so too low a value means a dead unit and a 502.
     */
    public function defaultMemoryMax(): ?string
    {
        return null;
    }

    /**
     * No constraint by default.
     *
     * Without it the panel would accept a Node version the application refuses to run under
     * and serve a 502 from a process that exited on startup. A range rather than a list:
     * projects state support as a floor and sometimes a ceiling.
     *
     * @return array{min: ?string, max: ?string}|null
     */
    public function supportedNodeRange(): ?array
    {
        return null;
    }

    /**
     * Nothing by default. A type that installs an npm package names it, and its Node range
     * is read from that package.
     */
    public function npmPackage(): ?string
    {
        return null;
    }

    /**
     * No opinion by default (a blank PHP site or git deployment runs whatever the user's code
     * runs); only a type whose application publishes a supported range overrides it.
     *
     * @return array{min: ?string, max: ?string}|null
     */
    public function supportedPhpRange(): ?array
    {
        return null;
    }

    public function supportedPhpRangeFor(Application $application): ?array
    {
        return $this->supportedPhpRange();
    }

    /**
     * No recipe by default; a type overrides this once one has been built for it.
     */
    public function stagingStrategy(): ?StagingStrategy
    {
        return null;
    }

    public function cloneStrategy(): ?CloneStrategy
    {
        return null;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function field(string $name, string $type, bool $required = false, bool $advanced = false, array $extra = []): array
    {
        return array_merge([
            'name' => $name,
            // Localized here so the frontend never holds a field-label list.
            'label' => __("application.fields.{$name}"),
            'type' => $type,
            'required' => $required,
            'advanced' => $advanced,
        ], $extra);
    }
}
