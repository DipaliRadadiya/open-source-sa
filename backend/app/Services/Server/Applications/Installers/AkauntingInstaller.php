<?php

namespace App\Services\Server\Applications\Installers;

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Akaunting — accounting and invoicing.
 *
 * Its `install` command takes every value as an option but asks for whatever
 * it wasn't given, so the two passwords are omitted and answered on stdin.
 * It uses Laravel's `$this->secret()`, which on this version routes to
 * Symfony's question helper — the one that reads a pipe. Laravel Prompts,
 * which does not, is what makes checking rather than assuming worthwhile.
 *
 * The one trap is `--no-interaction`: with it, a missing option is a hard
 * error rather than a question, so passing it would defeat the whole
 * arrangement.
 */
class AkauntingInstaller extends AbstractSiteInstaller
{
    public function siteType(): string
    {
        return 'akaunting';
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

        // Everything except the two passwords. Anything omitted becomes a
        // question, and a question nobody answers hangs until the timeout.
        $this->runAsSiteUser('install_app', $application, [
            $this->phpBinary($application), 'artisan', 'install',
            '--db-host='.($context['db_host'] ?? '127.0.0.1'),
            '--db-port=3306',
            '--db-name='.$context['database'],
            '--db-username='.$context['db_user'],
            '--db-prefix='.($settings['table_prefix'] ?? ''),
            '--company-name='.($settings['company_name'] ?? $application->name),
            '--company-email='.($settings['company_email'] ?? $settings['admin_email'] ?? ''),
            '--admin-email='.($settings['admin_email'] ?? ''),
            '--locale='.($settings['locale'] ?? 'en-GB'),
        ],
            // Asked in the order the command asks them: database, then admin.
            $context['db_password']."\n".($settings['admin_password'] ?? '')."\n",
            $documentRoot,
        );
        $steps[] = 'install_app';

        return $steps;
    }

    /**
     * @throws ProvisioningFailedException
     */
    protected function downloadUrl(): string
    {
        $configured = (string) config('server.installers.akaunting.download_url', '');

        if ($configured !== '') {
            return $configured;
        }

        $response = Http::timeout(15)->acceptJson()
            ->get((string) config('server.installers.akaunting.releases_api'));

        $url = collect($response->successful() ? $response->json('assets') ?? [] : [])
            ->pluck('browser_download_url')
            ->first(fn ($candidate) => is_string($candidate) && str_ends_with($candidate, '.zip'));

        if (! is_string($url)) {
            throw new ProvisioningFailedException('download', (string) Str::uuid());
        }

        return $url;
    }

    private function phpBinary(Application $application): string
    {
        $version = $application->php_version ?: config('server.default_php_version', '8.4');

        return str_replace('{version}', (string) $version, (string) config('server.php_binary_pattern', '/usr/bin/php{version}'));
    }
}
