<?php

namespace App\Services\Server\Doctor\Checks;

use App\Contracts\DoctorCheck;
use App\Services\Server\Capabilities\ServerCapabilities;
use App\Services\Server\ServerOps;

/**
 * Is Docker installed, running, and usable by the panel?
 *
 * Uses `docker info`, not `docker --version`: the version reads off the client binary and
 * succeeds with the daemon stopped, while `info` talks to the daemon.
 *
 * Presence is asked first, and without sudo. `ServerOps` puts `sudo -n` in front of `docker`,
 * and sudo matches NOPASSWD rules on the resolved path, so with no Docker installed sudo
 * answers "a password is required", the same string as a stale sudo grant. Only after presence
 * is known is the structured `$denied` flag read.
 *
 * Docker missing is a pass on every stack except `docker`: nothing is wrong, and a warning on
 * most installs teaches operators to stop reading this report.
 */
class DockerCheck implements DoctorCheck
{
    public function __construct(
        private ServerOps $serverOps,
        private ServerCapabilities $capabilities,
    ) {}

    public function key(): string
    {
        return 'docker';
    }

    public function run(): array
    {
        if (! $this->serverOps->binaryExists('docker')) {
            return $this->notInstalled();
        }

        $result = $this->serverOps->run(
            ['docker', 'info', '--format', '{{.ServerVersion}}'],
            ['feature' => 'doctor', 'op' => 'docker_info'],
            timeout: 20,
        );

        if ($result->answered && $result->output() !== '') {
            return [
                'status' => 'pass',
                'detail' => 'docker '.trim($result->output()),
                'fix' => null,
            ];
        }

        // Read before any stderr matching. `denied` is sudo's own refusal: the panel is missing
        // a line in /etc/sudoers.d (`panel:sudoers` writes it). Kept apart from the socket
        // refusal below, whose fix touches the `docker` group, which is root-equivalent.
        if ($result->denied) {
            return [
                'status' => 'fail',
                'detail' => $result->reference !== ''
                    ? __('doctor.details.docker_sudo_ref', ['reference' => $result->reference])
                    : __('doctor.details.docker_sudo'),
                'fix' => 'doctor.fixes.docker_sudo',
            ];
        }

        $stderr = strtolower($result->errorOutput());

        // Separate message: the generic "not working" sends people to reinstall a healthy daemon.
        if (str_contains($stderr, 'permission denied')) {
            return [
                'status' => 'fail',
                'detail' => __('doctor.details.docker_refused', ['error' => trim($result->errorOutput())]),
                'fix' => 'doctor.fixes.docker_denied',
            ];
        }

        // Reachable only by a race: the package was removed after the presence probe.
        if (str_contains($stderr, 'not found')) {
            return $this->notInstalled();
        }

        // Installed, not answering: almost always a stopped daemon, so a failure, not a warning.
        return [
            'status' => 'fail',
            'detail' => $result->reference !== ''
                ? __('doctor.details.docker_silent_ref', ['reference' => $result->reference])
                : __('doctor.details.docker_silent'),
            'fix' => 'doctor.fixes.docker_down',
        ];
    }

    /**
     * Docker is not on this box.
     *
     * Only a finding when the installer recorded the `docker` stack. An unrecorded stack (null)
     * takes the benign branch on purpose.
     *
     * @return array{status: string, detail: string, fix: string|null}
     */
    private function notInstalled(): array
    {
        if ($this->capabilities->recordedStack() === 'docker') {
            return [
                'status' => 'warn',
                'detail' => __('doctor.details.docker_missing_stack'),
                'fix' => 'doctor.fixes.docker_missing',
            ];
        }

        return [
            'status' => 'pass',
            'detail' => __('doctor.details.docker_optional'),
            'fix' => null,
        ];
    }
}
