<?php

namespace App\Services\Server\Applications;

use App\Models\Application;
use App\Services\Server\ServerOps;
use Illuminate\Support\Facades\Log;

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
    /**
     * The subdirectories of `.panel` that root writes into. Everything else
     * in there is either handed to the user by {@see ensure()} (`sessions`,
     * `trash`, `file-backups`, the upload staging directory, `sso`) or does
     * not exist.
     */
    public const ROOT_OWNED = ['php', 'staging-backups', 'staging-rollbacks'];

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
            // `-h`: `.panel` is root's, so nothing in it should be a link —
            // but this runs on servers where it was not, before secure() has.
            ['chown', '-h', "{$user}:{$user}", $directory],
            $this->context($application, 'panel_dir_chown', $directory),
            timeout: 15,
        );
    }

    /**
     * Put `.panel` back in root's hands, and take out anything planted in it.
     *
     * The docblock above was true of this class and false of the servers: on
     * every PHP site on nginx and Apache `.panel` was owned by the *site
     * user*, because PoolManager handed it over with `chown -R` on the parent
     * of the sessions directory (found live, 2026-09-29). Root then wrote into
     * a directory the user controlled, and a symlink put there by the user —
     * or by any plugin running as it — redirected those writes: enabling Basic
     * Auth wrote the hash into a root-owned file of the user's choosing *and
     * chowned it to them* (`/etc/passwd` would be a root shell), and Fix
     * permissions ran `chmod -R 0700` down whatever `sessions` pointed at.
     *
     * Called wherever the directory is ensured, so `sites:resync` — which runs
     * on every deploy and self-update — repairs existing sites without anyone
     * doing anything. Every step is `find … -type`-guarded or `-h`, so none of
     * them follows a link; the point of the method is that the paths inside
     * are not trusted yet.
     *
     * Best-effort like the rest of directory preparation: a step that fails is
     * logged by ServerOps and reported by whatever needed it.
     */
    public function secure(Application $application): void
    {
        $panel = $application->panelPath();

        // Not the directory's own entry if it is somehow a link: -type d under
        // find's default -P never matches one.
        $this->serverOps->run(
            ['find', $panel, '-maxdepth', '0', '-type', 'd', '-exec', 'chown', '-h', 'root:root', '{}', ';', '-exec', 'chmod', '0755', '{}', ';'],
            $this->context($application, 'panel_secure', $panel),
            timeout: 15,
        );

        // A link at the top of `.panel`, or directly inside one of the
        // directories root writes into, has no legitimate reason to exist —
        // the panel never makes one. Removed rather than followed, and named
        // in the log. Two finds, not one: -mindepth/-maxdepth are global, so
        // one command cannot ask about two depths.
        $planted = $this->serverOps->run(
            ['find', $panel, '-mindepth', '1', '-maxdepth', '1', '-type', 'l', '-print', '-delete'],
            $this->context($application, 'panel_unlink', $panel),
            timeout: 15,
        );

        $nested = $this->serverOps->run(
            array_merge(
                ['find', $panel, '-mindepth', '2', '-maxdepth', '2', '-type', 'l', '('],
                $this->rootOwnedPatterns($panel),
                [')', '-print', '-delete'],
            ),
            $this->context($application, 'panel_unlink_nested', $panel),
            timeout: 15,
        );

        $removed = trim(($planted->ok ? $planted->output() : '')."\n".($nested->ok ? $nested->output() : ''));

        if ($removed !== '') {
            Log::channel('server-ops')->warning('symlinks removed from a site\'s .panel directory', [
                'feature' => 'application',
                'op' => 'panel_unlink',
                'application' => $application->id,
                'paths' => array_values(array_filter(explode("\n", $removed))),
            ]);
        }

        // What root writes itself — the Basic Auth file, and the directories
        // holding the PHP ini and staging dumps — goes back to root, so the
        // user cannot swap their contents while owning them.
        $this->serverOps->run(
            array_merge(
                ['find', $panel, '-mindepth', '1', '-maxdepth', '1', '('],
                ['(', '-name', '.htpasswd', '-type', 'f', ')', '-o'],
                ['(', '-type', 'd', '('],
                $this->rootOwnedNames(),
                [')', ')', ')', '-exec', 'chown', '-h', 'root:root', '{}', '+'],
            ),
            $this->context($application, 'panel_reclaim', $panel),
            timeout: 15,
        );
    }

    /**
     * `-name a -o -name b …` for find.
     *
     * @return array<int, string>
     */
    private function rootOwnedNames(): array
    {
        $args = [];

        foreach (self::ROOT_OWNED as $i => $name) {
            if ($i > 0) {
                $args[] = '-o';
            }

            array_push($args, '-name', $name);
        }

        return $args;
    }

    /**
     * `-path {panel}/a/* -o -path {panel}/b/* …` for find.
     *
     * @return array<int, string>
     */
    private function rootOwnedPatterns(string $panel): array
    {
        $args = [];

        foreach (self::ROOT_OWNED as $i => $name) {
            if ($i > 0) {
                $args[] = '-o';
            }

            array_push($args, '-path', "{$panel}/{$name}/*");
        }

        return $args;
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
