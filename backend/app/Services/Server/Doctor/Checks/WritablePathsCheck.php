<?php

namespace App\Services\Server\Doctor\Checks;

use App\Contracts\DoctorCheck;

/**
 * Directories the panel writes to as itself.
 *
 * The recurring failure this catches: root creates a directory during install
 * and the panel account cannot write to it. Nothing complains until the first
 * time the feature is used, which may be weeks later and will be reported as
 * "the update button does nothing".
 */
class WritablePathsCheck implements DoctorCheck
{
    public function key(): string
    {
        return 'writable_paths';
    }

    public function run(): array
    {
        $paths = [
            'storage' => storage_path(),
            'logs' => storage_path('logs'),
            'update state' => (string) config('panel_update.state_dir'),
        ];

        $problems = [];

        foreach ($paths as $label => $path) {
            if ($path === '') {
                continue;
            }

            if (! is_dir($path)) {
                // Only a warning: the update state directory is created on
                // first use if its parent allows it. A hard failure here would
                // cry wolf on a panel that has simply never updated.
                $problems[] = $label.' missing ('.$path.')';

                continue;
            }

            if (! is_writable($path)) {
                $problems[] = $label.' not writable ('.$path.')';
            }
        }

        if ($problems !== []) {
            return [
                'status' => 'fail',
                'detail' => implode('; ', $problems),
                'fix' => 'doctor.fixes.writable_paths',
            ];
        }

        return [
            'status' => 'pass',
            'detail' => implode(', ', array_keys($paths)),
            'fix' => null,
        ];
    }
}
