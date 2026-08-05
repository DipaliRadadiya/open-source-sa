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
 * The credential file lives at `{documentRoot}/.panel/.htpasswd`, the same
 * `.panel/` directory already used for PHP-isolation sessions and file-editor
 * backups — already outside every vhost's served paths (the dotfile deny
 * rule every template carries), so nothing new has to keep it hidden.
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
    ) {}

    public function credentialsPath(Application $application): string
    {
        return $this->provisioner->documentRoot($application).'/.panel/.htpasswd';
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

    private function writeCredentialsFile(Application $application, string $username, string $hash): void
    {
        $path = $this->credentialsPath($application);
        $user = $application->systemUser?->username;

        $this->serverOps->run(
            ['mkdir', '-p', dirname($path)],
            $this->context($application, 'basic_auth_mkdir'),
        );

        $written = $this->files->put(
            $path,
            "{$username}:{$hash}\n",
            $this->context($application, 'basic_auth_write'),
        );

        if ($written->failed()) {
            throw new BasicAuthOperationException($written->reference);
        }

        // Readable by the web server, not locked to the site user — nginx and
        // Apache read this at request time as their own worker user, not as
        // the site's isolated user (only the FPM socket is group-shared, per
        // the Fix Permissions research). A 0600 file here would report
        // "enabled" and then answer every request with a 500.
        if ($user !== null) {
            $this->serverOps->run(
                ['chown', "{$user}:{$user}", $path],
                $this->context($application, 'basic_auth_chown'),
            );
        }

        $this->serverOps->run(
            ['chmod', '0644', $path],
            $this->context($application, 'basic_auth_chmod'),
        );
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
