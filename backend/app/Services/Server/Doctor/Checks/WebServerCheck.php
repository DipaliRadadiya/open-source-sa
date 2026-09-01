<?php

namespace App\Services\Server\Doctor\Checks;

use App\Contracts\DoctorCheck;
use App\Services\Server\Capabilities\ServerCapabilities;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

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
 *
 * There is a third answer, and leaving it out is what made this check lie: the
 * panel may not be *allowed* to run the config test. That is a warning about
 * this check, not a verdict on the config — {@see PrivilegeCheck} is where the
 * missing grant belongs, and it reports it.
 *
 * And a fourth, which this check used to be blind to. Everything above asks
 * the *box* what is installed. The panel does not use that answer — it uses
 * `ServerCapability.web_server`, recorded at install time. When the two
 * disagree the box is fine, the config test passes, and doctor reported a
 * clean bill of health on a server where every site creation was writing an
 * Apache vhost into a directory that did not exist. A check that reads a
 * different value from the code it is checking is not checking that code.
 */
class WebServerCheck implements DoctorCheck
{
    public function __construct(private ServerCapabilities $capabilities) {}

    public function key(): string
    {
        return 'web_server';
    }

    public function run(): array
    {
        // Before anything else, because it can repair itself: a record the box
        // flatly contradicts is corrected here rather than left for an operator
        // to fix with a command they would first have to be told about.
        $this->capabilities->reconcileWebServer();

        $detected = $this->detect();

        $recorded = $this->capabilities->recordedWebServer();

        if ($recorded !== null && $detected !== null && $recorded !== $detected) {
            return [
                'status' => 'fail',
                'detail' => "the panel is configured for {$recorded} but this server runs {$detected}"
                    ." — every site the panel writes goes to {$recorded}'s directories",
                'fix' => 'doctor.fixes.web_server_mismatch',
            ];
        }

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

        $test = $binary.' '.implode(' ', $args);
        $result = Process::timeout(15)->run(array_merge(['sudo', '-n', $binary], $args));

        if (! $result->successful()) {
            // A non-zero exit is two different findings wearing one face. sudo
            // refuses before the binary runs, and the refusal exits non-zero
            // exactly like a failed config test — so on a box with no sudoers
            // grant this reported "the web server configuration is invalid"
            // while `nginx -t` passed and every vhost was fine. Doctor is what
            // you run when something is already broken; inventing a second,
            // false problem out of the first sends the reader in the wrong
            // direction at the worst moment.
            if (! $this->permitted($binary)) {
                return [
                    'status' => 'warn',
                    'detail' => 'not permitted to run '.$test.', so the configuration was not tested',
                    'fix' => 'doctor.fixes.web_server_untestable',
                ];
            }

            return [
                'status' => 'fail',
                'detail' => trim($detected.' config does not pass '.$test.': '.$this->firstLine($result->errorOutput() ?: $result->output())),
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
     * May this panel run that command at all?
     *
     * Asked of sudo itself rather than by reading the failed command's stderr,
     * for the same reason {@see PrivilegeCheck} asks it: `sudo -n -l <binary>`
     * resolves the real sudoers rule for the real user and changes nothing,
     * whereas sudo's refusals are prose ("a password is required", "Sorry,
     * user … is not allowed to execute"), differ between refusal kinds and are
     * translated on a localized host. A diagnostic that mistakes one failure
     * for another must not be built on string matching.
     *
     * Only ever reached after the test has already failed, so the extra
     * process costs nothing on a healthy server.
     */
    private function permitted(string $binary): bool
    {
        return Process::timeout(10)->run(['sudo', '-n', '-l', $binary])->successful();
    }

    /**
     * The one line of the config test worth putting in front of an operator.
     *
     * `nginx -t` names the file and the line it choked on; without it this
     * check asserted the configuration was invalid and offered nothing to back
     * the claim up.
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
