<?php

namespace App\Services\Server\Doctor\Checks;

use App\Contracts\DoctorCheck;
use App\Services\Server\ServerOps;

/**
 * Is any container published to the whole internet?
 *
 * **Docker bypasses the firewall.** It inserts its own rules into the `DOCKER`
 * chain ahead of the filter rules ufw manages, so a port published to
 * `0.0.0.0` is reachable from anywhere *even while the panel's firewall screen
 * says it is closed*. The screen reports ufw, and ufw is not what is deciding.
 *
 * Everything the panel generates publishes to `127.0.0.1` and is proxied by
 * nginx, so a finding is a container started by hand or a bug in our template.
 *
 * Reported as a failure, not a warning: an unintended open port is not a
 * degraded feature.
 */
class DockerExposureCheck implements DoctorCheck
{
    /**
     * Addresses that mean "every interface". An empty host field means the same:
     * `docker run -p 8080:80` publishes to all of them.
     */
    private const WILDCARDS = ['0.0.0.0', '::', '*', ''];

    public function __construct(private ServerOps $serverOps) {}

    public function key(): string
    {
        return 'docker_exposure';
    }

    public function run(): array
    {
        // Presence first, and without sudo: `sudo -n docker` answers "a password is required"
        // whether Docker is absent or ungranted, so the branches below need this check.
        if (! $this->serverOps->binaryExists('docker')) {
            return [
                'status' => 'pass',
                'detail' => __('doctor.details.exposure_no_docker'),
                'fix' => null,
            ];
        }

        $result = $this->serverOps->run(
            ['docker', 'ps', '--format', '{{.Names}}\t{{.Ports}}'],
            ['feature' => 'doctor', 'op' => 'docker_ps'],
            timeout: 20,
        );

        // Docker refused is not Docker absent: passing here would claim no container is
        // exposed on the strength of a command that never ran. Warn: unproven, not proven safe.
        if ($result->denied) {
            return [
                'status' => 'warn',
                'detail' => __('doctor.details.exposure_sudo', ['reference' => $result->reference]),
                'fix' => 'doctor.fixes.docker_sudo',
            ];
        }

        // Docker down is DockerCheck's business; two checks failing for one cause buries it.
        if (! $result->answered) {
            return [
                'status' => 'pass',
                'detail' => __('doctor.details.exposure_no_daemon'),
                'fix' => null,
            ];
        }

        $exposed = [];

        foreach (explode("\n", trim($result->output())) as $line) {
            if ($line === '') {
                continue;
            }

            [$name, $ports] = array_pad(explode("\t", $line, 2), 2, '');

            foreach ($this->wildcardPorts($ports) as $port) {
                $exposed[] = "{$name} → {$port}";
            }
        }

        if ($exposed !== []) {
            return [
                'status' => 'fail',
                'detail' => __('doctor.details.exposure_public', ['ports' => implode(', ', $exposed)]),
                'fix' => 'doctor.fixes.docker_exposure',
            ];
        }

        return [
            'status' => 'pass',
            'detail' => __('doctor.details.exposure_none'),
            'fix' => null,
        ];
    }

    /**
     * The published ports bound to every interface.
     *
     * `docker ps` renders mappings as `0.0.0.0:8080->80/tcp, [::]:8080->80/tcp`. Only the host
     * side of the arrow matters for reachability.
     *
     * @return list<string>
     */
    private function wildcardPorts(string $ports): array
    {
        $found = [];

        foreach (explode(',', $ports) as $mapping) {
            $mapping = trim($mapping);

            if ($mapping === '' || ! str_contains($mapping, '->')) {
                // No arrow: exposed but not published, so only other containers reach it.
                continue;
            }

            [$host] = explode('->', $mapping, 2);
            $host = trim($host);

            // Rightmost colon: an IPv6 host contains many.
            $separator = strrpos($host, ':');
            $address = $separator === false ? '' : substr($host, 0, $separator);

            if (in_array(trim($address, '[]'), self::WILDCARDS, true)) {
                $found[] = $mapping;
            }
        }

        return $found;
    }
}
