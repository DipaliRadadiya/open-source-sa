<?php

namespace App\Services\Server\Doctor\Checks;

use App\Contracts\DoctorCheck;
use App\Services\Panel\InstalledPanelInfo;
use Illuminate\Support\Facades\File;

/**
 * Did the interface actually build, and is what it serves current?
 *
 * The services check only proves the frontend unit is *running*; Next keeps serving an
 * old build after a failed one, with every other check green. Two cases:
 *  - no build at all → the panel cannot serve its interface
 *  - a build older than the code → it serves something other than what is on disk
 */
class FrontendBuildCheck implements DoctorCheck
{
    /** Directories whose contents should be older than the build. */
    private const SOURCES = ['app', 'components', 'lib', 'package.json'];

    public function __construct(private InstalledPanelInfo $installed) {}

    public function key(): string
    {
        return 'frontend_build';
    }

    public function run(): array
    {
        $frontend = $this->installed->repositoryPath().'/frontend';

        if (! is_dir($frontend)) {
            // A backend-only deployment is legitimate, not a failure.
            return ['status' => 'pass', 'detail' => __('doctor.details.frontend_none'), 'fix' => null];
        }

        $buildId = $frontend.'/.next/BUILD_ID';

        if (! is_file($buildId)) {
            return [
                'status' => 'fail',
                'detail' => __('doctor.details.frontend_missing'),
                'fix' => 'doctor.fixes.frontend_build_missing',
            ];
        }

        $builtAt = (int) filemtime($buildId);
        $newestSource = $this->newestSourceTime($frontend);

        if ($newestSource > $builtAt) {
            return [
                'status' => 'warn',
                'detail' => __('doctor.details.frontend_stale', [
                    'built' => date('Y-m-d H:i', $builtAt),
                    'source' => date('Y-m-d H:i', $newestSource),
                ]),
                'fix' => 'doctor.fixes.frontend_build_stale',
            ];
        }

        return [
            'status' => 'pass',
            'detail' => __('doctor.details.frontend_built', ['built' => date('Y-m-d H:i', $builtAt)]),
            'fix' => null,
        ];
    }

    /**
     * Newest mtime across the source that the build is made from.
     *
     * node_modules is excluded: npm rewrites it on every install, which would flag a
     * freshly rebuilt panel as stale.
     */
    private function newestSourceTime(string $frontend): int
    {
        $newest = 0;

        foreach (self::SOURCES as $source) {
            $path = $frontend.'/'.$source;

            if (is_file($path)) {
                $newest = max($newest, (int) filemtime($path));

                continue;
            }

            if (! is_dir($path)) {
                continue;
            }

            foreach (File::allFiles($path) as $file) {
                $newest = max($newest, (int) $file->getMTime());
            }
        }

        return $newest;
    }
}
