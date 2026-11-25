<?php

namespace App\Services\Server\Applications\Installers;

use App\Contracts\ApplicationUpdater;
use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Services\Server\Applications\Installers\Concerns\ResolvesUpdates;
use App\Services\Server\Applications\Installers\Concerns\UpdatesNpmApp;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/**
 * n8n — workflow automation.
 *
 * Installed into the site rather than globally (one version per site, owned by the user who
 * runs it) and configured through the site's `.env`, which the unit already loads.
 *
 *  - **`N8N_ENCRYPTION_KEY` is generated once and never changes.** It encrypts every stored
 *    credential; losing or changing it makes them permanently unreadable, with no error until a
 *    workflow runs. Generated before first start, since n8n's own would land in a data
 *    directory a reinstall would replace.
 *  - **`N8N_USER_FOLDER` points into the site**: n8n defaults to `~/.n8n`, and the unit mounts
 *    the home read-only.
 *
 * Data lives in SQLite under that folder, so no database is needed.
 *
 * **The panel creates the owner account** in `afterStart()`: n8n has no command for it, and
 * left alone its owner is whoever opens the public URL first.
 *
 * **Licensing:** n8n is fair-code (Sustainable Use License), not OSI open source.
 */
class N8nInstaller extends AbstractNodeInstaller implements ApplicationUpdater
{
    use ResolvesUpdates;
    use UpdatesNpmApp;

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
     * Claim the instance: create its owner through n8n's own setup endpoint, from the server,
     * before the site is reported ready.
     *
     * Same request as n8n's first-run page, to 127.0.0.1 only. Once an owner exists the endpoint
     * refuses (400), which closes the page to a stranger. The password travels on stdin.
     *
     * **A 200 proves nothing here.** While starting, n8n answers every path with `200` and a
     * "starting up" page, so the setup request could "succeed" and leave the site unclaimed. This
     * waits for the real settings JSON first, then requires the first-run page to be closed.
     *
     * Skipped when n8n already has an owner, so Retry Setup does not fail on a claimed instance.
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

    protected function npmPackage(): string
    {
        return 'n8n';
    }

    /**
     * n8n migrates its database on start and answers 200 with a "starting up" page meanwhile:
     * wait for the real settings JSON, as the install does.
     */
    protected function confirmStarted(Application $application): void
    {
        $this->waitForSetupState($application, 'http://127.0.0.1:'.((int) ($application->app_port ?: 5678)));
    }

    /**
     * Whether n8n's first-run page is still open, once n8n can say.
     *
     * Polls `/rest/settings` until it returns real settings, up to
     * `server.installers.n8n.ready_attempts` tries two seconds apart; fails the step otherwise.
     *
     * @throws ProvisioningFailedException
     */
    private function waitForSetupState(Application $application, string $base): bool
    {
        $attempts = max(1, (int) config('server.installers.n8n.ready_attempts', 60));

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            // Not `run()`, which fails on the first non-2xx: while starting, n8n refuses the
            // connection, serves a starting-up page, then 404s while routes register. All mean "not yet".
            $response = $this->serverOps->run(
                ['curl', '-sS', '--fail', '--max-time', '10', $base.'/rest/settings'],
                ['feature' => 'application', 'op' => 'installer.create_admin_wait', 'application' => $application->id],
                timeout: 20,
            );
            $open = $response->ok
                ? ($this->decode($response->output())['data']['userManagement']['showSetupOnFirstLoad'] ?? null)
                : null;

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
     * The key this instance already uses, when it has been started before.
     *
     * `install()` runs again on Retry Setup, and a fresh key then makes n8n refuse to start
     * ("Mismatching encryption keys"): n8n keeps its first key in `.n8n/config`. That file is
     * asked first, then the `.env`.
     */
    private function existingEncryptionKey(Application $application, string $documentRoot): ?string
    {
        $read = fn (string $path) => $this->serverOps->run(
            ['cat', $path],
            ['feature' => 'application', 'op' => 'installer.read_encryption_key', 'application' => $application->id],
        );

        $config = $read($documentRoot.'/.n8n/config');
        $key = $config->ok ? ($this->decode($config->output())['encryptionKey'] ?? null) : null;

        if (! is_string($key) || $key === '') {
            $env = $read($documentRoot.'/.env');
            $key = $env->ok && preg_match('/^N8N_ENCRYPTION_KEY="?([^"\n]+)"?$/m', $env->output(), $m) ? $m[1] : null;
        }

        return is_string($key) && preg_match('/^[A-Za-z0-9+\/=_-]{16,}$/', $key) === 1 ? $key : null;
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
            // Generated before first start (see the class note): 32 random bytes, hex.
            'N8N_ENCRYPTION_KEY' => $this->existingEncryptionKey($application, $documentRoot) ?? bin2hex(random_bytes(32)),
            'N8N_USER_FOLDER' => $documentRoot,
            'N8N_PORT' => (string) ($application->app_port ?: 5678),
            // The task-runner broker binds 5679 unless told, so a second n8n on the server exited on
            // start. Left out for sites created before they had a port of their own.
            ...($application->internal_port ? ['N8N_RUNNERS_BROKER_PORT' => (string) $application->internal_port] : []),
            // Loopback only: binding wider would publish the site on the server's own address,
            // past whatever the vhost does.
            'N8N_LISTEN_ADDRESS' => '127.0.0.1',
            'N8N_HOST' => $domain,
            // The public scheme changes only when the vhost gains or loses a certificate; reconciled then.
            'N8N_PROTOCOL' => $application->scheme(),
            // n8n refuses to open the editor over plain HTTP when this is true (its default), and a
            // site has no certificate until minutes after creation. Derived, not hardcoded false: a
            // cookie sent in clear on an HTTPS site is a real exposure, so `syncUrl()` promotes it when a
            // certificate lands and demotes it when one is removed (else the editor locks out).
            'N8N_SECURE_COOKIE' => $application->scheme() === 'https' ? 'true' : 'false',
            'N8N_WEBHOOK_URL' => $application->url('/'),
            'N8N_EDITOR_BASE_URL' => $application->url(),
            'N8N_PROXY_HOPS' => '1',
            // n8n's own default release channel is `dev`, which the editor shows as [DEV] in the
            // browser tab. Separate from NODE_ENV, which does not reach it.
            'N8N_RELEASE_TYPE' => 'stable',
            'GENERIC_TIMEZONE' => (string) config('app.timezone', 'UTC'),
            'N8N_DIAGNOSTICS_ENABLED' => 'false',
        ];

        $contents = "# Managed by the panel. N8N_ENCRYPTION_KEY must never change.\n";

        foreach ($lines as $key => $value) {
            // systemd's parser is not a shell: quoting keeps a value with a space in it one value.
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
            // Both directions: demoting when a certificate is removed keeps the site reachable, since
            // n8n refuses to load the editor with a secure cookie on an insecure URL.
            'N8N_SECURE_COOKIE' => $scheme === 'https' ? 'true' : 'false',
            'N8N_WEBHOOK_URL' => rtrim($url, '/').'/',
            'N8N_EDITOR_BASE_URL' => rtrim($url, '/'),
            // Nothing to do with the URL, and here on purpose: this is the one path that rewrites a
            // live site's environment (the loop below appends missing keys), so existing sites pick
            // this up the next time their certificate is issued or removed.
            'N8N_RELEASE_TYPE' => 'stable',
        ];

        $changed = $this->configMutator->transform($application, $path, function (string $contents) use ($values): string {
            // Drop the deprecated predecessor so two variables cannot disagree about the webhook URL.
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
