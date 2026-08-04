<?php

namespace App\Services\Server\Doctor\Checks;

use App\Contracts\DoctorCheck;
use App\Services\Panel\InstalledPanelInfo;
use Illuminate\Support\Facades\File;

/**
 * Did the interface actually build, and is what it serves current?
 *
 * The services check proves the frontend unit is *running*. A Next server runs
 * perfectly happily on a build from last week — so a build that failed leaves
 * the panel up, serving stale code, with every check green. That is exactly
 * how a diagnostic file with a Node import in an Edge-runtime hook shipped to
 * users: the build broke, nothing said so, and the panel looked fine.
 *
 * Two questions, because they have different answers:
 *  - no build at all → the panel cannot serve its interface
 *  - a build older than the code → it is serving something other than what
 *    is on disk, which is the confusing one to debug
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
            // A backend-only deployment is a legitimate arrangement; saying
            // the panel is broken because a directory it does not use is
            // absent would be wrong.
            return ['status' => 'pass', 'detail' => 'no frontend in this installation', 'fix' => null];
        }

        $buildId = $frontend.'/.next/BUILD_ID';

        if (! is_file($buildId)) {
            return [
                'status' => 'fail',
                'detail' => 'no build output at frontend/.next',
                'fix' => 'doctor.fixes.frontend_build_missing',
            ];
        }

        $builtAt = (int) filemtime($buildId);
        $newestSource = $this->newestSourceTime($frontend);

        if ($newestSource > $builtAt) {
            return [
                'status' => 'warn',
                'detail' => sprintf(
                    'built %s, newest source %s — serving older code',
                    date('Y-m-d H:i', $builtAt),
                    date('Y-m-d H:i', $newestSource),
                ),
                'fix' => 'doctor.fixes.frontend_build_stale',
            ];
        }

        return [
            'status' => 'pass',
            'detail' => 'built '.date('Y-m-d H:i', $builtAt),
            'fix' => null,
        ];
    }

    /**
     * Newest mtime across the source that the build is made from.
     *
     * node_modules is excluded deliberately — npm rewrites it on every
     * install, so including it would report a stale build for a panel that
     * had just been rebuilt.
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
