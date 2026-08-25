<?php

namespace App\Services\Server\Applications;

use App\Models\Application;
use App\Services\Server\ServerOps;

/**
 * What framework is actually deployed in a site's directory.
 *
 * Detected from files on disk, never inferred from the site-type record: a git
 * site is whatever the repository contains, and that changes without telling
 * us. Same detect-don't-trust rule the rest of the panel follows.
 *
 * This exists for one concrete reason. A Laravel site with a cached config
 * ignores `.env` entirely — you save, the panel says "saved", and nothing
 * happens, with no error anywhere to explain it. Knowing the framework is what
 * lets the save actually take effect.
 */
class FrameworkDetector
{
    public const LARAVEL = 'laravel';

    public const CRAFT = 'craft';

    public const STATAMIC = 'statamic';

    public const NEXTJS = 'nextjs';

    public const NUXT = 'nuxt';

    public const NODE = 'node';

    public const UNKNOWN = 'unknown';

    public function __construct(
        private ServerOps $serverOps,
        private ApplicationProvisioner $provisioner,
    ) {}

    /**
     * Ordered most-specific first: Statamic and Craft are Laravel underneath
     * and both ship an `artisan`, so checking for Laravel first would label
     * every Statamic site "Laravel" and offer the wrong apply command.
     */
    public function detect(Application $application): string
    {
        return $this->locate($application)['framework'];
    }

    /**
     * The directory that actually contains the deployed framework's CLI.
     *
     * `codePath()` is authoritative for panel-installed layouts such as Craft
     * and Statamic. The document-root parent is also inspected for migrated
     * git applications that already use the conventional `project/public`
     * layout; current flat git deployments remain supported because their
     * framework marker is found in the document root first.
     */
    public function root(Application $application): string
    {
        return $this->locate($application)['root'];
    }

    /**
     * Whether a saved `.env` needs an extra step to be read.
     *
     * Only true for a framework that caches its configuration AND has a cache
     * sitting there right now. Reporting it unconditionally would train people
     * to ignore it.
     */
    public function requiresApply(Application $application, string $framework): bool
    {
        if (! in_array($framework, [self::LARAVEL, self::STATAMIC], true)) {
            return false;
        }

        $root = $this->rootFor($application, $framework);

        return $this->exists($application, $root.'/bootstrap/cache/config.php');
    }

    /**
     * The command that makes a saved `.env` take effect, or null when saving
     * is enough on its own.
     *
     * @return array<int, string>|null
     */
    public function applyCommand(Application $application, string $framework): ?array
    {
        if (! in_array($framework, [self::LARAVEL, self::STATAMIC], true)) {
            return null;
        }

        $root = $this->rootFor($application, $framework);
        $php = 'php'.($application->php_version ?: '');

        return [$php, $root.'/artisan', 'config:clear'];
    }

    /**
     * @return array{framework: string, root: string}
     */
    private function locate(Application $application): array
    {
        foreach ($this->candidateRoots($application) as $root) {
            $framework = $this->detectAt($application, $root);

            if ($framework !== self::UNKNOWN) {
                return ['framework' => $framework, 'root' => $root];
            }
        }

        return ['framework' => self::UNKNOWN, 'root' => $application->codePath()];
    }

    private function detectAt(Application $application, string $root): string
    {
        return match (true) {
            $this->exists($application, $root.'/please') => self::STATAMIC,
            $this->exists($application, $root.'/craft') => self::CRAFT,
            $this->exists($application, $root.'/artisan') => self::LARAVEL,
            $this->anyExists($application, $root, ['next.config.js', 'next.config.mjs', 'next.config.ts']) => self::NEXTJS,
            $this->anyExists($application, $root, ['nuxt.config.js', 'nuxt.config.mjs', 'nuxt.config.ts']) => self::NUXT,
            $this->exists($application, $root.'/package.json') => self::NODE,
            default => self::UNKNOWN,
        };
    }

    private function rootFor(Application $application, string $framework): string
    {
        foreach ($this->candidateRoots($application) as $root) {
            if ($this->detectAt($application, $root) === $framework) {
                return $root;
            }
        }

        return $application->codePath();
    }

    /**
     * @return array<int, string>
     */
    private function candidateRoots(Application $application): array
    {
        $documentRoot = $this->provisioner->documentRoot($application);
        $roots = [$application->codePath(), $documentRoot];

        // A non-root web root may describe a conventional project layout that
        // predates this panel: `artisan` beside `public/`, not inside it.
        if (trim((string) $application->web_root, '/') !== '') {
            $roots[] = dirname($documentRoot);
        }

        return array_values(array_unique($roots));
    }

    private function exists(Application $application, string $path): bool
    {
        // Through ServerOps rather than PHP's is_file(): the site directory is
        // owned by the site's system user and is not readable by the panel's
        // own unprivileged account. Exit 1 is the expected "not found" answer,
        // not an error for the admin dashboard.
        return $this->serverOps->probe(
            ['test', '-e', $path],
            ['feature' => 'application', 'op' => 'detect_framework', 'application' => $application->id],
            timeout: 15,
        )->ok;
    }

    /**
     * @param  array<int, string>  $names
     */
    private function anyExists(Application $application, string $root, array $names): bool
    {
        foreach ($names as $name) {
            if ($this->exists($application, $root.'/'.$name)) {
                return true;
            }
        }

        return false;
    }
}
