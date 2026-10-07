<?php

namespace App\Services\Server\Applications;

use App\Models\Application;
use App\Services\Server\ServerOps;

/**
 * The `$table_prefix` a WordPress site really uses, read from its own
 * wp-config.php.
 *
 * Clone and staging copy every table as it is, then wrote a fresh
 * wp-config.php with `wp_` hardcoded. A site installed with another prefix
 * (a common hardening step, and the panel's own installer accepts one) came
 * out pointing at tables that do not exist: WordPress offered to install
 * itself again (frontend QA CL-B5).
 */
class WordPressTablePrefix
{
    public const DEFAULT = 'wp_';

    public function __construct(
        private ServerOps $serverOps,
        private ApplicationProvisioner $provisioner,
    ) {}

    public function of(Application $application): string
    {
        $documentRoot = $this->provisioner->documentRoot($application);

        // WordPress also loads wp-config.php from one level above the web
        // root, a common way to keep it out of reach of the web server.
        foreach ([$documentRoot, dirname($documentRoot)] as $directory) {
            $config = $this->serverOps->probe(
                ['cat', "{$directory}/wp-config.php"],
                ['feature' => 'application', 'op' => 'wp_table_prefix', 'application' => $application->id],
            );

            if ($config->ok) {
                return self::parse($config->output()) ?? self::fallback($application);
            }
        }

        return self::fallback($application);
    }

    public static function parse(string $config): ?string
    {
        return preg_match('/^\s*\$table_prefix\s*=\s*[\'"]([A-Za-z0-9_]+)[\'"]\s*;/m', $config, $match) === 1
            ? $match[1]
            : null;
    }

    private static function fallback(Application $application): string
    {
        $prefix = (string) ($application->installSettings()['table_prefix'] ?? '');

        return preg_match('/^[A-Za-z0-9_]+$/', $prefix) === 1 ? $prefix : self::DEFAULT;
    }
}
