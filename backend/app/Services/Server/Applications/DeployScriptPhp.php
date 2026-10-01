<?php

namespace App\Services\Server\Applications;

use App\Services\Server\Php\PhpVersionManager;
use App\Services\Server\Runtimes\PhpRuntime;

/**
 * v7's deploy-script variables: `{PHP81}`, `{PHP84}` — one per installed PHP
 * version, each that version's interpreter.
 *
 * v8 had only `{php}` (the site's own version), so a script carried over from
 * v7 ran `{PHP81} artisan migrate` as literal text and died on "command not
 * found" (operator, 2026-10-01: many sites come from v7). Same names and same
 * targets as v7's `GitDeployment::aliases()`: `/usr/bin/php8.1` on FPM,
 * `/usr/local/lsws/lsphp81/bin/php` on OpenLiteSpeed — the stack's own
 * binary path, so neither is spelled out here.
 *
 * A variable naming a version that is not installed is refused when the
 * script is saved and again before a deploy runs it, rather than left as text
 * for the shell to stumble over halfway through.
 */
class DeployScriptPhp
{
    /** `{PHP` + major digit + minor digits + `}`. */
    public const PATTERN = '/\{PHP(\d)(\d+)\}/';

    public function __construct(
        private PhpVersionManager $versions,
        private PhpRuntime $php,
    ) {}

    /**
     * @return array<string, string> `{PHP84}` => that version's interpreter
     */
    public function aliases(): array
    {
        $aliases = [];

        foreach ($this->versions->versions() as $version) {
            $aliases['{PHP'.str_replace('.', '', $version).'}'] = $this->php->binaryPath($version);
        }

        return $aliases;
    }

    /**
     * The `{PHPxx}` variables in this script with no installed version behind them.
     *
     * @return array<int, string>
     */
    public function missing(string $script): array
    {
        preg_match_all(self::PATTERN, $script, $matches);

        return array_values(array_unique(array_diff($matches[0], array_keys($this->aliases()))));
    }

    /**
     * `{PHP81}` → `8.1`, for saying which version to install.
     *
     * @param  array<int, string>  $variables
     * @return array<int, string>
     */
    public static function versionsOf(array $variables): array
    {
        return array_map(
            fn (string $variable): string => preg_replace(self::PATTERN, '$1.$2', $variable),
            $variables,
        );
    }
}
