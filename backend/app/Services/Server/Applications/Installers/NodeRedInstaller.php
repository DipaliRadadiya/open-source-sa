<?php

namespace App\Services\Server\Applications\Installers;

use App\Models\Application;

/**
 * Node-RED — flow-based automation.
 *
 * Installed **into the site**, not globally. Upstream's instructions say
 * `npm install -g node-red`, which is right for one machine running one
 * Node-RED and wrong here: a global install is one version shared by every
 * site on the box, upgraded for all of them at once, and owned by root rather
 * than by the site user whose process runs it.
 *
 * Two things this writes that upstream leaves to the user, because the panel
 * puts the site on a public domain and upstream assumes a home network:
 *
 *  - **A password on the editor.** Node-RED ships with no authentication at
 *    all. Anyone who reaches the URL can edit flows, and a flow can run
 *    arbitrary code as the site user — so an unauthenticated Node-RED on a
 *    public domain is a remote shell. The panel refuses to create that.
 *  - **A user directory inside the site.** Node-RED defaults to `~/.node-red`,
 *    which the unit mounts read-only (`ProtectHome=read-only`), so the default
 *    would start and then fail to save a flow.
 */
class NodeRedInstaller extends AbstractNodeInstaller
{
    public function siteType(): string
    {
        return 'nodered';
    }

    public function startCommand(Application $application, string $documentRoot): ?string
    {
        return implode(' ', [
            'node', "{$documentRoot}/node_modules/node-red/red.js",
            '--userDir', $documentRoot,
            '--settings', "{$documentRoot}/settings.js",
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function install(Application $application, string $documentRoot, array $context): void
    {
        $settings = $application->settings ?? [];

        // --omit=dev: the editor is shipped built, so the dev tree is a few
        // hundred megabytes of nothing this will ever run.
        $this->runWithNode('install_app', $application, [
            'npm', 'install', '--omit=dev', '--no-audit', '--no-fund',
            'node-red@'.config('server.installers.nodered.version', 'latest'),
        ], $documentRoot);

        $this->writeSecretFile(
            $application,
            "{$documentRoot}/settings.js",
            $this->settings(
                (string) ($settings['admin_username'] ?? 'admin'),
                (string) ($settings['admin_password'] ?? ''),
            ),
        );
    }

    /**
     * The settings module.
     *
     * `uiPort` reads `PORT` because that is what the unit sets and what the
     * reverse proxy was pointed at; the literal is only the fallback for
     * someone running this by hand.
     */
    private function settings(string $username, string $password): string
    {
        $user = json_encode($username, JSON_THROW_ON_ERROR);
        $hash = json_encode($this->bcrypt($password), JSON_THROW_ON_ERROR);

        return <<<JS
        // Managed by the panel. Edits survive, but a reinstall overwrites this.
        module.exports = {
            uiPort: process.env.PORT || 1880,
            // Behind the panel's reverse proxy, so binding anywhere else would
            // publish the editor on the server's own address as well.
            uiHost: '127.0.0.1',
            flowFile: 'flows.json',
            adminAuth: {
                type: 'credentials',
                users: [{ username: {$user}, password: {$hash}, permissions: '*' }],
            },
            functionGlobalContext: {},
            logging: {
                console: { level: 'info', metrics: false, audit: false },
            },
        };

        JS;
    }
}
