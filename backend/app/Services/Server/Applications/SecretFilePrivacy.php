<?php

namespace App\Services\Server\Applications;

use App\Models\Application;
use App\Services\Server\Php\RuntimeOwnership;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use Throwable;

/**
 * Keep a site's secret files away from every account that does not run it.
 *
 * A `.env` is often created 0644 (installers, `create-project`, git deploys), and on
 * OpenLiteSpeed the web server account (`nobody`) is in every site user's group, so even 0640
 * config files are readable by it.
 *
 * The mode follows RuntimeOwnership::secretFileMode(): a site running as its own user loses
 * the group too (`go-rwx`); where PHP runs as the web server's account the group is handed to it
 * (`chown -h`) and only "other" is stripped, or the site would lose its own configuration.
 *
 * narrow() only removes access, so a file the user tightened stays as they left it.
 * Best-effort: a running site must not be failed over a file mode. reset() is the
 * "Fix permissions" version: absolute modes, and it reports a failure.
 */
class SecretFilePrivacy
{
    /**
     * Each type's secret files, relative to its code path (the project root). Includes files
     * the application writes itself (Joomla, PrestaShop, Nextcloud), at 0644 or 0640.
     *
     * @var array<string, array<int, string>>
     */
    public const FILES = [
        'wordpress' => ['wp-config.php'],
        'moodle' => ['config.php'],
        'phpmyadmin' => ['config.inc.php'],
        'mautic' => ['config/local.php'],
        'nodebb' => ['config.json'],
        'nodered' => ['settings.js'],
        'joomla' => ['configuration.php'],
        'prestashop' => ['app/config/parameters.php'],
        'nextcloud' => ['config/config.php'],
    ];

    /**
     * Types whose application rewrites its secret file and loosens it again, so install and
     * deploy time is not enough. Swept every five minutes by `sites:narrow-app-secrets`.
     *
     *  - Joomla sets `configuration.php` to 0444 on every save of Global Configuration.
     *  - Nextcloud writes `config.php` at its `configfilemode` (default 0640, readable on
     *    OpenLiteSpeed); NextcloudInstaller sets the option, this covers older installs.
     *
     * Not PrestaShop: Symfony's dumpFile() keeps the existing file's mode.
     *
     * @var array<int, string>
     */
    public const REWRITTEN_BY_APP = ['joomla', 'nextcloud'];

    /**
     * Directories of secret files, with the name pattern inside them (a Statamic user is a
     * YAML file with a password hash, created with the default umask).
     *
     * @var array<string, array<string, string>>
     */
    public const DIRECTORIES = [
        'statamic' => ['users' => '*.yaml'],
    ];

    public function __construct(
        private ServerOps $serverOps,
        private RuntimeOwnership $ownership,
    ) {}

    /**
     * Also run by `sites:resync`, which is how sites installed before this
     * existed are repaired.
     */
    public function narrow(Application $application): void
    {
        try {
            $this->apply($application, reset: false);
        } catch (Throwable $e) {
            // Resolving the `.env` path can throw: leave the files alone, don't fail the site.
            report($e);
        }
    }

    /**
     * "Fix permissions": set each secret file to exactly the mode and group the site needs.
     *
     * narrow() cannot do this: a site whose PHP runs as the web server account breaks with a
     * `.env` at 0600, and only an absolute mode with the group handed back repairs that.
     *
     * Returns the first command that failed, or null. Not best-effort: the user pressed a
     * button and should hear that it did not work.
     */
    public function reset(Application $application): ?ServerOpsResult
    {
        return $this->apply($application, reset: true);
    }

    private function apply(Application $application, bool $reset): ?ServerOpsResult
    {
        $application->loadMissing('systemUser');
        $user = $application->systemUser?->username;

        if ($user === null) {
            return null;
        }

        $ownUser = $this->ownership->runsAsOwnUser($application);
        $group = $this->ownership->secretFileGroup($application);

        $mode = match (true) {
            $reset => $this->ownership->secretFileMode($application),
            $ownUser => 'go-rwx',
            default => 'o-rwx,g-w',
        };

        $failure = null;
        $record = function (ServerOpsResult $result) use (&$failure): void {
            if ($result->failed() && $failure === null) {
                $failure = $result;
            }
        };

        foreach ($this->directories($application) as $directory => $pattern) {
            // Own-user sites only (Statamic always is). Absent is ordinary, and `find` on a
            // missing directory exits non-zero, which reset() would report as a failure.
            if (! $ownUser || ! $this->serverOps->probe(['runuser', '-u', $user, '--', 'test', '-d', $directory], $this->context($application))->ok) {
                continue;
            }

            // The directory first: new files get the default umask, so closing it keeps them
            // private from creation. `-maxdepth 0 -type d` skips a planted link.
            $record($this->serverOps->run(
                ['runuser', '-u', $user, '--', 'find', $directory, '-maxdepth', '0', '-type', 'd', '-exec', 'chmod', $reset ? '0700' : 'go-rwx', '{}', '+'],
                $this->context($application),
            ));

            // `find` never follows links (-P default): a planted one is skipped, not chmodded.
            $record($this->serverOps->run(
                ['runuser', '-u', $user, '--', 'find', $directory, '-maxdepth', '1', '-type', 'f', '-name', $pattern, '-exec', 'chmod', $mode, '{}', '+'],
                $this->context($application),
            ));
        }

        foreach ($this->paths($application) as $path) {
            // Absent is the common case (most types have no `.env`).
            if (! $this->serverOps->probe(['runuser', '-u', $user, '--', 'test', '-f', $path], $this->context($application))->ok) {
                continue;
            }

            // reset() hands the group back both ways (else `www-data` lingers after a move).
            if (! $ownUser || $reset) {
                // `-h`: a link at the path is changed itself, never followed.
                $record($this->serverOps->run(['chown', '-h', "{$user}:{$group}", $path], $this->context($application)));
            }

            // As the site user, so a planted link reaches only what they could already change.
            $record($this->serverOps->run(['runuser', '-u', $user, '--', 'chmod', $mode, $path], $this->context($application)));
        }

        return $failure;
    }

    /**
     * @return array<int, string>
     */
    private function paths(Application $application): array
    {
        // Resolved here, not injected (container cycle via the provisioner). path() not exists():
        // exists() also moves a legacy `.env`, which a resync must not do.
        $paths = [app(ApplicationEnvironment::class)->path($application)];

        $root = rtrim($application->codePath(), '/');

        foreach (self::FILES[(string) $application->site_type] ?? [] as $relative) {
            $paths[] = "{$root}/{$relative}";
        }

        return array_values(array_unique($paths));
    }

    /**
     * @return array<string, string> directory => name pattern
     */
    private function directories(Application $application): array
    {
        $root = rtrim($application->codePath(), '/');
        $directories = [];

        foreach (self::DIRECTORIES[(string) $application->site_type] ?? [] as $relative => $pattern) {
            $directories["{$root}/{$relative}"] = $pattern;
        }

        return $directories;
    }

    /**
     * @return array<string, mixed>
     */
    private function context(Application $application): array
    {
        return ['feature' => 'application', 'op' => 'secret_privacy', 'application' => $application->id];
    }
}
