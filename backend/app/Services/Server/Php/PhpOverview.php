<?php

namespace App\Services\Server\Php;

use App\Services\Runtime\LifecycleCatalog;
use App\Services\Runtime\PinnedSites;
use App\Services\Server\Runtimes\PhpRuntime;

/**
 * What the PHP screen shows: every installed version with the facts a user
 * decides on — whether it is the default, how many sites pin it, whether the
 * panel itself runs on it — plus what could still be installed.
 *
 * PHP is one feature here rather than three. It used to be split across the
 * Services screen (the ini editor, gated by `service`) and the Settings
 * screen (versions and extensions, gated by `setting`), which meant managing
 * PHP required both permissions — and `setting` also grants the SSH port and
 * the reboot button. You could not let someone change a PHP version without
 * also letting them reboot the server.
 */
class PhpOverview
{
    public function __construct(
        private PhpRuntime $runtime,
        private PhpVersionManager $versions,
        private PinnedSites $pinned,
        private LifecycleCatalog $lifecycle,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function read(): array
    {
        $sites = $this->pinned->summary('php_version');

        return [
            'default' => $this->runtime->default(),
            'panel_version' => $this->runtime->panelVersion(),
            'versions' => array_map(function (array $version) use ($sites) {
                $pinned = $sites[$version['version']] ?? null;

                return [
                    ...$version,
                    // Names, not just a count: "3 sites" does not tell you
                    // whether removing this breaks staging or the shop.
                    'in_use_by' => $pinned['count'] ?? 0,
                    'sites' => $pinned['names'] ?? [],
                    'sites_truncated' => $pinned['truncated'] ?? false,
                    // PHP has no LTS — active support, then security-only.
                    // There is deliberately no `lts` field here.
                    'lifecycle' => $this->lifecycle->for('php', $version['version']),
                    // The FPM unit stays on the Services screen — starting and
                    // stopping a daemon is the same job there as for nginx or
                    // redis. Named here so the two screens can link.
                    'service' => "php{$version['version']}-fpm",
                    'ini_path' => $this->versions->iniPath($version['version']),
                ];
            }, $this->runtime->versions()),
            'installable' => array_map(fn (string $version) => [
                'version' => $version,
                'lifecycle' => $this->lifecycle->for('php', $version),
            ], $this->runtime->installable()),
            'lifecycle_available' => ! $this->lifecycle->isStale(),
        ];
    }
}
