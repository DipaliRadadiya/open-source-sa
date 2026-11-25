<?php

namespace App\Services\Server\Applications;

use App\Exceptions\Server\Application\BasicAuthOperationException;
use App\Exceptions\Server\WebServer\WebServerReloadFailedException;
use App\Models\Application;
use App\Services\Server\ManagedFile;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use App\Services\Server\WebServers\WebServerManager;
use Illuminate\Support\Facades\Hash;

/**
 * Whole-site HTTP Basic Auth: one shared username and password per application.
 *
 * The credential file lives where v7 keeps it, `{appRoot}/conf/{web server}/.htpasswd`, above the
 * served directory. It must be 0644 so the web server's worker can read it, so keeping it out of
 * the document root is what makes it unreachable over HTTP. See `Application::basicAuthPath()`.
 *
 * Enable, disable and change-credential use the same apply, test, reload sequence as
 * `ApplicationProvisioner::disable()`/`enable()`: a failed config test restores the previous state.
 */
class BasicAuthManager
{
    public function __construct(
        private WebServerManager $webServers,
        private ApplicationProvisioner $provisioner,
        private ManagedFile $files,
        private ServerOps $serverOps,
        private SiteRootLock $rootLock,
    ) {}

    public function credentialsPath(Application $application): string
    {
        return $application->basicAuthPath();
    }

    /**
     * Where the file used to live: inside the document root, then in the site's `.panel/`. Each is
     * removed once the current one is in place.
     *
     * @return list<string>
     */
    private function legacyCredentialsPaths(Application $application): array
    {
        return [
            $this->provisioner->documentRoot($application).'/.panel/.htpasswd',
            $application->panelPath().'/.htpasswd',
        ];
    }

    /**
     * Turn protection on (or change the credential on a site already
     * protected) — one action either way, one save button.
     */
    public function protect(Application $application, string $username, string $password): void
    {
        $hash = Hash::make($password);

        $this->writeCredentialsFile($application, $username, $hash);

        $wasEnabled = $application->basic_auth_enabled;
        $previousUsername = $application->basic_auth_username;

        $application->basic_auth_enabled = true;
        $application->basic_auth_username = $username;

        $applied = $this->applyVhost($application);

        if ($applied->failed()) {
            throw new BasicAuthOperationException($applied->reference);
        }

        if ($this->webServers->driver()->test()->failed()) {
            // Roll back, as disable()/enable() do: never leave the vhost referencing a credential
            // that was not saved.
            $application->basic_auth_enabled = $wasEnabled;
            $application->basic_auth_username = $previousUsername;

            $restored = $this->applyVhost($application);

            throw new BasicAuthOperationException($restored->reference);
        }

        $reload = $this->webServers->driver()->reload();

        // Saved either way: the tested config is on disk and the row must match it.
        $application->basic_auth_password = $hash;
        $application->save();

        if ($reload->failed()) {
            throw new WebServerReloadFailedException($reload->reference);
        }
    }

    public function unprotect(Application $application): void
    {
        $application->basic_auth_enabled = false;

        $applied = $this->applyVhost($application);

        if ($applied->failed()) {
            throw new BasicAuthOperationException($applied->reference);
        }

        if ($this->webServers->driver()->test()->failed()) {
            $application->basic_auth_enabled = true;

            $restored = $this->applyVhost($application);

            throw new BasicAuthOperationException($restored->reference);
        }

        $reload = $this->webServers->driver()->reload();

        $application->save();

        // After a failed reload the web server still uses the old config, so the file stays.
        if ($reload->failed()) {
            throw new WebServerReloadFailedException($reload->reference);
        }

        // Removed only once the vhost no longer references it: the file must outlive the block
        // that points at it.
        $this->serverOps->run(
            ['rm', '-f', $this->credentialsPath($application)],
            $this->context($application, 'basic_auth_remove_credentials'),
        );
    }

    /**
     * Write the credential file at the application's current document root, reusing the stored
     * hash. Needed when the document root moves under a protected site, or the vhost would point
     * at a credential file that is not there (the site would be down, not open).
     */
    public function publish(Application $application): void
    {
        if (! $application->basic_auth_enabled) {
            return;
        }

        $username = (string) $application->basic_auth_username;
        $hash = (string) $application->basic_auth_password;

        if ($username === '' || $hash === '') {
            return;
        }

        $this->writeCredentialsFile($application, $username, $hash);
    }

    /**
     * Make `{site}/conf` and `{site}/conf/{web server}` real root-owned directories before root
     * writes into them.
     *
     * On a server upgraded from v7 both belong to the site user, who could swap either for a
     * symlink so root's `tee` and `chown` would hit whatever it points at. A link found here is
     * removed, not followed, and both become root:root 0755.
     */
    private function secureDirectories(Application $application, string $directory): void
    {
        $conf = dirname($directory);

        foreach ([$conf, $directory] as $path) {
            $link = $this->serverOps->probe(['test', '-L', $path], $this->context($application, 'basic_auth_link_check'));

            if ($link->ok) {
                $this->serverOps->run(['rm', '-f', $path], $this->context($application, 'basic_auth_unlink'));
            }

            // `conf` is an entry of the site root, which is immutable once locked ({@see SiteRootLock}).
            $made = $path === $conf
                ? $this->rootLock->ensureDirectory($application, $path, $this->context($application, 'basic_auth_mkdir'))
                : $this->serverOps->run(['mkdir', '-p', $path], $this->context($application, 'basic_auth_mkdir'));

            if ($made->failed()) {
                throw new BasicAuthOperationException($made->reference);
            }

            $this->serverOps->run(['chown', '-h', 'root:root', $path], $this->context($application, 'basic_auth_dir_chown'));
            $this->serverOps->run(['chmod', '0755', $path], $this->context($application, 'basic_auth_dir_chmod'));
        }
    }

    private function writeCredentialsFile(Application $application, string $username, string $hash): void
    {
        $path = $this->credentialsPath($application);

        $this->secureDirectories($application, dirname($path));

        $written = $this->files->put(
            $path,
            "{$username}:{$hash}\n",
            $this->context($application, 'basic_auth_write'),
        );

        if ($written->failed()) {
            throw new BasicAuthOperationException($written->reference);
        }

        // 0644: nginx and Apache read it as their own worker user, so 0600 would answer every
        // request with a 500. Root-owned, so the site user cannot rewrite its own password.
        $this->serverOps->run(
            ['chown', '-h', 'root:root', $path],
            $this->context($application, 'basic_auth_chown'),
        );

        $this->serverOps->run(
            ['chmod', '0644', $path],
            $this->context($application, 'basic_auth_chmod'),
        );

        // Only after the new file is in place: the old one must outlive any config pointing at it.
        foreach ($this->legacyCredentialsPaths($application) as $legacy) {
            if ($legacy !== $path) {
                $this->serverOps->run(
                    ['rm', '-f', $legacy],
                    $this->context($application, 'basic_auth_remove_legacy'),
                );
            }
        }
    }

    private function applyVhost(Application $application): ServerOpsResult
    {
        return $this->webServers->driver()->apply($application, $this->provisioner->documentRoot($application));
    }

    /**
     * @return array<string, mixed>
     */
    private function context(Application $application, string $op): array
    {
        return ['feature' => 'application', 'op' => $op, 'application' => $application->id];
    }
}
