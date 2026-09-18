<?php

namespace App\Services\Server\WebServers;

use App\Models\ServerCapability;
use App\Services\Server\ServerOps;

/**
 * Where this server keeps its OpenLiteSpeed vhosts, and what the files are
 * called.
 *
 * Two layouts exist in the wild and a migrated server has the one this panel
 * did not write:
 *
 *   this panel   <vhost_root>/<slug>/vhconf.conf
 *   the old one  /etc/<brand>-ols/<name>/main.conf   (+ php.conf beside it)
 *
 * `<brand>` is the old panel's build-time name — `strings.ToLower(ServiceName)`
 * in its agent, its whitelabel's `folder_name` in its backend — so on a
 * reseller's box it is that reseller's brand and no hardcoded list is right
 * everywhere. It is found by looking, not by being told: the directory is on
 * disk, and a directory cannot be rebranded out from under a glob.
 *
 * This matters because without it a v7 server that installs this panel gets a
 * sync that finds **zero** OpenLiteSpeed sites — wrong root and wrong
 * filename — and a panel that cannot see the sites it is supposed to manage.
 *
 * Detection is deliberately conservative: a directory only counts when it
 * actually contains a vhost, so an empty `/etc/something-ols` left behind by
 * an uninstall does not redirect a working server at its own leftovers.
 */
class OlsVhostLayout
{
    /** The filenames a vhost may have, ours first. */
    public const FILENAMES = ['vhconf.conf', 'main.conf'];

    public function __construct(private ServerOps $serverOps) {}

    /**
     * The directory this server's vhosts live in.
     *
     * The recorded answer wins: it was measured on this box, and the config
     * default is only ever a guess about a server nobody has looked at.
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
     * Look for a vhost root the old panel wrote, and record it if one is
     * there.
     *
     * Returns the root in use afterwards, so a caller can log what it will be
     * working with rather than having to ask again.
     */
    public function detect(): string
    {
        $configured = rtrim((string) config('server.web_server_drivers.openlitespeed.vhost_root'), '/');

        // Ours first: a server that has both — one this panel created, one it
        // inherited — is a server this panel is already managing, and moving it
        // to the inherited directory would strand every site it made.
        if ($configured !== '' && $this->holdsVhost($configured)) {
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
     * `/etc/*-ols` directories, newest-looking last so the loop takes the
     * first that holds anything.
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
     * Asked of the filesystem rather than assumed from the directory existing:
     * php-fpm ships an `/etc/apache2` on boxes with no Apache, and a config
     * directory existing has already been mistaken here for a thing being
     * installed.
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
