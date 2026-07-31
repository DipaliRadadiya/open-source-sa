<?php

namespace App\Services\Runtime;

use App\Enums\InstallStatus;

/**
 * Folds in-flight installs into a runtime's version list.
 *
 * Shared by the PHP and Node screens rather than written twice: the two
 * overviews already promise the frontend identical field names so one
 * component renders both, and a progress field that behaved differently
 * between them would quietly break that promise.
 */
class RuntimeProgress
{
    public function __construct(private InstallTracker $installs) {}

    /**
     * @param  array<int, array<string, mixed>>  $versions  detected on disk
     * @param  array<int, array<string, mixed>>  $installable  offered by the package source
     * @return array{versions: array<int, array<string, mixed>>, installable: array<int, array<string, mixed>>}
     */
    public function apply(string $runtime, array $versions, array $installable): array
    {
        $installs = $this->installs->versions($runtime);

        $onDisk = array_map(fn (array $version) => [
            ...$version,
            // Present on disk, so `ready` unless apt is currently doing
            // something to it — reinstalling over an existing version, say.
            ...($installs->get($version['version'])?->toProgress() ?? $this->settled()),
        ], $versions);

        $known = array_column($versions, 'version');

        // A version that is installing or failed has nothing on disk yet, so
        // nothing above found it. These are the entries the screen could not
        // show at all before.
        $pending = $installs
            ->reject(fn ($install, string $version) => in_array($version, $known, true))
            ->map(fn ($install, string $version) => [
                'version' => $version,
                ...$install->toProgress(),
            ])
            ->values()
            ->all();

        return [
            'versions' => [...$pending, ...$onDisk],
            // A version being installed right now must not also be offered
            // for installation — the button would start a second apt run for
            // something already underway.
            'installable' => array_values(array_filter(
                $installable,
                fn (array $row) => $installs->get($row['version'])?->status !== InstallStatus::Installing,
            )),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function settled(): array
    {
        return [
            'status' => InstallStatus::Ready->value,
            'started_at' => null,
            'started_at_human' => null,
            'reason' => null,
            'message' => null,
            'reference' => null,
        ];
    }
}
