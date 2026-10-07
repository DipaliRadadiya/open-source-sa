<?php

namespace App\Services\Server\Applications;

use App\Exceptions\Server\Application\BasicAuthOperationException;
use App\Models\Application;
use App\Services\Server\ManagedFile;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use App\Services\Server\WebServers\WebServerManager;
use Illuminate\Support\Facades\Hash;

/**
 * Whole-site HTTP Basic Auth — one username, one password per application,
 * a single shared credential rather than a table of named users.
 *
 * The credential file lives where v7 keeps it (v7 layout B4):
 * `{appRoot}/conf/{web server}/.htpasswd`, above the served directory. It
 * has to be 0644 so the web server's worker can read it at request time, so
 * keeping it out of the document root is what actually makes it unreachable
 * over HTTP. See `Application::basicAuthPath()`.
 *
 * Enable/disable/change-credential all funnel through the same apply-then-
 * test-then-reload sequence `ApplicationProvisioner::disable()`/`enable()`
 * use, with the same rollback shape: a failed config test restores the
 * previous state before failing, so a bad toggle never leaves the vhost
 * pointed at a broken config file on the next restart.
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
     * Where the file used to live, before it moved above the webroot.
     *
     * Sites protected before that change still have a world-readable copy
     * sitting in their document root; writing the new one removes it.
     */
    /**
     * Where the file used to live: inside the document root, then in the
     * site's `.panel/`. Each is removed once the current one is in place.
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
            // Put the previous state back before failing — the same reason
            // disable()/enable() roll back: a config test failure must never
            // leave the vhost pointed at a block referencing a credential
            // that was never actually saved.
            $application->basic_auth_enabled = $wasEnabled;
            $application->basic_auth_username = $previousUsername;

            $restored = $this->applyVhost($application);

            throw new BasicAuthOperationException($restored->reference);
        }

        $this->webServers->driver()->reload();

        $application->basic_auth_password = $hash;
        $application->save();
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

        $this->webServers->driver()->reload();

        $application->save();

        // Removed only once the vhost no longer references it — nothing
        // should be able to read a stale credential once protection is off,
        // but the file must outlive the block that points at it, not the
        // other way round.
        $this->serverOps->run(
            ['rm', '-f', $this->credentialsPath($application)],
            $this->context($application, 'basic_auth_remove_credentials'),
        );
    }

    /**
     * Write the credential file at the application's *current* document root,
     * reusing the stored hash rather than asking for the password again.
     *
     * Needed when the document root moves under a protected site: the file is
     * addressed by document root, so a moved root would leave the vhost
     * pointing at a credential file that is not there. That fails closed
     * rather than open, but the site is still down — and a webroot change
     * silently switching protection off would be worse.
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
     * `{site}/conf` and `{site}/conf/{web server}`, root's and real
     * directories, before root writes into them.
     *
     * On a server that comes from v7 both belong to the site user, who could
     * swap either for a link: root's `tee` would then write the credential —
     * and the `chown` after it hand ownership of — whatever the link points
     * at. A link found here is removed, not followed, and both become
     * root:root 0755: the web server still reads the file, the user can no
     * longer replace what it sits in.
     */
    private function secureDirectories(Application $application, string $directory): void
    {
        $conf = dirname($directory);

        foreach ([$conf, $directory] as $path) {
            $link = $this->serverOps->probe(['test', '-L', $path], $this->context($application, 'basic_auth_link_check'));

            if ($link->ok) {
                $this->serverOps->run(['rm', '-f', $path], $this->context($application, 'basic_auth_unlink'));
            }

            // `conf` is an entry of the site root, which is immutable once
            // locked ({@see SiteRootLock}).
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

        // Readable by the web server — nginx and Apache read this at request
        // time as their own worker user, not as the site's isolated user. A
        // 0600 file here would report "enabled" and then answer every request
        // with a 500. Root's, as v7 leaves it: the site user has no business
        // rewriting the password that protects their own site.
        $this->serverOps->run(
            ['chown', '-h', 'root:root', $path],
            $this->context($application, 'basic_auth_chown'),
        );

        $this->serverOps->run(
            ['chmod', '0644', $path],
            $this->context($application, 'basic_auth_chmod'),
        );

        // Only after the new one is in place: the old file must outlive the
        // config still pointing at it, never the other way round.
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
