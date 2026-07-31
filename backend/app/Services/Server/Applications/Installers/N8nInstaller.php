<?php

namespace App\Services\Server\Applications\Installers;

use App\Models\Application;
use Illuminate\Support\Str;

/**
 * n8n — workflow automation.
 *
 * Installed into the site rather than globally, for the same reason as
 * Node-RED: one version per site, owned by the user whose process runs it.
 *
 * Everything n8n needs is configured through the environment, so this writes
 * the site's `.env` — which the unit already loads — rather than a config
 * file. Two entries there matter more than the rest:
 *
 *  - **`N8N_ENCRYPTION_KEY` is generated once and never changes.** It encrypts
 *    every stored credential. Losing or changing it does not lock you out of
 *    n8n; it makes every credential in it permanently unreadable, with no
 *    error until a workflow runs. It is generated here, before first start,
 *    because a key n8n generates itself lands in a data directory that a
 *    reinstall would replace.
 *  - **`N8N_USER_FOLDER` points into the site.** n8n defaults to `~/.n8n`,
 *    and the unit mounts the home read-only.
 *
 * Its data lives in SQLite under that folder, so it needs no database.
 *
 * There is no admin account to create: n8n's owner account is set up by the
 * first person to open the site.
 *
 * **Licensing:** n8n is fair-code under the Sustainable Use License, not open
 * source. Self-hosting for your own use is exactly what it permits — but it is
 * not OSI-licensed, and that is worth knowing before it goes in a catalog next
 * to GPL software.
 */
class N8nInstaller extends AbstractNodeInstaller
{
    public function siteType(): string
    {
        return 'n8n';
    }

    public function startCommand(Application $application, string $documentRoot): ?string
    {
        return "node {$documentRoot}/node_modules/n8n/bin/n8n start";
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function install(Application $application, string $documentRoot, array $context): void
    {
        $this->runWithNode('install_app', $application, [
            'npm', 'install', '--omit=dev', '--no-audit', '--no-fund',
            'n8n@'.config('server.installers.n8n.version', 'latest'),
        ], $documentRoot);

        $this->writeSecretFile($application, "{$documentRoot}/.env", $this->environment($application, $documentRoot));
    }

    private function environment(Application $application, string $documentRoot): string
    {
        $domain = (string) $application->domain;

        $lines = [
            // Generated before first start — see the class note. 32 bytes of
            // randomness, hex, which is what n8n's own docs suggest.
            'N8N_ENCRYPTION_KEY' => bin2hex(random_bytes(32)),
            'N8N_USER_FOLDER' => $documentRoot,
            'N8N_PORT' => (string) ($application->app_port ?: 5678),
            // Loopback only: the site is reached through the panel's reverse
            // proxy, and binding wider would publish it on the server's own
            // address too, past whatever the vhost does.
            'N8N_LISTEN_ADDRESS' => '127.0.0.1',
            'N8N_HOST' => $domain,
            // The proxy terminates TLS, so n8n has to be told what the browser
            // sees — otherwise every webhook URL it prints and every OAuth
            // callback it builds says http.
            'N8N_PROTOCOL' => 'https',
            'WEBHOOK_URL' => "https://{$domain}/",
            'N8N_PROXY_HOPS' => '1',
            'GENERIC_TIMEZONE' => (string) config('app.timezone', 'UTC'),
            'N8N_DIAGNOSTICS_ENABLED' => 'false',
        ];

        $contents = "# Managed by the panel. N8N_ENCRYPTION_KEY must never change.\n";

        foreach ($lines as $key => $value) {
            // systemd reads this file itself, and its parser is not a shell:
            // quoting is what keeps a value with a space in it one value.
            $contents .= $key.'="'.Str::replace('"', '\"', $value)."\"\n";
        }

        return $contents;
    }
}
