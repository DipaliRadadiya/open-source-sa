<?php

namespace App\Services\Server\Applications\Installers\Concerns;

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use Composer\Semver\Semver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * One-click update for an app the panel installed from npm into the site
 * (n8n, Node-RED) — v7's update-n8n / update-node-red, done differently.
 *
 * v7 updated a global install as root, always to the newest major, never
 * restarted the process (the old code kept running), and read Node-RED's
 * "installed" version from the registry, so it showed the newest release as
 * installed. Here the version comes from the site's own node_modules, the
 * update stays inside the installed major like every other updater, runs as
 * the site user with the site's Node, and ends with a restart.
 *
 * Expects the using class to be an AbstractNodeInstaller using ResolvesUpdates.
 */
trait UpdatesNpmApp
{
    /** The npm package the site runs (`n8n`, `node-red`). */
    abstract protected function npmPackage(): string;

    /**
     * After the restart: throw if the app did not come back. The default
     * trusts the restart; n8n overrides it, because it answers 200 with a
     * "starting up" page while it migrates its database.
     */
    protected function confirmStarted(Application $application): void {}

    public function installedVersion(Application $application): ?string
    {
        $json = json_decode((string) $this->readSiteFile(
            $application,
            $application->documentRoot().'/node_modules/'.$this->npmPackage().'/package.json',
        ), true);
        $version = is_array($json) ? ($json['version'] ?? null) : null;

        return is_string($version) && $this->isStable($version) ? $this->normalizeVersion($version) : null;
    }

    /**
     * Within the installed major, the release npm itself would call current
     * for that line — never just the highest number.
     *
     * Both projects publish their test lines as plain x.y.z: n8n tags its
     * pre-release `next` (2.44.2 while `latest` is 2.43.6), and Node-RED keeps
     * each old major on a `vN-maintenance` tag. Sorting the version list would
     * hand a production site n8n's beta. So: the `latest` tag when it is in
     * the installed major, otherwise a non-test tag in that major, and only
     * for a line with no tag at all (n8n 1.x) the highest release in it.
     */
    public function availableVersions(Application $application, string $installed): array
    {
        $packument = $this->packument();

        if ($packument === null) {
            return ['latest' => null, 'newest' => null];
        }

        $tags = array_filter($packument['tags'], fn ($v, $tag) => is_string($v) && $this->isStable($v)
            && preg_match('/^(next|beta|alpha|rc|canary|nightly|dev|experimental)/i', (string) $tag) !== 1, ARRAY_FILTER_USE_BOTH);
        $newest = $tags['latest'] ?? null;
        $line = $this->line($installed);

        $latest = $this->highest(array_values(array_filter($tags, fn (string $v) => $this->line($v) === $line)))
            ?? $this->highestInLine(array_keys($packument['engines']), $installed);

        return ['latest' => $latest, 'newest' => $newest];
    }

    /**
     * npm install of the target into the site, the same command the install
     * ran, then a restart. Nothing else in the site is written — n8n's `.env`
     * (and its encryption key) and Node-RED's settings.js and flows stay as
     * they are; install() is never called, because it rewrites them.
     *
     * The old node_modules is moved aside first, not copied (n8n's is a
     * gigabyte), and put back if the install or the start fails, so a failed
     * update leaves the site on the version it had.
     */
    public function update(Application $application, string $from, string $to): void
    {
        $this->assertNodeSupports($application, $to);

        $root = $application->documentRoot();
        $saved = $root.'/.node_modules.pre-update';

        // A leftover from an earlier failed run would make `mv` nest the
        // current tree inside it instead of replacing it.
        $this->runAsSiteUser('prepare', $application, ['rm', '-rf', '--', $saved], null, $root);
        $this->runAsSiteUser('prepare', $application, ['cp', '-p', '--', $root.'/package.json', $root.'/.package.json.pre-update'], null, $root);
        $this->runAsSiteUser('prepare', $application, ['mv', '--', $root.'/node_modules', $saved], null, $root);

        try {
            $this->runWithNode('install_app', $application, [
                'npm', 'install', '--omit=dev', '--no-audit', '--no-fund', $this->npmPackage().'@'.$to,
            ], $root);
            $this->restartAndConfirm($application);
        } catch (Throwable $e) {
            $this->putBack($application, $root, $saved);

            throw $e;
        }

        $this->runAsSiteUser('cleanup', $application, ['rm', '-rf', '--', $saved, $root.'/.package.json.pre-update'], null, $root);
    }

    private function restartAndConfirm(Application $application): void
    {
        $restarted = $this->supervisor->restart($application);

        if ($restarted->failed()) {
            throw ProvisioningFailedException::fromResult('restart', $restarted);
        }

        $this->confirmStarted($application);
    }

    /** Best effort: the reason the update failed is what the user needs to see, not a rollback hiccup. */
    private function putBack(Application $application, string $root, string $saved): void
    {
        try {
            $this->runAsSiteUser('rollback', $application, ['rm', '-rf', '--', $root.'/node_modules'], null, $root);
            $this->runAsSiteUser('rollback', $application, ['mv', '--', $saved, $root.'/node_modules'], null, $root);
            $this->runAsSiteUser('rollback', $application, ['mv', '--', $root.'/.package.json.pre-update', $root.'/package.json'], null, $root);
            $this->supervisor->restart($application);
        } catch (Throwable) {
            // Reported through the original failure.
        }
    }

    /**
     * Refuse a release the site's Node cannot run, before anything moves:
     * both refuse to start outside `engines.node` (n8n 2 wants Node 24,
     * Node-RED 5 wants 22.9). A site with no pinned Node runs the server's
     * default, whose version is not on record — that is let through, as the
     * install itself does.
     */
    private function assertNodeSupports(Application $application, string $to): void
    {
        $node = (string) $application->node_version;
        $range = $this->packument()['engines'][$to] ?? null;

        if ($node === '' || $range === null) {
            return;
        }

        try {
            $ok = Semver::satisfies($node, $range);
        } catch (Throwable) {
            $ok = true;
        }

        if (! $ok) {
            throw new ProvisioningFailedException('check', (string) Str::uuid(), 'node_unsupported');
        }
    }

    /**
     * The registry's abbreviated packument: dist-tags, and `engines.node` per
     * stable release. The abbreviated form is what npm installs from; the
     * full one carries every README (n8n's would be tens of megabytes).
     *
     * @return array{tags: array<string, mixed>, engines: array<string, string>}|null
     */
    private function packument(): ?array
    {
        // Not memoised: the status lookup is cached by ApplicationUpdates, and
        // a copy held here would outlive releases in a long-running worker.
        try {
            $response = Http::timeout(30)
                ->withHeaders(['Accept' => 'application/vnd.npm.install-v1+json', 'User-Agent' => 'control-panel'])
                ->get('https://registry.npmjs.org/'.rawurlencode($this->npmPackage()));
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $engines = [];

        foreach ((array) $response->json('versions') as $version => $meta) {
            if ($this->isStable((string) $version)) {
                $engines[(string) $version] = (string) ($meta['engines']['node'] ?? '*');
            }
        }

        return ['tags' => (array) $response->json('dist-tags'), 'engines' => $engines];
    }
}
