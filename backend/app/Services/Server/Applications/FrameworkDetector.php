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
        $root = $this->provisioner->documentRoot($application);

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

    /**
     * Whether a saved `.env` needs an extra step to be read.
     *
     * Only true for a framework that caches its configuration AND has a cache
     * sitting there right now. Reporting it unconditionally would train people
     * to ignore it.
     */
    public function requiresApply(Application $application, string $framework): bool
    {
        $root = $this->provisioner->documentRoot($application);

        return match ($framework) {
            self::LARAVEL, self::STATAMIC => $this->exists($application, $root.'/bootstrap/cache/config.php'),
            self::CRAFT => false,
            default => false,
        };
    }

    /**
     * The command that makes a saved `.env` take effect, or null when saving
     * is enough on its own.
     *
     * @return array<int, string>|null
     */
    public function applyCommand(Application $application, string $framework): ?array
    {
        $root = $this->provisioner->documentRoot($application);
        $php = 'php'.($application->php_version ?: '');

        return match ($framework) {
            self::LARAVEL, self::STATAMIC => [$php, $root.'/artisan', 'config:clear'],
            default => null,
        };
    }

    private function exists(Application $application, string $path): bool
    {
        // Through ServerOps rather than PHP's is_file(): the site directory is
        // owned by the site's system user and is not readable by the panel's
        // own unprivileged account.
        return $this->serverOps->run(
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
