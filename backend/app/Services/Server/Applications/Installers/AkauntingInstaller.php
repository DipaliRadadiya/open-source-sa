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
 * it wasn't given.
 *
 * The passwords go on the command line here, which every other installer in
 * this directory deliberately avoids — `ps` is readable by every user on the
 * machine. It is not a preference; it is the only route Akaunting still
 * supports.
 *
 * They used to be answered on stdin, on the reasoning that `$this->secret()`
 * routed to Symfony's question helper, which reads a pipe. That was true when
 * it was written and is not true now: this installer fetches Akaunting's
 * LATEST release, upstream moved to Laravel Prompts, and Prompts sees stdin is
 * not a TTY and returns the default without reading a byte. The install then
 * proceeded with an empty database password and failed at "Could not connect
 * to the database" — a message that blames the credentials it was never given.
 *
 * `--no-interaction` is now correct rather than a trap. With every value
 * supplied there is nothing left to ask, and it turns a version that would
 * otherwise sit waiting on a prompt into an immediate error instead of a job
 * that hangs until the timeout.
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
        $settings = $application->settings ?? [];

        $this->downloadAndExtract($application, null, $documentRoot);

        // Every value supplied, including the passwords. Anything omitted
        // becomes a question, and this version answers its own questions with
        // the default rather than reading the pipe — so an omitted password is
        // an empty password, not a prompt.
        $this->runAsSiteUser('install_app', $application, [
            $this->phpBinary($application), 'artisan', 'install',
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
}
