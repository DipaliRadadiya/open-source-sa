<?php

namespace App\Services\Server\Applications\Installers;

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

/**
 * Mautic — marketing automation.
 *
 * `mautic:install` takes every credential as a command-line option and never
 * prompts, which looked like the first installer that would have to put a
 * password in `ps`. Reading the command shows otherwise: it merges parameters
 * from `local.php` first and only lets CLI options override them. So the
 * entire configuration — database *and* administrator — is written to that
 * file, and the install command is invoked with no secrets at all.
 *
 * Its package is also the first zip in the set, and a flat one: entries start
 * at `.env.test` and `favicon.ico`, with no wrapping directory.
 */
class MauticInstaller extends AbstractPhpInstaller
{
    public function siteType(): string
    {
        return 'mautic';
    }

    protected function archiveFormat(): string
    {
        return 'zip';
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function install(Application $application, string $documentRoot, array $context): void
    {
        $settings = $application->settings ?? [];

        $this->downloadAndExtract($application, null, $documentRoot);

        $configDir = $documentRoot.'/'.trim((string) config('server.installers.mautic.config_dir', 'config'), '/');
        $this->run('configure', ['mkdir', '-p', $configDir], $application);

        // Everything the installer needs, so that no secret has to be argued
        // for on a command line. `site_url` is deliberately absent: Mautic
        // treats a local.php containing both db_driver and site_url as proof
        // that installation already finished, prints "Mautic already
        // installed", and exits 0 without creating a single table. The URL is
        // the command's non-secret positional argument instead, and Mautic
        // writes it into local.php itself in its final installation step.
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
                'mailer_transport' => 'smtp',
                'mailer_from_email' => (string) ($settings['mailer_email'] ?? ''),
                'mailer_from_name' => (string) ($settings['mailer_name'] ?? ''),
                'mailer_host' => (string) ($settings['mailer_host'] ?? ''),
                'mailer_port' => (int) ($settings['mailer_port'] ?? 587),
                'mailer_user' => (string) ($settings['mailer_username'] ?? ''),
                'mailer_password' => (string) ($settings['mailer_password'] ?? ''),
                'mailer_auth_mode' => null,
                'mailer_encryption' => null,
            ],
        ])->render());

        $this->runAsSiteUser('install_app', $application, [
            $this->phpBinary($application), 'bin/console', 'mautic:install',
            $application->url(),
            // Mautic treats recommendations (including its 512M memory
            // preference) as a confirmation prompt. With nobody to answer,
            // --no-interaction declines that prompt at step zero — whose
            // negated exit code is still zero. --force means continue past
            // recommendations; hard requirements still fail normally.
            '--force',
            '--no-interaction',
        ], null, $documentRoot);

        // Mautic has two known success-without-installing paths: "already
        // installed" and a declined step-zero recommendation both return 0.
        // Ask Doctrine for a table the installer creates instead of trusting
        // that exit code. This is read-only and carries no credentials.
        $this->runAsSiteUser('verify_install', $application, [
            $this->phpBinary($application), 'bin/console', 'doctrine:query:sql',
            'SELECT COUNT(*) FROM users',
            '--no-interaction',
        ], null, $documentRoot);
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
                $this->phpBinary($application), 'bin/console', 'mautic:cache:clear', '--no-interaction',
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

        $response = Http::timeout(15)->acceptJson()
            ->get((string) config('server.installers.mautic.releases_api'));

        $url = collect($response->successful() ? $response->json('assets') ?? [] : [])
            ->pluck('browser_download_url')
            ->first(fn ($candidate) => is_string($candidate)
                && str_ends_with($candidate, '.zip')
                && ! str_contains($candidate, '-update.'));

        if (! is_string($url)) {
            throw new ProvisioningFailedException('download', (string) Str::uuid());
        }

        return $url;
    }
}
