<?php

namespace App\Services\Panel;

/**
 * The PHP the panel's own update runs `artisan` and `composer` with.
 *
 * OpenLiteSpeed installs have no `/usr/bin/php{version}` (the panel runs on LSPHP under
 * `/usr/local/lsws`), so the path cannot be built from the version alone.
 *
 * Order: a configured value (`PANEL_PHP_BIN`) wins; then the versioned path, which is tried
 * FIRST on purpose (on nginx/Apache `/usr/local/bin/php` is not ours and may be another
 * major version); then the running interpreter's sibling; then `/usr/local/bin/php`.
 * The last line returns the versioned path even if missing: the caller's "no such file" error
 * is clearer than an exception invented here.
 */
class PanelPhpBinary
{
    /**
     * Created by install.sh on OpenLiteSpeed only, pointing at LSPHP's CLI, for PHARs whose
     * `#!/usr/bin/env php` shebang needs a `php` on PATH.
     */
    public const FALLBACK = '/usr/local/bin/php';

    /** The paths are constructor arguments so the lookup order can be tested. */
    public function __construct(
        private string $versionedPrefix = '/usr/bin/php',
        private string $fallback = self::FALLBACK,
        /** The interpreter executing this code; injected so tests can control it. */
        private string $running = PHP_BINARY,
    ) {}

    public function path(): string
    {
        $configured = trim((string) config('panel_update.php_binary', ''));

        if ($configured !== '') {
            return $configured;
        }

        $versioned = $this->versionedPrefix.config('panel_update.php_version');

        if (is_executable($versioned)) {
            return $versioned;
        }

        // The CLI sibling of the interpreter running this code. Checked before the shared symlink
        // so the panel's self-update does not follow a PHP version the user picked as default.
        // Under LSAPI it is `<tree>/bin/php`; under php-fpm it does not exist (the versioned
        // path above already answered).
        $sibling = $this->runningSibling();

        if ($sibling !== null) {
            return $sibling;
        }

        if (is_executable($this->fallback)) {
            return $this->fallback;
        }

        return $versioned;
    }

    /**
     * `php` next to whatever is executing this, or null.
     *
     * Refuses the two shared paths: both are link targets someone may repoint, which could
     * leave the panel updating itself with an interpreter that cannot run it.
     */
    private function runningSibling(): ?string
    {
        if ($this->running === '') {
            return null;
        }

        $candidate = dirname($this->running).'/php';

        if (in_array($candidate, ['/usr/bin/php', self::FALLBACK], true)) {
            return null;
        }

        return is_executable($candidate) ? $candidate : null;
    }
}
