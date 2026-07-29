<?php

namespace App\Services\Server\Applications\Installers;

use App\Models\Application;
use Illuminate\Support\Facades\View;

/**
 * phpMyAdmin — a web client for the server's databases.
 *
 * Unlike the other marketplace apps, this one is not a site with its own data:
 * it is a tool pointed at databases that already exist. So it needs no
 * database of its own, and it must not be given credentials of its own either
 * — it authenticates as whoever logs in.
 *
 * Two things the upstream tarball leaves to whoever unpacks it, both of which
 * matter on a public URL:
 *
 *  - **`blowfish_secret`** encrypts the cookie that carries a user's database
 *    credentials between requests. Ship a fixed one and anybody holding that
 *    value can decrypt any session on any install that shares it, so it is
 *    generated per installation.
 *  - **`setup/`** is a web wizard that writes configuration. It is fine inside
 *    a distribution package, which secures it; on a tarball unpacked into a
 *    public web root it is a configuration console on the open internet. It
 *    is removed.
 */
class PhpMyAdminInstaller extends AbstractSiteInstaller
{
    public function siteType(): string
    {
        return 'phpmyadmin';
    }

    /**
     * phpMyAdmin reads the databases that are already on the server, using the
     * credentials of whoever logs in. Creating one for it would leave an empty
     * database behind and give the tool a standing account it has no use for.
     */
    public function needsDatabase(): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function install(Application $application, string $documentRoot, array $context): array
    {
        $steps = [];

        $this->downloadAndExtract(
            $application,
            (string) config('server.installers.phpmyadmin.download_url'),
            $documentRoot,
        );
        $steps[] = 'download';
        $steps[] = 'extract';

        // Kept beside the site rather than inside it: phpMyAdmin writes
        // uploads and exports here, and anything under the document root is
        // a URL somebody can fetch.
        $tempDir = dirname($documentRoot).'/tmp';
        $this->run('configure', ['mkdir', '-p', $tempDir], $application);
        $this->run('configure', [
            'chown', "{$application->systemUser->username}:{$application->systemUser->username}", $tempDir,
        ], $application);
        $this->run('configure', ['chmod', '0750', $tempDir], $application);

        $this->writeSecretFile($application, "{$documentRoot}/config.inc.php", View::make('server.apps.phpmyadmin.config', [
            'blowfishSecret' => bin2hex(random_bytes(32)),
            'host' => (string) config('server.installers.phpmyadmin.db_host', '127.0.0.1'),
            'tempDir' => $tempDir,
        ])->render());
        $steps[] = 'configure';

        // The distribution's own advice is to secure the setup script. There
        // is nothing to secure it with here, so it goes.
        $this->run('harden', ['rm', '-rf', "{$documentRoot}/setup"], $application);
        $steps[] = 'harden';

        return $steps;
    }
}
