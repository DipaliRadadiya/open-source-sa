<?php

namespace App\Services\Panel;

/**
 * The PHP the panel's own update runs `artisan` and `composer` with.
 *
 * Three places built this path as `'/usr/bin/php'.$version` and there is no
 * such file on an OpenLiteSpeed server. That stack deliberately installs no
 * ondrej PHP at all -- the panel runs on LSPHP under `/usr/local/lsws` -- so
 * every self-update on every OLS install invoked a binary that does not exist.
 * A panel that cannot update itself cannot ship a security fix.
 *
 * ## Why the order is what it is
 *
 * `install.sh` already computes exactly the right value (`PANEL_PHP_BIN`) on
 * both stacks. It just never wrote it to `.env`, so it is now written, and a
 * configured value wins over everything below.
 *
 * But install.sh changes only reach *fresh* installs -- the updater ships code,
 * never packages -- so an OLS panel installed before that line existed has no
 * such key and still has to be able to update itself. Hence the fallbacks.
 *
 * **The versioned path is tried FIRST, and that is deliberate.** Preferring
 * `/usr/local/bin/php` would be the shorter rule and a real regression: on
 * nginx and Apache that path is not ours. Nothing in install.sh creates it on
 * those stacks, so it is whatever the operator put there -- plausibly a
 * different major version than the panel needs. Checking `/usr/bin/php{version}`
 * first means those servers resolve exactly as they did before this class
 * existed, and only a machine that genuinely has no ondrej PHP falls through.
 *
 * The last line returns the versioned path even when it is missing, rather
 * than throwing. If neither exists, something is wrong that this class cannot
 * name, and the caller's own failure -- "no such file" against a path a human
 * can read -- says more than an exception invented here.
 */
class PanelPhpBinary
{
    /**
     * Created by install.sh on OpenLiteSpeed only, pointing at LSPHP's CLI,
     * for the PHARs whose `#!/usr/bin/env php` shebang needs a `php` on PATH.
     * Being ours there is what makes it a safe second choice.
     */
    public const FALLBACK = '/usr/local/bin/php';

    /**
     * The two paths are constructor arguments rather than constants so the
     * order can be tested. Both branches depend on what exists on the machine
     * running the test, and a rule that cannot be exercised on a developer's
     * box is one that gets reversed by the next person who finds it puzzling.
     */
    public function __construct(
        private string $versionedPrefix = '/usr/bin/php',
        private string $fallback = self::FALLBACK,
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

        if (is_executable($this->fallback)) {
            return $this->fallback;
        }

        return $versioned;
    }
}
