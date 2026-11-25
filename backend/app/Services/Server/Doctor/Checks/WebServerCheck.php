<?php

namespace App\Services\Server\Doctor\Checks;

use App\Contracts\DoctorCheck;
use App\Services\Server\Capabilities\ServerCapabilities;
use App\Services\Server\WebServers\OlsConfigCheck;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Is there a web server the panel can write config for, and is that config currently valid?
 *
 * Failures it reports:
 *  - No driver for the installed web server: every site creation is refused.
 *  - A config that fails its own test: the next reload takes every site down.
 *  - The panel is not allowed to run the config test: a warning about this check, not a
 *    verdict on the config ({@see PrivilegeCheck} reports the missing grant).
 *  - The box and `ServerCapability.web_server` (recorded at install, and what the panel
 *    actually uses) disagree.
 */
class WebServerCheck implements DoctorCheck
{
    public function __construct(
        private ServerCapabilities $capabilities,
        private OlsConfigCheck $olsConfig,
    ) {}

    public function key(): string
    {
        return 'web_server';
    }

    public function run(): array
    {
        // First: a record the box flatly contradicts is corrected here.
        $this->capabilities->reconcileWebServer();

        $detected = $this->detect();

        $recorded = $this->capabilities->recordedWebServer();

        if ($recorded !== null && $detected !== null && $recorded !== $detected) {
            return [
                'status' => 'fail',
                'detail' => __('doctor.details.web_mismatch', ['recorded' => $recorded, 'detected' => $detected]),
                'fix' => 'doctor.fixes.web_server_mismatch',
            ];
        }

        if ($detected === null) {
            return [
                'status' => 'fail',
                'detail' => __('doctor.details.web_none', ['servers' => implode(', ', array_keys((array) config('server.web_servers')))]),
                'fix' => 'doctor.fixes.web_server_missing',
            ];
        }

        $drivers = (array) config('server.web_server_drivers');

        if (! isset($drivers[$detected])) {
            // Detected but undrivable: sites cannot be created, deliberately (an invented
            // config could take every site down).
            return [
                'status' => 'fail',
                'detail' => __('doctor.details.web_undrivable', ['server' => $detected]),
                'fix' => 'doctor.fixes.web_server_undrivable',
            ];
        }

        // OpenLiteSpeed: test the config (not `lswsctrl status`, which only says it runs).
        // It goes through OlsConfigCheck, which creates a directory the command needs first.
        if ($detected === 'openlitespeed') {
            return $this->openLiteSpeed();
        }

        [$binary, $args] = match ($detected) {
            'apache' => ['apachectl', ['configtest']],
            default => ['nginx', ['-t']],
        };

        $test = $binary.' '.implode(' ', $args);
        $result = Process::timeout(15)->run(array_merge(['sudo', '-n', $binary], $args));

        if (! $result->successful()) {
            // A non-zero exit can also be sudo refusing before the binary runs; without a
            // sudoers grant that must not be reported as an invalid configuration.
            if (! $this->permitted($binary)) {
                return [
                    'status' => 'warn',
                    'detail' => __('doctor.details.web_untestable', ['command' => $test]),
                    'fix' => 'doctor.fixes.web_server_untestable',
                ];
            }

            return [
                'status' => 'fail',
                'detail' => trim(__('doctor.details.web_invalid', ['server' => $detected, 'command' => $test, 'error' => $this->firstLine($result->errorOutput() ?: $result->output())])),
                'fix' => 'doctor.fixes.web_server_config',
            ];
        }

        return [
            'status' => 'pass',
            'detail' => __('doctor.details.web_ok', ['server' => $detected]),
            'fix' => null,
        ];
    }

    /**
     * OpenLiteSpeed's config test, with the preparation it cannot do itself.
     *
     * Same answers and order as the branch above: a missing sudoers grant is a gap in this
     * check, not a verdict on the configuration.
     *
     * @return array<string, mixed>
     */
    private function openLiteSpeed(): array
    {
        $binary = (string) (config('server.web_server_drivers.openlitespeed.test_command')[0] ?? '');
        $test = $binary.' -t';

        $result = $this->olsConfig->run(['op' => 'doctor_config_test']);

        if (! $result->failed()) {
            return [
                'status' => 'pass',
                'detail' => __('doctor.details.web_ok', ['server' => 'openlitespeed']),
                'fix' => null,
            ];
        }

        if (! $this->permitted($binary)) {
            return [
                'status' => 'warn',
                'detail' => __('doctor.details.web_untestable', ['command' => $test]),
                'fix' => 'doctor.fixes.web_server_untestable',
            ];
        }

        // OpenLiteSpeed prints its test log on stdout, so the reason is there, not on stderr.
        return [
            'status' => 'fail',
            'detail' => trim(__('doctor.details.web_invalid', ['server' => 'openlitespeed', 'command' => $test, 'error' => $this->firstLine($result->output() ?: $result->errorOutput())])),
            'fix' => 'doctor.fixes.web_server_config',
        ];
    }

    /**
     * May this panel run that command at all?
     *
     * Asked of sudo itself (`sudo -n -l <binary>`, as {@see PrivilegeCheck} does), not by
     * matching the failed command's stderr: sudo's refusals are prose and may be localized.
     * Only reached after the test has failed.
     */
    private function permitted(string $binary): bool
    {
        return Process::timeout(10)->run(['sudo', '-n', '-l', $binary])->successful();
    }

    /**
     * The one line of the config test worth showing: `nginx -t` names the file and line.
     */
    private function firstLine(string $output): string
    {
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (trim($line) !== '') {
                return Str::limit(trim($line), 200);
            }
        }

        return '';
    }

    /**
     * The same detection the panel itself uses, not a second copy.
     *
     * A directory walk is wrong: `/etc/apache2` is shipped by `phpX.Y-fpm`, so it reported a
     * mismatch on healthy OpenLiteSpeed boxes. A running unit beats a leftover directory.
     */
    private function detect(): ?string
    {
        return $this->capabilities->detectWebServer();
    }
}
