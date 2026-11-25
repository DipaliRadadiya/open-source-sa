<?php

namespace App\Services\Server\Applications\Installers;

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Akaunting — accounting and invoicing.
 *
 * Unlike the other installers, the passwords go on the command line (visible to `ps`): the latest
 * Akaunting uses Laravel Prompts, which does not read answers from stdin and would take an empty
 * default. With every value supplied, `--no-interaction` makes a missing one fail fast instead of
 * hanging on a prompt.
 */
class AkauntingInstaller extends AbstractPhpInstaller
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
    public function install(Application $application, string $documentRoot, array $context): void
    {
        $settings = $application->installSettings();

        $this->downloadAndExtract($application, null, $documentRoot);

        // Supply every value: an omitted one becomes an empty default, not a prompt.
        $this->runAsSiteUser('install_app', $application, [
            ...$this->phpCommand($application), 'artisan', 'install',
            '--db-host='.($context['db_host'] ?? '127.0.0.1'),
            '--db-port='.($context['db_port'] ?? 3306),
            '--db-name='.$context['database'],
            '--db-username='.$context['db_user'],
            '--db-prefix='.($settings['table_prefix'] ?? ''),
            '--company-name='.($settings['company_name'] ?? $application->name),
            '--company-email='.($settings['company_email'] ?? $settings['admin_email'] ?? ''),
            '--admin-email='.($settings['admin_email'] ?? ''),
            '--locale='.($settings['locale'] ?? 'en-GB'),
            '--db-password='.$context['db_password'],
            '--admin-password='.($settings['admin_password'] ?? ''),
            '--no-interaction',
        ], null, $documentRoot);

        // Recurring invoices, reminders and other scheduled work run from Laravel's scheduler.
        $this->scheduleCron($application, $documentRoot, 'artisan schedule:run', '* * * * *', 'scheduler');
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

        // An unreachable release host gets its own reason.
        try {
            $response = Http::timeout(15)->acceptJson()
                ->get((string) config('server.installers.akaunting.releases_api'));
        } catch (ConnectionException) {
            throw new ProvisioningFailedException('download', (string) Str::uuid(), 'download_unreachable');
        }

        $url = collect($response->successful() ? $response->json('assets') ?? [] : [])
            ->pluck('browser_download_url')
            ->first(fn ($candidate) => is_string($candidate) && str_ends_with($candidate, '.zip'));

        if (! is_string($url)) {
            throw new ProvisioningFailedException('download', (string) Str::uuid(), $response->successful() ? 'release_not_found' : 'download_unreachable');
        }

        return $url;
    }

    /**
     * The canonical URL is `APP_URL` in the site's `.env`; Laravel builds emailed, queued and
     * scheduled links from it.
     *
     * The value is quoted because dotenv treats an unquoted `#` as the start of a comment.
     */
    public function syncUrl(Application $application, string $url): void
    {
        $documentRoot = $application->documentRoot();
        $url = rtrim($url, '/');

        $changed = $this->setEnvValue($application, $documentRoot.'/.env', 'APP_URL', '"'.$url.'"');

        if (! $changed) {
            return;
        }

        // A cached config outlives the `.env` change. Best-effort: failing here would roll back a
        // certificate over a cache file.
        try {
            $this->runAsSiteUser('sync_url', $application, [
                ...$this->phpCommand($application), 'artisan', 'config:clear',
            ], null, $documentRoot);
        } catch (ProvisioningFailedException $e) {
            report($e);
        }
    }
}
