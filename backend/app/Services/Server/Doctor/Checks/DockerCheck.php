<?php

namespace App\Services\Server\Doctor\Checks;

use App\Contracts\DoctorCheck;
use App\Services\Server\ServerOps;

/**
 * Is Docker installed, running, and usable by the panel?
 *
 * Three separate questions that fail in three different ways, and the Services
 * screen can only answer the second. A daemon that is `active` still refuses
 * every command if the panel may not reach its socket, and that refusal
 * arrives as a permission error on a feature the user has just been shown as
 * working.
 *
 * Deliberately `docker info` rather than `docker --version`: the version reads
 * straight off the client binary and succeeds with the daemon stopped, so it
 * answers "is the package installed" while appearing to answer "does Docker
 * work". `info` talks to the daemon.
 */
class DockerCheck implements DoctorCheck
{
    public function __construct(private ServerOps $serverOps) {}

    public function key(): string
    {
        return 'docker';
    }

    public function run(): array
    {
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

        $stderr = strtolower($result->errorOutput());

        // Distinguished because the advice is completely different, and the
        // generic "Docker is not working" sends people to reinstall a daemon
        // that is running perfectly well.
        if (str_contains($stderr, 'permission denied')) {
            return [
                'status' => 'fail',
                'detail' => 'the daemon is reachable but refused the panel: '.trim($result->errorOutput()),
                'fix' => 'doctor.fixes.docker_denied',
            ];
        }

        if (str_contains($stderr, 'not found') || str_contains($stderr, 'command not found')) {
            return [
                'status' => 'warn',
                'detail' => 'docker is not installed on this server',
                'fix' => 'doctor.fixes.docker_missing',
            ];
        }

        // Installed, not answering. Almost always a stopped daemon; reported
        // as a failure rather than a warning because nothing containerised can
        // run and the panel would otherwise show containers as merely absent.
        return [
            'status' => 'fail',
            'detail' => 'docker is installed but the daemon did not answer'
                .($result->reference !== '' ? ' (reference '.$result->reference.')' : ''),
            'fix' => 'doctor.fixes.docker_down',
        ];
    }
}
