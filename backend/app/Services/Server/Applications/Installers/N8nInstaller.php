<?php

namespace App\Services\Server\Applications\Installers;

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use Illuminate\Support\Sleep;
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
 * **The owner account is created by the panel**, in `afterStart()`. n8n has
 * no command for it: left alone, its owner is whoever opens the site first,
 * and a fresh install is on a public URL from the moment it starts.
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

    /**
     * Claim the instance: create its owner through n8n's own setup endpoint,
     * from the server, before the site is reported ready.
     *
     * The same request n8n's first-run page sends, to 127.0.0.1 — n8n
     * listens there only. Once an owner exists the endpoint refuses (400),
     * which is what closes the page to a stranger. The password travels on
     * stdin, never on the command line.
     *
     * **A 200 proves nothing here.** While it starts, n8n answers every path —
     * its API included — with `200` and a "n8n is starting up" page. The
     * readiness check passed on that page, and the setup request got the same
     * page and a 200: the panel reported the owner created and the site went
     * live unclaimed (measured on a real server). So this waits for the real
     * settings JSON first, and afterwards reads the settings again and
     * requires the first-run page to be closed. Nothing else counts as done.
     *
     * Skipped when n8n already has an owner, so Retry Setup does not fail on
     * an instance a previous attempt already claimed.
     */
    public function afterStart(Application $application, string $documentRoot): void
    {
        $base = 'http://127.0.0.1:'.((int) ($application->app_port ?: 5678));
        $settings = $application->installSettings();

        if (! $this->waitForSetupState($application, $base)) {
            return;
        }

        $this->run('create_admin', [
            'curl', '-sS', '--fail-with-body', '--max-time', '30',
            '-H', 'Content-Type: application/json',
            '--data-binary', '@-',
            $base.'/rest/owner/setup',
        ], $application, json_encode([
            'email' => (string) ($settings['admin_email'] ?? ''),
            'firstName' => 'Admin',
            'lastName' => 'Owner',
            'password' => (string) ($settings['admin_password'] ?? ''),
        ], JSON_THROW_ON_ERROR));

        if ($this->waitForSetupState($application, $base)) {
            throw new ProvisioningFailedException('create_admin', (string) Str::uuid(), 'owner_not_created');
        }
    }

    /**
     * Whether n8n's first-run page is still open, once n8n can say.
     *
     * Polls until `/rest/settings` returns the real settings — not the
     * starting-up page — for up to `server.installers.n8n.ready_attempts`
     * tries, two seconds apart. Fails the step if it never does: an install
     * that cannot confirm its owner must not be reported ready.
     *
     * @throws ProvisioningFailedException
     */
    private function waitForSetupState(Application $application, string $base): bool
    {
        $attempts = max(1, (int) config('server.installers.n8n.ready_attempts', 60));

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $response = $this->run('create_admin', ['curl', '-sS', '--fail', '--max-time', '10', $base.'/rest/settings'], $application);
            $open = $this->decode($response->output())['data']['userManagement']['showSetupOnFirstLoad'] ?? null;

            if (is_bool($open)) {
                return $open;
            }

            if ($attempt < $attempts) {
                Sleep::for(2)->seconds();
            }
        }

        throw new ProvisioningFailedException('create_admin', (string) Str::uuid(), 'app_not_ready');
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
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
            // The proxy's public scheme changes only when the vhost gains or
            // loses a certificate. These values are reconciled on that event.
            'N8N_PROTOCOL' => $application->scheme(),
            // n8n defaults this to true and then refuses to open the editor
            // over a plain-HTTP URL — "your n8n server is configured to use a
            // secure cookie, however you are visiting this via an insecure
            // URL". A site has no certificate until one is issued, minutes
            // after it is created, so *every* first visit met that wall on a
            // site the panel had just reported as ready.
            //
            // Derived rather than hardcoded false, and that is the whole
            // point: a session cookie sent in clear on a site that does have
            // HTTPS is a real exposure, and `syncUrl()` below promotes this
            // the moment a certificate lands. It also demotes it again if one
            // is removed — without that, dropping a certificate would lock
            // somebody out of their own editor with no way back.
            'N8N_SECURE_COOKIE' => $application->scheme() === 'https' ? 'true' : 'false',
            'N8N_WEBHOOK_URL' => $application->url('/'),
            'N8N_EDITOR_BASE_URL' => $application->url(),
            'N8N_PROXY_HOPS' => '1',
            // n8n's own default is the `dev` channel — `releaseChannel = 'dev'`
            // in @n8n/config's GenericConfig — and the editor puts that channel
            // in the browser tab, so every site the panel created announced
            // itself as [DEV] to the customer running it in production.
            //
            // Not NODE_ENV, which the unit already sets to production; this is
            // a separate n8n concept and the unit's variable does not reach it.
            'N8N_RELEASE_TYPE' => 'stable',
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

    public function syncUrl(Application $application, string $url): void
    {
        $path = $application->documentRoot().'/.env';
        $scheme = (string) parse_url($url, PHP_URL_SCHEME);
        $values = [
            'N8N_PROTOCOL' => $scheme,
            // Both directions, deliberately. Promoting on a certificate is the
            // obvious half; demoting when one is removed is the half that
            // keeps a site reachable, because n8n answers a secure cookie on
            // an insecure URL by refusing to load the editor at all.
            'N8N_SECURE_COOKIE' => $scheme === 'https' ? 'true' : 'false',
            'N8N_WEBHOOK_URL' => rtrim($url, '/').'/',
            'N8N_EDITOR_BASE_URL' => rtrim($url, '/'),
            // Nothing to do with the URL, and here on purpose.
            //
            // Adding the variable to `environment()` fixes sites created after
            // this change and abandons every site already running — the same
            // shape as a shipped migration that only ever reaches fresh
            // installs. This is the one path that rewrites a live site's
            // environment, and the loop below appends a key it does not find,
            // so an existing site repairs itself the next time its certificate
            // is issued or removed. A site whose certificate never changes
            // again still needs a hand.
            'N8N_RELEASE_TYPE' => 'stable',
        ];

        $changed = $this->configMutator->transform($application, $path, function (string $contents) use ($values): string {
            // Remove the deprecated predecessor so two variables cannot
            // disagree about the public webhook URL.
            $contents = preg_replace('/^WEBHOOK_URL=.*\\R?/m', '', $contents) ?? $contents;

            foreach ($values as $key => $value) {
                $line = $key.'="'.Str::replace('"', '\\"', $value).'"';
                $updated = preg_replace('/^'.preg_quote($key, '/').'=.*$/m', $line, $contents, 1, $count);

                if (! is_string($updated)) {
                    throw new \RuntimeException('n8n environment could not be updated.');
                }

                $contents = $count === 1 ? $updated : rtrim($contents, "\n")."\n{$line}\n";
            }

            return $contents;
        });

        if (! $changed) {
            return;
        }

        $result = $this->supervisor->restart($application);

        if ($result->failed()) {
            throw new ProvisioningFailedException('sync_url', $result->reference);
        }
    }
}
