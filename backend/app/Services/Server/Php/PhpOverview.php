<?php

namespace App\Services\Server\Php;

use App\Contracts\PhpStack;
use App\Enums\InstallStatus;
use App\Services\Runtime\LifecycleCatalog;
use App\Services\Runtime\PinnedSites;
use App\Services\Runtime\RuntimeProgress;
use App\Services\Server\Runtimes\PhpRuntime;

/**
 * What the PHP screen shows: every installed version with the facts a user decides on (default,
 * how many sites pin it, whether the panel runs on it), plus what could still be installed.
 *
 * PHP is one permission feature: splitting it across `service` and `setting` would let someone
 * who manages PHP also change the SSH port or reboot the server.
 */
class PhpOverview
{
    public function __construct(
        private PhpRuntime $runtime,
        private PhpVersionManager $versions,
        private PinnedSites $pinned,
        private LifecycleCatalog $lifecycle,
        private RuntimeProgress $progress,
        private PhpStack $stack,
        private PhpFullVersion $fullVersions,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function read(): array
    {
        $sites = $this->pinned->summary('php_version');

        $progress = $this->progress->apply(
            'php',
            array_map(function (array $version) use ($sites) {
                $pinned = $sites[$version['version']] ?? null;

                return [
                    ...$version,
                    // Names, not just a count, so the user knows what removing it breaks.
                    'in_use_by' => $pinned['count'] ?? 0,
                    'sites' => $pinned['names'] ?? [],
                    'sites_truncated' => $pinned['truncated'] ?? false,
                    // PHP has no LTS (active support, then security-only), so no `lts` field.
                    'lifecycle' => $this->lifecycle->for('php', $version['version']),
                    // The FPM unit lives on the Services screen; named here so the screens can
                    // link. Asked of the stack: OpenLiteSpeed has no per-version unit.
                    'service' => $this->stack->serviceName($version['version']),
                    'ini_path' => $this->versions->iniPath($version['version']),
                    // Base packages this version should have and does not. Non-empty means the
                    // interpreter arrived another way (apt dependency, by hand) and is bare, so
                    // the screen can say so and Install will do something.
                    'missing_packages' => $this->runtime->missingBasePackages($version['version']),
                ];
            }, $this->runtime->versions()),
            array_map(fn (string $version) => [
                'version' => $version,
                'lifecycle' => $this->lifecycle->for('php', $version),
            ], $this->runtime->installable()),
        );

        $default = $this->runtime->default();

        $versions = array_map(fn (array $row) => [
            ...$row,
            // `8.4.14` where `version` is `8.4`. Only for a settled version: apt may be rewriting
            // the binary of one installing or being removed. Null otherwise, so the key is on
            // every row.
            'full_version' => ($row['status'] ?? null) === InstallStatus::Ready->value && isset($row['path'])
                ? $this->fullVersions->read($row['path'], $row['version'])
                : null,
        ], $progress['versions']);

        return [
            'default' => $default,
            'default_full_version' => collect($versions)->firstWhere('version', $default)['full_version'] ?? null,
            'panel_version' => $this->runtime->panelVersion(),
            'versions' => $versions,
            'installable' => $progress['installable'],
            'lifecycle_available' => ! $this->lifecycle->isStale(),
        ];
    }
}
