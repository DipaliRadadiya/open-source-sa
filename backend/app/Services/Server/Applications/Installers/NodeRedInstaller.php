<?php

namespace App\Services\Server\Applications\Installers;

use App\Contracts\ApplicationUpdater;
use App\Models\Application;
use App\Services\Server\Applications\Installers\Concerns\ResolvesUpdates;
use App\Services\Server\Applications\Installers\Concerns\UpdatesNpmApp;

/**
 * Node-RED — flow-based automation.
 *
 * Installed into the site, not globally: a global install is one version shared by every site
 * and owned by root rather than the site user.
 *
 * Two things this writes that upstream leaves to the user (the site sits on a public domain):
 *
 *  - **A password on the editor.** Node-RED has no authentication by default, and a flow can run
 *    arbitrary code as the site user, so an open editor is a remote shell. The panel refuses that.
 *  - **A user directory inside the site.** The default `~/.node-red` is read-only under the unit's
 *    `ProtectHome=read-only`, so flows could not be saved.
 */
class NodeRedInstaller extends AbstractNodeInstaller implements ApplicationUpdater
{
    use ResolvesUpdates;
    use UpdatesNpmApp;

    protected function npmPackage(): string
    {
        return 'node-red';
    }

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
        $settings = $application->installSettings();

        // --omit=dev: the editor ships built; the dev tree is hundreds of MB nothing runs.
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
     * `uiPort` reads `PORT` (set by the unit, targeted by the reverse proxy); the literal is only
     * a fallback for running it by hand.
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
            // Every visitor arrives from that proxy, so without this the
            // editor's audit log and any flow reading req.ip see 127.0.0.1
            // (bug #66). Loopback only: a client cannot pass its own
            // X-Forwarded-For through, the proxy appends the real address.
            httpServerOptions: { 'trust proxy': 'loopback' },
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
