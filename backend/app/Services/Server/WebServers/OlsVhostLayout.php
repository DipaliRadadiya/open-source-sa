<?php

namespace App\Services\Server\WebServers;

use App\Models\ServerCapability;
use App\Services\Server\ServerOps;

/**
 * Where this server keeps its OpenLiteSpeed vhosts, and what the files are called.
 *
 *   this panel   <vhost_root>/<slug>/vhconf.conf
 *   the old one  /etc/<brand>-ols/<name>/main.conf   (+ php.conf beside it)
 *
 * `<brand>` is the old panel's build-time (whitelabel) name, so it is found by globbing
 * `/etc/*-ols`, not from a hardcoded list. Without this a migrated v7 server syncs zero sites.
 *
 * Detection is conservative: a directory only counts when it actually contains a vhost, so an
 * empty `/etc/something-ols` left by an uninstall does not redirect a working server.
 */
class OlsVhostLayout
{
    /** The filenames a vhost may have, ours first. */
    public const FILENAMES = ['vhconf.conf', 'main.conf'];

    public function __construct(private ServerOps $serverOps) {}

    /**
     * The directory this server's vhosts live in.
     *
     * The recorded (detected) answer wins over the config default.
     */
    public function root(): string
    {
        $recorded = ServerCapability::query()->value('ols_vhost_root');

        if (is_string($recorded) && $recorded !== '') {
            return rtrim($recorded, '/');
        }

        return rtrim((string) config('server.web_server_drivers.openlitespeed.vhost_root'), '/');
    }

    /**
     * Is this server using a layout the old panel left behind?
     *
     * A root is recorded only when detection found one, so no extra probe is needed.
     */
    public function isLegacy(): bool
    {
        $recorded = ServerCapability::query()->value('ols_vhost_root');

        return is_string($recorded) && $recorded !== '';
    }

    /**
     * What this server calls a vhost file.
     *
     * Per server, not per site: a migrated box uses the old name for new sites too, so
     * "which file does this site use" never has to be asked per site.
     */
    public function filename(): string
    {
        return $this->isLegacy() ? 'main.conf' : self::FILENAMES[0];
    }

    /**
     * Look for a vhost root the old panel wrote, and record it if one is there.
     *
     * Returns the root in use afterwards.
     */
    public function detect(): string
    {
        $configured = rtrim((string) config('server.web_server_drivers.openlitespeed.vhost_root'), '/');

        // Ours first: a server with both layouts is already managed by this panel, and moving it
        // to the inherited directory would strand its sites. "Ours" means registered sites, not
        // just files: the configured root always holds the panel's own vhosts and the Example.
        if ($configured !== '' && $this->holdsVhost($configured) && $this->managesSites()) {
            $this->record(null);

            return $configured;
        }

        foreach ($this->candidates() as $candidate) {
            if (! $this->holdsVhost($candidate)) {
                continue;
            }

            $this->record($candidate);

            return $candidate;
        }

        $this->record(null);

        return $configured;
    }

    /**
     * Has this panel registered any site in the shared config yet?
     *
     * An unreadable config answers yes, which keeps the layout this panel already had.
     */
    private function managesSites(): bool
    {
        $read = $this->serverOps->run(
            ['cat', (string) config('server.web_server_drivers.openlitespeed.shared_config', '/usr/local/lsws/conf/httpd_config.conf')],
            ['feature' => 'web_server', 'op' => 'ols_layout_sites'],
        );

        return ! $read->ok || app(OlsSharedConfig::class)->sites($read->output()) !== [];
    }

    /**
     * `/etc/*-ols` directories.
     *
     * @return array<int, string>
     */
    private function candidates(): array
    {
        $result = $this->serverOps->run(
            ['find', '/etc', '-maxdepth', '1', '-type', 'd', '-name', '*-ols'],
            ['feature' => 'web_server', 'op' => 'ols_layout_detect'],
            expectedExitCodes: [1],
        );

        if (! $result->answered) {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode("\n", $result->output())),
            fn (string $line): bool => $line !== '',
        ));
    }

    /**
     * Does this directory hold a site's vhost, under either name?
     *
     * Checks for a real vhost file, not just the directory: a config directory can exist without
     * the software (php-fpm ships an `/etc/apache2` on boxes with no Apache).
     */
    private function holdsVhost(string $directory): bool
    {
        $result = $this->serverOps->run(
            array_merge(
                ['find', $directory, '-mindepth', '2', '-maxdepth', '2', '-type', 'f', '('],
                $this->nameTests(),
                [')', '-print', '-quit'],
            ),
            ['feature' => 'web_server', 'op' => 'ols_layout_probe', 'path' => $directory],
            expectedExitCodes: [1],
        );

        return $result->answered && trim($result->output()) !== '';
    }

    /**
     * `-name a -o -name b`, which is how `find` spells "either".
     *
     * @return array<int, string>
     */
    public function nameTests(): array
    {
        $tests = [];

        foreach (self::FILENAMES as $index => $name) {
            if ($index > 0) {
                $tests[] = '-o';
            }

            $tests[] = '-name';
            $tests[] = $name;
        }

        return $tests;
    }

    private function record(?string $root): void
    {
        $row = ServerCapability::query()->first();

        if ($row === null) {
            return;
        }

        if ($row->ols_vhost_root === $root) {
            return;
        }

        $row->forceFill(['ols_vhost_root' => $root])->save();
    }
}
