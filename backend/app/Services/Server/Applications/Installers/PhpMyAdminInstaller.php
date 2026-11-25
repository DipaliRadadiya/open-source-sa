<?php

namespace App\Services\Server\Applications\Installers;

use App\Contracts\ApplicationUpdater;
use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Services\Server\Applications\Installers\Concerns\ResolvesUpdates;
use App\Services\Server\Applications\PhpMyAdminSso;

/**
 * phpMyAdmin, a web client for the server's databases.
 *
 * Not a site with its own data: it needs no database or credentials of its own and authenticates
 * as whoever logs in.
 *
 * Two things the upstream tarball leaves to whoever unpacks it, both critical on a public URL:
 *
 *  - **`blowfish_secret`** encrypts the cookie carrying a user's database credentials. A fixed
 *    one would let anybody holding it decrypt sessions on any install, so it is generated per
 *    installation.
 *  - **`setup/`** is a web wizard that writes configuration: on a public web root it is a
 *    configuration console on the open internet, so it is removed.
 */
class PhpMyAdminInstaller extends AbstractPhpInstaller implements ApplicationUpdater
{
    use ResolvesUpdates;

    public function siteType(): string
    {
        return 'phpmyadmin';
    }

    /**
     * phpMyAdmin reads the databases already on the server, using the credentials of whoever logs
     * in. Creating one would leave an empty database and a standing account it has no use for.
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

        $this->downloadAndExtract(
            $application,
            (string) config('server.installers.phpmyadmin.download_url'),
            $documentRoot,
        );

        // Kept beside the site, not inside it: phpMyAdmin writes uploads and exports here, and
        // anything under the document root is a URL somebody can fetch.
        //
        // Anchored on the app's own {home}/{slug}, not dirname($documentRoot): a flat site's
        // document root is AT {home}/{slug}, so dirname() would escape into the shared home.
        $appRoot = $application->rootPath();

        $tempDir = "{$appRoot}/tmp";
        $this->run('configure', ['mkdir', '-p', $tempDir], $application);
        $this->run('configure', [
            'chown', "{$application->systemUser->username}:{$application->systemUser->username}", $tempDir,
        ], $application);
        $this->run('configure', ['chmod', '0750', $tempDir], $application);

        $sso = app(PhpMyAdminSso::class);

        $this->writeSecretFile($application, "{$documentRoot}/config.inc.php", $sso->renderConfig(
            bin2hex(random_bytes(32)),
            (string) config('server.installers.phpmyadmin.db_host', '127.0.0.1'),
            $tempDir,
        ));

        // The one-click sign-in needs a script on this site to write the session phpMyAdmin reads;
        // without it the button redirects to a file that does not exist.
        $result = $sso->installShim($application);

        if ($result->failed()) {
            throw new ProvisioningFailedException('configure', $result->reference);
        }

        $this->progress->record('configure');

        // The distribution advises securing the setup script; there is nothing to secure it with.
        $this->run('harden', ['rm', '-rf', "{$documentRoot}/setup"], $application);
    }

    // ---- One-click update (ApplicationUpdater) ---------------------------

    public function installedVersion(Application $application): ?string
    {
        $json = json_decode((string) $this->readSiteFile($application, $application->documentRoot().'/package.json'), true);
        $version = is_array($json) ? ($json['version'] ?? null) : null;

        return is_string($version) && $this->isStable($version) ? $this->normalizeVersion($version) : null;
    }

    public function availableVersions(Application $application, string $installed): array
    {
        $json = $this->getJson((string) config('server.installers.phpmyadmin.version_url', 'https://www.phpmyadmin.net/home_page/version.json'));
        $versions = collect($json['releases'] ?? [])
            ->pluck('version')
            ->filter(fn ($version): bool => is_string($version) && $this->isStable($version))
            ->values()
            ->all();

        return ['latest' => $this->highestInLine($versions, $installed), 'newest' => $this->highest($versions)];
    }

    /** The target release over the old files; `config.inc.php` survives (package ships a sample). */
    public function update(Application $application, string $from, string $to): void
    {
        $url = str_replace('{version}', $to, (string) config(
            'server.installers.phpmyadmin.release_url',
            'https://files.phpmyadmin.net/phpMyAdmin/{version}/phpMyAdmin-{version}-all-languages.tar.gz',
        ));

        $this->downloadAndExtract($application, $url, $application->documentRoot());
    }
}
