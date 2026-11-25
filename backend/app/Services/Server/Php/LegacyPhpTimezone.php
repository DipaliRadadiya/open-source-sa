<?php

namespace App\Services\Server\Php;

use App\Contracts\PhpStack;
use App\Services\Server\ManagedFile;
use Illuminate\Support\Facades\Log;

/**
 * Tell PHP 5.6–7.4 the server's timezone instead of letting it guess.
 *
 * On those versions a guessed zone (e.g. Asia/Kolkata) makes every site answer 500 with
 * "Timezone database is corrupt"; an explicit `date.timezone` works. Only below 8.0: 8.x guesses
 * correctly, and a file here would override whatever is set in that version's own php.ini.
 *
 * A file of its own in the scan directory, not an edit to php.ini, so the distro's conffile stays
 * untouched (see FpmReloadGrace). A site's own PHP setting still wins: the pool's php_admin_value
 * beats any ini file.
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
     * Every installed version that needs it. Never fatal: the timezone itself has already changed.
     * Returns the versions it could not update; the server-ops log has the reason.
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
     * One version: write the file into every directory it scans, then reload. False on any failure.
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
