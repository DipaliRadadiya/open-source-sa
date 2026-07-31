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
    public function install(Application $application, string $documentRoot, array $context): array
    {
        $settings = $application->settings ?? [];
        $steps = [];

        $this->downloadAndExtract($application, null, $documentRoot);
        $steps[] = 'download';
        $steps[] = 'extract';

        $configDir = $documentRoot.'/'.trim((string) config('server.installers.mautic.config_dir', 'config'), '/');
        $this->run('configure', ['mkdir', '-p', $configDir], $application);

        // Everything the installer needs, so that nothing has to be argued
        // for on a command line.
        $this->writeSecretFile($application, "{$configDir}/local.php", View::make('server.apps.mautic.local', [
            'host' => $context['db_host'] ?? '127.0.0.1',
            'port' => $context['db_port'] ?? 3306,
            'database' => $context['database'],
            'username' => $context['db_user'],
            'password' => $context['db_password'],
            'adminEmail' => $settings['admin_email'] ?? '',
            'adminUsername' => $settings['admin_user'] ?? 'admin',
            'adminPassword' => $settings['admin_password'] ?? '',
            'adminFirstName' => $settings['admin_first_name'] ?? 'Admin',
            'adminLastName' => $settings['admin_last_name'] ?? 'User',
            'siteUrl' => 'https://'.$application->domain,
        ])->render());
        $steps[] = 'configure';

        $this->runAsSiteUser('install_app', $application, [
            $this->phpBinary($application), 'bin/console', 'mautic:install',
            'https://'.$application->domain,
            '--no-interaction',
        ], null, $documentRoot);
        $steps[] = 'install_app';

        return $steps;
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
