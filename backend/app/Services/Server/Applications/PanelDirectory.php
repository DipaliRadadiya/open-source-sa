<?php

namespace App\Services\Server\Applications;

use App\Models\Application;
use App\Services\Server\ServerOps;

/**
 * Creates a directory inside an application's `.panel` and hands it to the
 * site user.
 *
 * `.panel` itself is root's. `AbstractWebServerDriver::ensurePanelDirectory()`
 * creates it through ServerOps, which elevates, and provisioning's
 * `chown -R` only ever descends the *document root* — so nothing gives the
 * site user a foothold in there. Every caller that then ran
 * `runuser -u <site> -- mkdir -p {panelPath}/<something>` was writing into a
 * root-owned 0755 directory and getting permission denied.
 *
 * That was invisible for as long as it existed, because `Process` is faked in
 * the suite: the tests asserted the command's shape, and a fake never returns
 * EACCES. File backups and the trash both failed this way on real servers
 * while their tests passed.
 *
 * Only the named subdirectory changes hands, never `.panel` itself. Write
 * permission on a directory is what allows unlinking the files inside it, and
 * `.panel` holds the Basic Auth credential — a system user with SSH access
 * could otherwise delete the `.htpasswd` protecting its own site and remove a
 * restriction an administrator put there.
 */
class PanelDirectory
{
    public function __construct(private ServerOps $serverOps) {}

    /**
     * Ensure `{panelPath}/{name}` exists and is owned by the site user.
     *
     * Idempotent: `mkdir -p` on an existing directory succeeds, and the chown
     * re-asserts ownership for a directory created before this existed, which
     * is how a server upgraded into this fix repairs itself without anybody
     * running anything.
     *
     * Deliberately not the *nested* path a caller may be about to write to —
     * one level, so the site user owns the top of its own tree and can then
     * create whatever depth it needs as itself.
     */
    public function ensure(Application $application, string $name): void
    {
        $directory = $application->panelPath().'/'.trim($name, '/');
        $user = $application->systemUser->username;

        $this->serverOps->run(
            ['mkdir', '-p', $directory],
            $this->context($application, 'panel_dir', $directory),
            timeout: 15,
        );

        $this->serverOps->run(
            ['chown', "{$user}:{$user}", $directory],
            $this->context($application, 'panel_dir_chown', $directory),
            timeout: 15,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function context(Application $application, string $op, string $directory): array
    {
        return [
            'feature' => 'application',
            'op' => $op,
            'application' => $application->id,
            'path' => $directory,
        ];
    }
}
