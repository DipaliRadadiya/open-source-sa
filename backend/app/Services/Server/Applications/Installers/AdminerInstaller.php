<?php

namespace App\Services\Server\Applications\Installers;

use App\Contracts\ApplicationUpdater;
use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Services\Server\Applications\AdminerSso;
use App\Services\Server\Applications\Installers\Concerns\ResolvesUpdates;
use Illuminate\Support\Str;

/**
 * Adminer — a web client for the server's MySQL, MariaDB and PostgreSQL
 * databases, shipped upstream as a single PHP file.
 *
 * Like phpMyAdmin it is a tool pointed at databases that already exist, so it
 * needs no database of its own and holds no credentials of its own.
 *
 * Laid out as two files: `adminer.php` is upstream's, byte for byte, and
 * `index.php` is the panel's wrapper that includes it. Stock Adminer's login
 * form takes any host and any driver, which on a public URL is a probe for
 * whatever the server can reach; the wrapper narrows it to this server's own
 * database servers ({@see AdminerSso}). Keeping upstream's file untouched is
 * what lets an update be "replace one file".
 */
class AdminerInstaller extends AbstractPhpInstaller implements ApplicationUpdater
{
    use ResolvesUpdates;

    public function siteType(): string
    {
        return 'adminer';
    }

    /**
     * Adminer reads the databases that are already on the server, using the
     * credentials of whoever logs in.
     */
    public function needsDatabase(): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function install(Application $application, string $documentRoot, array $context): void
    {
        $this->fetchAdminer($application, $this->latestVersion() ?? (string) config('server.installers.adminer.version'), $documentRoot);

        // The wrapper that locks Adminer to this server — written over the
        // provisioner's placeholder index.php — and the script the panel's
        // one-click sign-in redirects to.
        $result = app(AdminerSso::class)->installShim($application);

        if ($result->failed()) {
            throw new ProvisioningFailedException('configure', $result->reference);
        }

        $this->progress->record('configure');
    }

    // ---- One-click update (ApplicationUpdater) ---------------------------

    public function installedVersion(Application $application): ?string
    {
        $source = (string) $this->readSiteFile($application, $application->documentRoot().'/adminer.php');

        // The compiled file defines `const VERSION = "6.1.2"` in its own
        // namespace; that line is how Adminer reports itself in its footer.
        if (preg_match('/\bconst\s+VERSION\s*=\s*["\'](\d+\.\d+\.\d+)["\']/', $source, $match) !== 1) {
            return null;
        }

        return $this->normalizeVersion($match[1]);
    }

    public function availableVersions(Application $application, string $installed): array
    {
        $versions = $this->releasedVersions();

        return ['latest' => $this->highestInLine($versions, $installed), 'newest' => $this->highest($versions)];
    }

    /**
     * The target release over the old `adminer.php`. `index.php` and `sso.php`
     * are the panel's and are not in the download, so they survive as they
     * are.
     */
    public function update(Application $application, string $from, string $to): void
    {
        $this->fetchAdminer($application, $to, $application->documentRoot());
    }

    /**
     * Download the single-file build into the web root as `adminer.php`.
     *
     * Into a work directory first and copied in only once complete, so a
     * failed download never leaves a half-written PHP file being served.
     *
     * @throws ProvisioningFailedException
     */
    private function fetchAdminer(Application $application, string $version, string $documentRoot): void
    {
        $url = str_replace('{version}', $this->normalizeVersion($version), (string) config('server.installers.adminer.download_url'));

        $work = rtrim((string) config('server.installer_work_dir', sys_get_temp_dir()), '/')
            .'/install-'.Str::uuid();

        $this->run('download', ['mkdir', '-p', "{$work}/src"], $application);

        // --fail so a GitHub error page is never saved as adminer.php; --proto
        // to refuse anything but https, including on redirects.
        $this->run('download', [
            'curl', '--fail', '--location', '--silent', '--show-error',
            '--proto', '=https', '--proto-redir', '=https',
            '--max-time', (string) $this->timeout(),
            '--output', "{$work}/src/adminer.php", $url,
        ], $application);

        $this->copyIntoSite($application, "{$work}/src", $documentRoot, $work);

        $this->serverOps->run(['rm', '-rf', $work], ['feature' => 'application', 'op' => 'installer.cleanup']);
    }

    /** The newest stable release, or null when GitHub cannot be asked. */
    private function latestVersion(): ?string
    {
        return $this->highest($this->releasedVersions());
    }

    /** @return array<int, string> */
    private function releasedVersions(): array
    {
        $endpoint = trim((string) config('server.installers.adminer.releases_api', ''));

        return $endpoint === '' ? [] : array_map(
            fn (array $release): string => $this->normalizeVersion($release['tag_name']),
            $this->githubReleases($endpoint),
        );
    }
}
