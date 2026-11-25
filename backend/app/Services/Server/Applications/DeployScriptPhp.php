<?php

namespace App\Services\Server\Applications;

use App\Services\Server\Php\PhpVersionManager;
use App\Services\Server\Runtimes\PhpRuntime;

/**
 * v7's deploy-script variables: `{PHP81}`, `{PHP84}` — one per installed PHP version, each that
 * version's interpreter. Same names and targets as v7's `GitDeployment::aliases()`, so scripts
 * carried over from v7 keep working (the binary path comes from the stack's `PhpRuntime`).
 *
 * A variable naming a version that is not installed is refused when the script is saved and
 * again before a deploy runs it, rather than failing halfway through the shell script.
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
