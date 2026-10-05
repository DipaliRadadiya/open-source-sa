<?php

namespace App\Services\Server\Php;

use App\Contracts\PhpStack;
use App\Services\Server\ManagedFile;
use Illuminate\Support\Facades\Log;

/**
 * Tell PHP 5.6–7.4 the server's timezone instead of letting it guess.
 *
 * Junior re-test #14: after the server timezone was set to Asia/Kolkata, every
 * site on PHP 5.6–7.4 answered 500 with "Timezone database is corrupt - this
 * should *never* happen!", and 8.x carried on. Measured on the nginx test
 * server (Ubuntu 26.04, tzdata 2026c, 2026-10-05) with the CLI, which shares
 * the code path:
 *
 *  - no `date.timezone`, zone guessed from the system → fatal, on 5.6 and 7.4;
 *  - `date.timezone = Asia/Kolkata` set explicitly → works, on both;
 *  - UTC either way → works, which is why nobody saw it before a change.
 *
 * So it is the *guess* that breaks on those versions, not the zone data, and
 * an explicit value is the fix. Only below 8.0: 8.x guesses correctly, and a
 * file here would quietly override whatever someone set in that version's own
 * php.ini.
 *
 * A file of its own in the scan directory rather than an edit to php.ini, for
 * the reason FpmReloadGrace gives: the distro's conffile stays untouched. A
 * site's own PHP setting still wins — the pool sets it with
 * php_admin_value, which beats any ini file.
 */
class LegacyPhpTimezone
{
    public const FILE = '00-panel-timezone.ini';

    public function __construct(
        private PhpStack $stack,
        private ManagedFile $files,
    ) {}

    public static function needed(string $version): bool
    {
        return version_compare($version, '8.0', '<');
    }

    public static function contents(string $timezone): string
    {
        return "; Managed by the panel. PHP before 8.0 cannot work out the server's timezone by itself.\n"
            ."date.timezone = {$timezone}\n";
    }

    /**
     * Every installed version that needs it. Never fatal: the timezone itself
     * has already changed, and failing the request over one PHP version would
     * report the opposite of what happened. Returns the versions it could not
     * update, which the server-ops log has the reason for.
     *
     * @return array<int, string>
     */
    public function applyAll(string $timezone): array
    {
        $failed = [];

        foreach ($this->stack->versions() as $version) {
            if (self::needed($version) && ! $this->apply($version, $timezone)) {
                $failed[] = $version;
            }
        }

        return $failed;
    }

    /**
     * One version: write the file into every directory it scans, then reload
     * so running workers stop guessing. False on any failure.
     */
    public function apply(string $version, string $timezone): bool
    {
        if (! self::needed($version) || ! preg_match('~^[A-Za-z0-9_+\-]+(/[A-Za-z0-9_+\-]+)*$~D', $timezone)) {
            return false;
        }

        $context = ['feature' => 'php', 'op' => 'legacy_timezone', 'version' => $version];

        // On LSPHP every SAPI scans the same directory; once is enough.
        $dirs = array_values(array_unique(array_map(
            fn (string $sapi): string => $this->stack->scanDir($version, $sapi),
            $this->stack->sapis($version),
        )));

        foreach ($dirs as $dir) {
            $path = "{$dir}/".self::FILE;

            if ($this->files->put($path, self::contents($timezone), $context)->failed()) {
                Log::channel('server-ops')->warning('php.legacy_timezone_not_written', $context + ['path' => $path]);

                return false;
            }
        }

        return $this->stack->reload($version)->ok;
    }
}
