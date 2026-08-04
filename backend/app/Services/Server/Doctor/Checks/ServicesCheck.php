<?php

namespace App\Services\Server\Doctor\Checks;

use App\Contracts\DoctorCheck;
use Illuminate\Support\Facades\Process;

/**
 * Are the units the panel depends on actually running?
 *
 * The names come from config, which install.sh writes from the panel slug. A
 * mismatch here is silent and awful: the panel restarts a unit that does not
 * exist, systemctl fails, and the operator is told the update or the service
 * action failed for no discoverable reason. Checking the names against the
 * running system turns that into one obvious line.
 */
class ServicesCheck implements DoctorCheck
{
    public function key(): string
    {
        return 'services';
    }

    public function run(): array
    {
        $units = array_filter([
            config('panel_update.services.php_fpm'),
            config('panel_update.services.frontend'),
            config('panel_update.services.queue'),
        ]);

        $down = [];
        $missing = [];

        foreach ($units as $unit) {
            // is-active exits non-zero for both "stopped" and "no such unit",
            // so ask what it actually said — those are different problems with
            // different fixes.
            $state = trim(Process::timeout(10)->run(['systemctl', 'is-active', $unit])->output());

            if ($state === 'active') {
                continue;
            }

            $state === '' || $state === 'unknown' || $state === 'inactive'
                ? $down[] = $unit.' ('.($state ?: 'unknown').')'
                : $down[] = $unit.' ('.$state.')';

            if (! Process::timeout(10)->run(['systemctl', 'cat', $unit])->successful()) {
                $missing[] = $unit;
            }
        }

        if ($missing !== []) {
            return [
                'status' => 'fail',
                'detail' => 'no such unit: '.implode(', ', $missing),
                'fix' => 'doctor.fixes.services_missing',
            ];
        }

        if ($down !== []) {
            return [
                'status' => 'fail',
                'detail' => 'not running: '.implode(', ', $down),
                'fix' => 'doctor.fixes.services_down',
            ];
        }

        return [
            'status' => 'pass',
            'detail' => implode(', ', $units),
            'fix' => null,
        ];
    }
}
