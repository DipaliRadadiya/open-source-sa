<?php

namespace App\Services\Server\Applications\Installers\Concerns;

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use Composer\Semver\Semver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * One-click update for an app the panel installed from npm into the site (n8n, Node-RED).
 *
 * The installed version comes from the site's own node_modules, the update stays inside the
 * installed major, runs as the site user with the site's Node, and ends with a restart.
 *
 * Expects the using class to be an AbstractNodeInstaller using ResolvesUpdates.
 */
trait UpdatesNpmApp
{
    /** The npm package the site runs (`n8n`, `node-red`). */
    abstract protected function npmPackage(): string;

    /**
     * After the restart: throw if the app did not come back. n8n overrides this because it
     * answers 200 with a "starting up" page while it migrates its database.
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
     * Within the installed major, the release npm itself would call current for that line,
     * never just the highest number.
     *
     * Both projects publish test lines as plain x.y.z (n8n's `next`, Node-RED's `vN-maintenance`),
     * so sorting versions could hand a production site a beta. Use the `latest` tag if it is in
     * the installed major, else a non-test tag in that major, else the highest release in it.
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
     * npm install of the target into the site, then a restart. Nothing else is written: n8n's
     * `.env` (with its encryption key) and Node-RED's settings.js and flows stay, so install()
     * is never called (it rewrites them).
     *
     * The old node_modules is moved aside (n8n's is a gigabyte) and put back if the install or
     * start fails, so a failed update leaves the site on its old version.
     */
    public function update(Application $application, string $from, string $to): void
    {
        $this->progress->begin('check');
        $this->assertNodeSupports($application, $to);

        $root = $application->documentRoot();
        $saved = $root.'/.node_modules.pre-update';

        // A leftover from a failed run would make `mv` nest the current tree inside it.
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
        $this->progress->begin('restart');

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
     * Refuse a release the site's Node cannot run, before anything moves: both apps refuse to
     * start outside `engines.node`. A site with no pinned Node runs the server default, whose
     * version is not on record, and is let through as the install does.
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
     * The registry's abbreviated packument: dist-tags and `engines.node` per stable release.
     * The full one carries every README (tens of megabytes for n8n).
     *
     * @return array{tags: array<string, mixed>, engines: array<string, string>}|null
     */
    private function packument(): ?array
    {
        // Not memoised: ApplicationUpdates caches the lookup, and a copy here would go stale in a
        // long-running worker.
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
