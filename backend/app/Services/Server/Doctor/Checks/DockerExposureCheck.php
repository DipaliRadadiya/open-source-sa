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
 * says it is closed*. The screen is not lying on purpose; it reports ufw, and
 * ufw is not what is deciding.
 *
 * That makes this the one check that ships before the panel can create a
 * container at all. Everything the panel generates publishes to `127.0.0.1`
 * and is proxied by nginx, so a finding here is either a container someone
 * started by hand or a bug in our own template — and both need saying out
 * loud, because neither is visible anywhere else in the panel.
 *
 * Reported as a failure, not a warning. An unintended open port is not a
 * degraded feature.
 */
class DockerExposureCheck implements DoctorCheck
{
    /**
     * Addresses that mean "every interface". An empty host field means the
     * same thing — `docker run -p 8080:80` with no address publishes to all of
     * them, and it is the most common way this happens.
     */
    private const WILDCARDS = ['0.0.0.0', '::', '*', ''];

    public function __construct(private ServerOps $serverOps) {}

    public function key(): string
    {
        return 'docker_exposure';
    }

    public function run(): array
    {
        $result = $this->serverOps->run(
            ['docker', 'ps', '--format', '{{.Names}}\t{{.Ports}}'],
            ['feature' => 'doctor', 'op' => 'docker_ps'],
            timeout: 20,
        );

        // Docker absent or down is DockerCheck's business, not this one's. Two
        // checks failing for one cause buries the cause.
        if (! $result->answered) {
            return [
                'status' => 'pass',
                'detail' => 'no reachable Docker daemon — nothing to expose',
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
                'detail' => 'reachable from any address, past the firewall: '.implode(', ', $exposed),
                'fix' => 'doctor.fixes.docker_exposure',
            ];
        }

        return [
            'status' => 'pass',
            'detail' => 'no container publishes to a public address',
            'fix' => null,
        ];
    }

    /**
     * The published ports bound to every interface.
     *
     * `docker ps` renders the mapping as
     * `0.0.0.0:8080->80/tcp, [::]:8080->80/tcp` and a loopback one as
     * `127.0.0.1:8080->80/tcp`. Only the host side of the arrow matters — the
     * container port is internal and says nothing about reachability.
     *
     * @return list<string>
     */
    private function wildcardPorts(string $ports): array
    {
        $found = [];

        foreach (explode(',', $ports) as $mapping) {
            $mapping = trim($mapping);

            if ($mapping === '' || ! str_contains($mapping, '->')) {
                // No arrow means the port is exposed but not published, which
                // is reachable only from other containers. Not a finding.
                continue;
            }

            [$host] = explode('->', $mapping, 2);
            $host = trim($host);

            // Rightmost colon: an IPv6 host is full of them, so splitting on
            // the first would read `[` as the address on every v6 mapping.
            $separator = strrpos($host, ':');
            $address = $separator === false ? '' : substr($host, 0, $separator);

            if (in_array(trim($address, '[]'), self::WILDCARDS, true)) {
                $found[] = $mapping;
            }
        }

        return $found;
    }
}
