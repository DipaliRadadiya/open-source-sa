<?php

namespace App\Services\Runtime;

use App\Enums\InstallStatus;

/**
 * Folds in-flight installs into a runtime's version list.
 *
 * Shared by the PHP and Node screens: both overviews promise the frontend identical field
 * names so one component renders both.
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
            // Present on disk, so `ready` unless apt is currently working on it.
            ...($this->stillCurrent($installs->get($version['version']), $version)?->toProgress() ?? $this->settled()),
        ], $versions);

        $known = array_column($versions, 'version');

        // An installing or failed version has nothing on disk yet, so nothing above found it.
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
            // A version being installed must not also be offered for installation (a second
            // apt run).
            'installable' => array_values(array_filter(
                $installable,
                // Removing counts as busy too: Install would race the purge on the same package.
                fn (array $row) => ! in_array(
                    $installs->get($row['version'])?->status,
                    [InstallStatus::Installing, InstallStatus::Removing],
                    true,
                ),
            )),
        ];
    }

    /**
     * The install record, unless it is a failure the version has since got past: a version
     * now on disk with nothing missing is not failed any more. Only where the screen knows what
     * "complete" means (`missing_packages`); elsewhere the record stands.
     *
     * Never a failed *removal*: a version apt refused to purge is still on disk with nothing
     * missing, and settling it would show `ready` as if Remove had never been pressed.
     *
     * @param  array<string, mixed>  $version
     */
    private function stillCurrent(?object $install, array $version): ?object
    {
        if ($install?->status === InstallStatus::Failed
            && ! str_starts_with((string) $install->reason, 'remove_')
            && ($version['missing_packages'] ?? null) === []) {
            return null;
        }

        return $install;
    }

    /**
     * @return array<string, mixed>
     */
    private function settled(): array
    {
        return [
            'status' => InstallStatus::Ready->value,
            'queued' => false,
            'started_at' => null,
            'started_at_human' => null,
            'reason' => null,
            'message' => null,
            'reference' => null,
            'error_line' => null,
            // Named here too, so every version carries the same keys whether or not an install
            // touched it.
            'current_step' => null,
            'output' => null,
        ];
    }
}
