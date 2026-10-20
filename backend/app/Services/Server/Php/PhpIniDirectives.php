<?php

namespace App\Services\Server\Php;

/**
 * The few php.ini settings the PHP page offers as a form (RC-8, 2026-10-20),
 * read from and written into the server's own php.ini text.
 *
 * Everything else in the file is left exactly as it was: only the lines that
 * set these directives change. The write then goes through
 * PhpVersionManager::writeIni(), the same path as the raw editor — backup
 * first, PHP's own check, put back if PHP refuses it.
 */
class PhpIniDirectives
{
    public const KEYS = [
        'memory_limit', 'upload_max_filesize', 'post_max_size',
        'max_execution_time', 'max_input_time', 'max_input_vars',
    ];

    /**
     * What the file sets for each key, or null where it sets nothing (PHP's
     * built-in default then applies). The last active line wins, as in PHP.
     *
     * @return array<string, string|null>
     */
    public function read(string $contents): array
    {
        $values = array_fill_keys(self::KEYS, null);

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            if (preg_match('/^\s*([a-z_.]+)\s*=\s*(.*?)\s*$/i', $line, $m) === 1 && array_key_exists(strtolower($m[1]), $values)) {
                $values[strtolower($m[1])] = trim($m[2], " \t\"'");
            }
        }

        return $values;
    }

    /**
     * The file with these keys set. A key the file already sets has every
     * active line for it rewritten; one it does not is added right after the
     * `[PHP]` section header (or at the top). Comments are never touched.
     *
     * @param  array<string, string|int>  $changes
     */
    public function apply(string $contents, array $changes): string
    {
        foreach ($changes as $key => $value) {
            if (! in_array($key, self::KEYS, true)) {
                continue;
            }

            $line = $key.' = '.$value;
            $pattern = '/^[ \t]*'.preg_quote($key, '/').'[ \t]*=.*$/mi';

            if (preg_match($pattern, $contents) === 1) {
                $contents = (string) preg_replace($pattern, $line, $contents);

                continue;
            }

            $contents = preg_match('/^[ \t]*\[PHP\][ \t]*$/mi', $contents) === 1
                ? (string) preg_replace('/^([ \t]*\[PHP\][ \t]*)$/mi', '$1'."\n".$line, $contents, 1)
                : $line."\n".$contents;
        }

        return $contents;
    }
}
