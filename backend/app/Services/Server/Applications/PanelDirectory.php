<?php

namespace App\Services\Server\Applications;

use App\Models\Application;
use App\Services\Server\ServerOps;
use Illuminate\Support\Facades\Log;

/**
 * Creates a directory inside an application's `.panel` and hands it to the site user.
 *
 * `.panel` itself is root's (created through ServerOps) and provisioning's `chown -R` only
 * descends the document root, so the site user has no foothold in it: a plain
 * `runuser -u <site> -- mkdir` there gets permission denied.
 *
 * Only the named subdirectory changes hands, never `.panel` itself. Write permission on a
 * directory allows unlinking its files, and `.panel` holds the Basic Auth credential: a system
 * user with SSH access could otherwise delete the `.htpasswd` protecting its own site.
 */
class PanelDirectory
{
    /**
     * The subdirectories of `.panel` that root writes into. Everything else in there is handed
     * to the user by {@see ensure()} (`sessions`, `trash`, `file-backups`, upload staging, `sso`).
     */
    public const ROOT_OWNED = ['php', 'staging-backups', 'staging-rollbacks'];

    public function __construct(private ServerOps $serverOps) {}

    /**
     * Ensure `{panelPath}/{name}` exists and is owned by the site user.
     *
     * Idempotent: the chown re-asserts ownership of a directory that already exists.
     *
     * One level only, not the nested path a caller may write to: the site user owns the top of
     * its tree and creates deeper levels itself.
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
            // `-h`: never follow a link, even one planted before secure() ran.
            ['chown', '-h', "{$user}:{$user}", $directory],
            $this->context($application, 'panel_dir_chown', $directory),
            timeout: 15,
        );
    }

    /**
     * Put `.panel` back in root's hands, and take out anything planted in it.
     *
     * If the site user owns `.panel`, root writes into a directory the user controls: a symlink
     * planted there redirects root's writes (Basic Auth could write a hash into, and chown to
     * the user, a file like `/etc/passwd`; Fix permissions could `chmod -R` the link target).
     *
     * Called wherever the directory is ensured, so `sites:resync` repairs existing sites. Every
     * step is `find … -type`-guarded or `-h`, so none follows a link: the paths inside are not
     * trusted yet. Best-effort: a failed step is logged by ServerOps.
     */
    public function secure(Application $application): void
    {
        $panel = $application->panelPath();

        // `-type d` under find's default -P never matches a link, so a linked `.panel` is skipped.
        $this->serverOps->run(
            ['find', $panel, '-maxdepth', '0', '-type', 'd', '-exec', 'chown', '-h', 'root:root', '{}', ';', '-exec', 'chmod', '0755', '{}', ';'],
            $this->context($application, 'panel_secure', $panel),
            timeout: 15,
        );

        // The panel never makes a link at the top of `.panel` or inside a root-written directory:
        // remove it and log it. Two finds, as -mindepth/-maxdepth are global to one command.
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

        // What root writes (the Basic Auth file, PHP ini and staging directories) goes back to
        // root, so the user cannot swap its contents.
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
