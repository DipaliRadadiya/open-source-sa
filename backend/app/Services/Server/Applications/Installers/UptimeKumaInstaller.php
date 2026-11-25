<?php

namespace App\Services\Server\Applications\Installers;

use App\Contracts\ApplicationUpdater;
use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Services\Server\Applications\Installers\Concerns\ResolvesUpdates;
use Illuminate\Support\Facades\Http;

/**
 * Uptime Kuma — self-hosted uptime monitoring.
 *
 * Installed with `git clone` then `npm run setup` (upstream's own steps), not a release archive,
 * so the shared download helper is not used. Data lives in SQLite under `data/`.
 *
 * The panel creates the admin account in `afterStart()`: Kuma has no setup command, so otherwise
 * the first visitor to the public URL would pick the database and become administrator.
 *
 * Reads `PORT` from the unit; the rest comes from `.env` (see `environment()`).
 */
class UptimeKumaInstaller extends AbstractNodeInstaller implements ApplicationUpdater
{
    use ResolvesUpdates;

    public function siteType(): string
    {
        return 'uptimekuma';
    }

    public function startCommand(Application $application, string $documentRoot): ?string
    {
        return 'node server/server.js';
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function install(Application $application, string $documentRoot, array $context): void
    {
        $repository = (string) config('server.installers.uptimekuma.repository');

        $this->cloneInto($application, $repository, $this->ref($application, $repository), $documentRoot);

        // `npm run setup` also builds the frontend; `npm ci` alone would leave a blank page.
        $this->runWithNode('install_app', $application, ['npm', 'run', 'setup'], $documentRoot);

        $this->writeSecretFile($application, "{$documentRoot}/.env", $this->environment());
    }

    /**
     * - `UPTIME_KUMA_HOST`: Kuma listens on every interface by default, which would bypass the
     *   vhost, Basic Auth and WAF. The reverse proxy only needs 127.0.0.1.
     * - `UPTIME_KUMA_DB_TYPE`: SQLite, so nobody can choose it on the public first-run page.
     */
    private function environment(): string
    {
        return "UPTIME_KUMA_HOST=127.0.0.1\nUPTIME_KUMA_DB_TYPE=sqlite\n";
    }

    /**
     * Create the administrator through Kuma's own `setup` event (socket.io) before the site is
     * reported ready.
     *
     * Once an administrator exists Kuma refuses the event, which closes the first-run page.
     * Credentials travel on stdin. "Already initialized" counts as done, so a retry succeeds.
     */
    public function afterStart(Application $application, string $documentRoot): void
    {
        $settings = $application->installSettings();

        $this->runWithNode('create_admin', $application, ['node', '-e', self::SETUP_SCRIPT], $documentRoot, input: json_encode([
            'port' => (int) ($application->app_port ?: 3001),
            'username' => (string) ($settings['admin_username'] ?? 'admin'),
            'password' => (string) ($settings['admin_password'] ?? ''),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Fixed text: every value it uses arrives on stdin.
     *
     * Sends `setup` only after Kuma emits `loginRequired`: its `setup` handler is attached late in
     * the connection handler, so an earlier `setup` is dropped without a reply.
     *
     * Kuma's "needs setup" signal is computed at startup and unreliable early; the `setup` answer
     * (created, or "has been initialized") is the real one. Anything else is retried until the
     * deadline, except a password Kuma calls too weak.
     */
    private const SETUP_SCRIPT = <<<'JS'
        const { io } = require("socket.io-client");
        const input = JSON.parse(require("fs").readFileSync(0, "utf8"));
        const socket = io("http://127.0.0.1:" + input.port, { transports: ["websocket"], reconnectionDelay: 2000, timeout: 10000 });
        const done = (code, message) => { if (message) process.stderr.write(message + "\n"); socket.close(); process.exit(code); };
        setTimeout(() => done(1, "Uptime Kuma did not accept the administrator within 2 minutes."), 120000);
        let sending = false;
        const send = () => {
            if (sending) return;
            sending = true;
            socket.emit("setup", input.username, input.password, (res) => {
                sending = false;
                if (res && res.ok) return done(0);
                const message = String((res && res.msg) || "");
                if (message.includes("has been initialized")) return done(0, "Uptime Kuma already has an administrator.");
                if (message.includes("passwordTooWeak")) return done(1, "Uptime Kuma refused the password as too weak.");
                process.stderr.write("Uptime Kuma is not ready yet (" + message + "), retrying.\n");
                setTimeout(send, 3000);
            });
        };
        socket.on("loginRequired", send);
        JS;

    /**
     * The git ref to clone: an operator's pin, or the newest stable release.
     *
     * `SERVER_UPTIME_KUMA_BRANCH` pins a specific tag or branch; empty means ask the repository
     * for the newest release (a hardcoded version silently goes stale).
     */
    private function ref(Application $application, string $repository): string
    {
        $pinned = trim((string) config('server.installers.uptimekuma.branch', ''));

        return $pinned !== '' ? $pinned : $this->latestStableTag($application, $repository);
    }

    /**
     * The newest stable release, from the releases API, falling back to the repository's tags.
     *
     * The API knows drafts and pre-releases but is limited to 60 unauthenticated requests an hour
     * per IP; `ls-remote` has no limit but only sees tags.
     */
    private function latestStableTag(Application $application, string $repository): string
    {
        return $this->fromReleasesApi() ?? $this->fromRepositoryTags($application, $repository);
    }

    /**
     * The newest stable release from GitHub's releases API, or null if it could not be asked.
     *
     * Deliberately the full list sorted by version, not `/releases/latest`: that is the newest by
     * date, and upstream back-patches its 1.x line, so it could name a 1.x release as current.
     * Drafts and pre-releases are dropped by flag; the `x.y.z` pattern still applies on top.
     */
    private function fromReleasesApi(): ?string
    {
        $endpoint = trim((string) config('server.installers.uptimekuma.releases_api', ''));

        if ($endpoint === '') {
            return null;
        }

        try {
            $response = Http::timeout(15)
                // GitHub answers 403 to a request with no User-Agent.
                ->withHeaders(['Accept' => 'application/vnd.github+json', 'User-Agent' => 'control-panel'])
                ->get($endpoint, ['per_page' => 100]);
        } catch (\Throwable) {
            // Fall back to the tag list.
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $tags = collect($response->json())
            ->filter(fn ($release): bool => is_array($release)
                && ($release['draft'] ?? false) === false
                && ($release['prerelease'] ?? false) === false)
            ->pluck('tag_name')
            ->filter(fn ($tag): bool => is_string($tag) && preg_match('/^v?\d+\.\d+\.\d+$/', $tag) === 1)
            ->values()
            ->all();

        if ($tags === []) {
            return null;
        }

        return $this->highest($tags);
    }

    /**
     * The highest `x.y.z` tag the repository publishes (the fallback: no token, no rate limit).
     *
     * Pre-releases are excluded by the anchored pattern (`2.0.0-beta.3` does not match), since
     * tags carry no flags. Failing here is fatal rather than quietly installing an old pinned
     * version; the clone that follows uses the same host anyway.
     */
    private function fromRepositoryTags(Application $application, string $repository): string
    {
        $result = $this->run(
            'resolve_version',
            ['git', 'ls-remote', '--tags', '--refs', $repository],
            $application,
        );

        $tags = [];

        foreach (preg_split('/\R/', trim($result->output())) ?: [] as $line) {
            // `--refs` drops the `^{}` peeled duplicates. The `v` is optional: upstream used both.
            if (preg_match('#refs/tags/(v?\d+\.\d+\.\d+)$#', trim($line), $matches) === 1) {
                $tags[] = $matches[1];
            }
        }

        if ($tags === []) {
            throw new ProvisioningFailedException('resolve_version', $result->reference);
        }

        return $this->highest($tags);
    }

    /**
     * @param  array<int, string>  $tags
     */
    private function highest(array $tags): string
    {
        // `version_compare`, never a string sort: '2.10.0' sorts below '2.9.0' as text.
        usort($tags, fn (string $a, string $b): int => version_compare(ltrim($b, 'v'), ltrim($a, 'v')));

        return $tags[0];
    }

    // ---- One-click update (ApplicationUpdater) ---------------------------

    public function installedVersion(Application $application): ?string
    {
        $json = json_decode((string) $this->readSiteFile($application, $application->documentRoot().'/package.json'), true);
        $version = is_array($json) ? ($json['version'] ?? null) : null;

        return is_string($version) && $this->isStable($version) ? $this->normalizeVersion($version) : null;
    }

    public function availableVersions(Application $application, string $installed): array
    {
        $endpoint = trim((string) config('server.installers.uptimekuma.releases_api', ''));
        $versions = $endpoint === '' ? [] : array_map(
            fn (array $release): string => $this->normalizeVersion($release['tag_name']),
            $this->githubReleases($endpoint),
        );

        return ['latest' => $this->highestInLine($versions, $installed), 'newest' => $this->highest($versions)];
    }

    /**
     * Check out the release tag over the install's clone, rerun `npm run setup`, restart the
     * service. `data/` and `.env` are untracked, so the checkout leaves them alone.
     */
    public function update(Application $application, string $from, string $to): void
    {
        $documentRoot = $application->documentRoot();

        $this->runAsSiteUser('download', $application, [
            'git', 'fetch', '--depth', '1', 'origin', "refs/tags/{$to}:refs/tags/{$to}",
        ], null, $documentRoot);
        $this->runAsSiteUser('extract', $application, ['git', 'checkout', '--force', $to], null, $documentRoot);
        $this->runWithNode('install_app', $application, ['npm', 'run', 'setup'], $documentRoot);

        $this->progress->begin('restart');
        $restarted = $this->supervisor->restart($application);

        if ($restarted->failed()) {
            throw ProvisioningFailedException::fromResult('restart', $restarted);
        }
    }
}
