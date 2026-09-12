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
        /**
         * The interpreter executing this code. Injected for the same reason the
         * two paths above are: a rule that cannot be exercised on a developer's
         * box is one that gets reversed by the next person who finds it
         * puzzling, and `PHP_BINARY` is whatever happens to be running the
         * suite.
         */
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

        // The CLI sibling of the interpreter actually running this code.
        //
        // Checked before the shared symlink because the two are no longer the
        // same thing. `/usr/local/bin/php` used to be both "the panel's own
        // PHP" and "the php on PATH", and the moment the PHP screen's "make
        // default" button started owning the second, the panel's self-update
        // would have followed whatever version the user picked — up to and
        // including one that cannot run the panel.
        //
        // This needs no configuration and no migration, which matters because
        // install.sh only reaches fresh installs: the panel is being executed
        // by a binary in the tree it belongs to, so that tree is the answer.
        // Under LSAPI `PHP_BINARY` is `<tree>/bin/lsphp` and the sibling is
        // `<tree>/bin/php`; under php-fpm it is `/usr/sbin/php-fpm8.4`, whose
        // sibling does not exist — which is correct, because the versioned
        // path above has already answered on that stack.
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
     * Deliberately refuses the two shared paths. Reaching either of them here
     * would reintroduce the coupling this method exists to break: both are
     * link targets somebody else may move, and a panel that updates itself with
     * an interpreter a user can repoint is one setting away from not starting.
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
