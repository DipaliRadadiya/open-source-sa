<?php

namespace App\Services\Server\Applications\Installers;

use App\Contracts\ApplicationUpdater;
use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Services\Server\Applications\Installers\Concerns\ResolvesUpdates;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

/**
 * Mautic — marketing automation.
 *
 * `mautic:install` merges parameters from `local.php` before CLI options, so the whole
 * configuration (database and administrator) is written to that file and the command runs with
 * no secrets on its command line.
 *
 * The package is a flat zip: entries start at `.env.test` and `favicon.ico`, with no wrapping
 * directory.
 */
class MauticInstaller extends AbstractPhpInstaller implements ApplicationUpdater
{
    use ResolvesUpdates;

    public function siteType(): string
    {
        return 'mautic';
    }

    protected function archiveFormat(): string
    {
        return 'zip';
    }

    /**
     * What Mautic's own installer enforces: MySQL 8.4.0+ and MariaDB 10.11.0+.
     *
     * Follows upstream, since the panel installs the latest release. Without it, older engines
     * were offered Mautic and `mautic:install` then cancelled after the site was created.
     *
     * @return array<string, string>
     */
    public function minimumEngineVersions(): array
    {
        return ['mysql' => '8.4.0', 'mariadb' => '10.11.0'];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function install(Application $application, string $documentRoot, array $context): void
    {
        $settings = $application->installSettings();

        $this->downloadAndExtract($application, null, $documentRoot);

        $configDir = $documentRoot.'/'.trim((string) config('server.installers.mautic.config_dir', 'config'), '/');
        $this->run('configure', ['mkdir', '-p', $configDir], $application);

        // Everything the installer needs, so no secret goes on a command line. `site_url` is
        // deliberately absent: with both db_driver and site_url in local.php Mautic prints
        // "already installed" and exits 0 without creating tables. The URL is the command's
        // positional argument; Mautic writes it to local.php itself.
        $this->writeSecretFile($application, "{$configDir}/local.php", View::make('server.apps.mautic.local', [
            'parameters' => [
                'db_driver' => 'pdo_mysql',
                'db_host' => (string) ($context['db_host'] ?? '127.0.0.1'),
                'db_port' => (int) ($context['db_port'] ?? 3306),
                'db_name' => (string) $context['database'],
                'db_user' => (string) $context['db_user'],
                'db_password' => (string) $context['db_password'],
                'db_table_prefix' => null,
                'db_backup_tables' => false,
                'admin_email' => (string) ($settings['admin_email'] ?? ''),
                'admin_username' => (string) ($settings['admin_user'] ?? 'admin'),
                'admin_password' => (string) ($settings['admin_password'] ?? ''),
                'admin_firstname' => (string) ($settings['admin_first_name'] ?? 'Admin'),
                'admin_lastname' => (string) ($settings['admin_last_name'] ?? 'User'),
                'site_title' => (string) ($settings['site_title'] ?? $application->name),
                // Mautic 5+ reads one DSN and ignores the Mautic 4 mailer_* keys. See mailerDsn().
                'mailer_dsn' => $this->mailerDsn($settings),
                'mailer_from_email' => (string) (($settings['mailer_email'] ?? '') ?: ($settings['admin_email'] ?? '')),
                'mailer_from_name' => (string) (($settings['mailer_name'] ?? '') ?: ($settings['site_title'] ?? $application->name)),
            ],
        ])->render());

        $this->runAsSiteUser('install_app', $application, [
            ...$this->phpCommand($application), 'bin/console', 'mautic:install',
            $application->url(),
            // Mautic asks to confirm on recommendations (incl. 512M memory). With nobody to
            // answer, --no-interaction declines at step zero yet exits 0. --force continues
            // past recommendations; hard requirements still fail. The 512M limit is met by
            // `phpCommand()` on the interpreter.
            '--force',
            '--no-interaction',
        ], null, $documentRoot);

        // "Already installed" and a declined step-zero recommendation both exit 0, so query a
        // table the installer creates instead of trusting the exit code. Read-only, no credentials.
        $this->runAsSiteUser('verify_install', $application, [
            ...$this->phpCommand($application), 'bin/console', 'doctrine:query:sql',
            'SELECT COUNT(*) FROM users',
            '--no-interaction',
        ], null, $documentRoot);

        $this->scheduleJobs($application, $documentRoot);
    }

    /**
     * Mautic's cron jobs. Without them segments never fill, campaigns never run and queued
     * messages are never sent; Mautic leaves them to the host.
     *
     * The required set from Mautic's documentation plus imports and webhooks, staggered across
     * each quarter hour so no two start in the same minute, with the installer's memory limit
     * (see phpCommand()).
     */
    private function scheduleJobs(Application $application, string $documentRoot): void
    {
        $memory = array_slice($this->phpCommand($application), 1);

        foreach ([
            'mautic:segments:update' => ['segments', '0,15,30,45 * * * *'],
            'mautic:campaigns:update' => ['campaign members', '5,20,35,50 * * * *'],
            'mautic:campaigns:trigger' => ['campaign events', '10,25,40,55 * * * *'],
            'mautic:messages:send' => ['queued messages', '2,17,32,47 * * * *'],
            'mautic:custom-field:create-column' => ['custom fields', '7,22,37,52 * * * *'],
            'mautic:import' => ['imports', '12,27,42,57 * * * *'],
            'mautic:webhooks:process' => ['webhooks', '3,18,33,48 * * * *'],
        ] as $command => [$label, $expression]) {
            $this->scheduleCron($application, $documentRoot, 'bin/console '.$command, $expression, 'Mautic '.$label, $memory);
        }
    }

    public function syncUrl(Application $application, string $url): void
    {
        $documentRoot = $application->documentRoot();
        $path = $documentRoot.'/'.trim((string) config('server.installers.mautic.config_dir', 'config'), '/').'/local.php';

        $changed = $this->configMutator->transform($application, $path, function (string $contents) use ($url): string {
            $literal = var_export($url, true);
            $updated = preg_replace(
                "/('site_url'\\s*=>\\s*)'(?:\\\\.|[^'])*'/",
                '$1'.$literal,
                $contents,
                1,
                $count,
            );

            if (is_string($updated) && $count === 1) {
                return $updated;
            }

            $updated = preg_replace('/\\n];\\s*$/', "\n  'site_url' => {$literal},\n];\n", $contents, 1, $count);

            if (! is_string($updated) || $count !== 1) {
                throw new \RuntimeException('Mautic parameters array was not found.');
            }

            return $updated;
        });

        if ($changed) {
            $this->runAsSiteUser('sync_url', $application, [
                ...$this->phpCommand($application), 'bin/console', 'mautic:cache:clear', '--no-interaction',
            ], null, $documentRoot);
        }
    }

    /**
     * The full package for the current release — not the update package, which
     * carries only changed files and would unpack into an unusable site.
     *
     * @throws ProvisioningFailedException
     */
    protected function downloadUrl(): string
    {
        $configured = (string) config('server.installers.mautic.download_url', '');

        if ($configured !== '') {
            return $configured;
        }

        // An unreachable release host gets its own reason, not a bare "download failed".
        try {
            $response = Http::timeout(15)->acceptJson()
                ->get((string) config('server.installers.mautic.releases_api'));
        } catch (ConnectionException) {
            throw new ProvisioningFailedException('download', (string) Str::uuid(), 'download_unreachable');
        }

        $url = collect($response->successful() ? $response->json('assets') ?? [] : [])
            ->pluck('browser_download_url')
            ->first(fn ($candidate) => is_string($candidate)
                && str_ends_with($candidate, '.zip')
                && ! str_contains($candidate, '-update.'));

        if (! is_string($url)) {
            throw new ProvisioningFailedException('download', (string) Str::uuid(), $response->successful() ? 'release_not_found' : 'download_unreachable');
        }

        return $url;
    }

    /**
     * The Symfony Mailer DSN for the SMTP details given, or `null://null` (sending off) when
     * there are none. `smtps://` on 465 (TLS from the first byte), `smtp://` elsewhere (STARTTLS
     * when offered). Credentials are URL-encoded so `@` or `/` is not read as part of the host.
     *
     * @param  array<string, mixed>  $settings
     */
    private function mailerDsn(array $settings): string
    {
        $host = trim((string) ($settings['mailer_host'] ?? ''));

        if ($host === '') {
            return 'null://null';
        }

        $port = (int) ($settings['mailer_port'] ?? 587) ?: 587;
        $user = (string) ($settings['mailer_username'] ?? '');
        $auth = $user === '' ? '' : rawurlencode($user).':'.rawurlencode((string) ($settings['mailer_password'] ?? '')).'@';

        return ($port === 465 ? 'smtps' : 'smtp').'://'.$auth.$host.':'.$port;
    }

    // ---- One-click update (ApplicationUpdater) ---------------------------

    public function installedVersion(Application $application): ?string
    {
        $json = json_decode((string) $this->readSiteFile($application, $application->documentRoot().'/app/release_metadata.json'), true);
        $version = is_array($json) ? ($json['version'] ?? null) : null;

        return is_string($version) && $this->isStable($version) ? $this->normalizeVersion($version) : null;
    }

    public function availableVersions(Application $application, string $installed): array
    {
        $versions = array_map(
            fn (array $release): string => $this->normalizeVersion($release['tag_name']),
            $this->githubReleases($this->releasesList()),
        );

        return ['latest' => $this->highestInLine($versions, $installed), 'newest' => $this->highest($versions)];
    }

    /**
     * Mautic's own manual method: the full package of the target release over the old files,
     * then its migrations and a cache clear.
     *
     * Not `mautic:update:find` / `mautic:update:apply`: Mautic 7.x no longer has them.
     * `config/local.php` survives the copy because the package does not ship one.
     */
    public function update(Application $application, string $from, string $to): void
    {
        $documentRoot = $application->documentRoot();
        $release = collect($this->githubReleases($this->releasesList()))
            ->first(fn (array $release): bool => $this->normalizeVersion($release['tag_name']) === $to);
        $url = collect($release['assets'] ?? [])
            ->pluck('browser_download_url')
            ->first(fn ($candidate) => is_string($candidate)
                && str_ends_with($candidate, '.zip')
                && ! str_contains($candidate, '-update.'));

        if (! is_string($url)) {
            throw new ProvisioningFailedException('download', (string) Str::uuid(), 'release_not_found');
        }

        $this->downloadAndExtract($application, $url, $documentRoot);

        $php = $this->phpCommand($application);
        $this->runAsSiteUser('migrate', $application, [
            ...$php, 'bin/console', 'doctrine:migrations:migrate', '--no-interaction', '--allow-no-migration',
        ], null, $documentRoot);
        $this->runAsSiteUser('clear_cache', $application, [
            ...$php, 'bin/console', 'mautic:cache:clear', '--no-interaction',
        ], null, $documentRoot);
    }

    /** The list, not `/latest`: an update stays within the installed major. */
    private function releasesList(): string
    {
        return (string) config('server.installers.mautic.releases_list', 'https://api.github.com/repos/mautic/mautic/releases');
    }
}
