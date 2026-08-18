<?php

namespace App\Services\Server\Applications\Installers;

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Services\Server\Applications\PhpMyAdminSso;

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
class PhpMyAdminInstaller extends AbstractPhpInstaller
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
    public function install(Application $application, string $documentRoot, array $context): void
    {

        $this->downloadAndExtract(
            $application,
            (string) config('server.installers.phpmyadmin.download_url'),
            $documentRoot,
        );

        // Kept beside the site rather than inside it: phpMyAdmin writes
        // uploads and exports here, and anything under the document root is
        // a URL somebody can fetch.
        //
        // Anchored on the app's own {home}/{slug} directory directly, not
        // dirname($documentRoot) — a git site nests its document root under
        // current/, so dirname() lands on {home}/{slug} correctly, but a
        // flat (non-git) site with an empty web_root, like this one, has
        // its document root AT {home}/{slug}, and dirname() would escape
        // one level too far into the site owner's shared home directory.
        // Slug is skipped rather than interpolated blank for a row that
        // predates the slug column — an empty path segment would still
        // resolve on disk (a//b === a/b to the filesystem) but would no
        // longer be the exact string anything comparing paths expects.
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

        // The panel's one-click sign-in needs a script on this site to write
        // the session phpMyAdmin reads. Without it the button redirects to a
        // file that does not exist, which is what it did for as long as the
        // feature has shipped.
        $result = $sso->installShim($application);

        if ($result->failed()) {
            throw new ProvisioningFailedException('configure', $result->reference);
        }

        $this->progress->record('configure');

        // The distribution's own advice is to secure the setup script. There
        // is nothing to secure it with here, so it goes.
        $this->run('harden', ['rm', '-rf', "{$documentRoot}/setup"], $application);
    }
}
