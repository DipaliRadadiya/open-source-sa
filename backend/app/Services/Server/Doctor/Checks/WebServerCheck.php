<?php

namespace App\Services\Server\Doctor\Checks;

use App\Contracts\DoctorCheck;
use Illuminate\Support\Facades\Process;

/**
 * Is there a web server the panel can write config for, and is that config
 * currently valid?
 *
 * Two separate failures hide here. A box running something the panel has no
 * driver for means every site creation is refused — correctly, but only at
 * the moment someone tries. And a config that no longer passes its own test
 * means the *next* reload takes every hosted site down, whether or not the
 * panel caused it; finding that out during a deploy is the worst possible
 * time.
 */
class WebServerCheck implements DoctorCheck
{
    public function key(): string
    {
        return 'web_server';
    }

    public function run(): array
    {
        $detected = $this->detect();

        if ($detected === null) {
            return [
                'status' => 'fail',
                'detail' => 'none of '.implode(', ', array_keys((array) config('server.web_servers'))).' found',
                'fix' => 'doctor.fixes.web_server_missing',
            ];
        }

        $drivers = (array) config('server.web_server_drivers');

        if (! isset($drivers[$detected])) {
            // Detected but undrivable — sites cannot be created. Deliberate:
            // a config we invented would fail its own test at best and take
            // every site down at worst.
            return [
                'status' => 'fail',
                'detail' => $detected.' is installed but the panel has no driver for it',
                'fix' => 'doctor.fixes.web_server_undrivable',
            ];
        }

        [$binary, $args] = match ($detected) {
            'apache' => ['apachectl', ['configtest']],
            'openlitespeed' => ['lswsctrl', ['status']],
            default => ['nginx', ['-t']],
        };

        $result = Process::timeout(15)->run(array_merge(['sudo', '-n', $binary], $args));

        if (! $result->successful()) {
            return [
                'status' => 'fail',
                'detail' => $detected.' config does not pass '.$binary.' '.implode(' ', $args),
                'fix' => 'doctor.fixes.web_server_config',
            ];
        }

        return [
            'status' => 'pass',
            'detail' => $detected.', config valid',
            'fix' => null,
        ];
    }

    /**
     * By the directories install.sh looks for, so the panel and the installer
     * agree on what "nginx is installed" means.
     */
    private function detect(): ?string
    {
        foreach ((array) config('server.web_servers') as $name => $paths) {
            foreach ((array) $paths as $path) {
                if (is_dir($path)) {
                    return $name;
                }
            }
        }

        return null;
    }
}
