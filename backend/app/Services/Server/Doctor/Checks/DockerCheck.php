<?php

namespace App\Services\Server\Doctor\Checks;

use App\Contracts\DoctorCheck;
use App\Services\Server\Capabilities\ServerCapabilities;
use App\Services\Server\ServerOps;

/**
 * Is Docker installed, running, and usable by the panel?
 *
 * Four separate questions that fail in four different ways, and the Services
 * screen can only answer the second. A daemon that is `active` still refuses
 * every command if the panel may not reach its socket, and that refusal
 * arrives as a permission error on a feature the user has just been shown as
 * working.
 *
 * Deliberately `docker info` rather than `docker --version`: the version reads
 * straight off the client binary and succeeds with the daemon stopped, so it
 * answers "is the package installed" while appearing to answer "does Docker
 * work". `info` talks to the daemon.
 *
 * **Presence is asked first, and without sudo.** That ordering is the whole
 * correctness of this check, not a tidy-up. `ServerOps` puts `sudo -n` in front
 * of `docker`, and sudo matches its NOPASSWD rules on the resolved absolute
 * path — so on a box with no Docker there is nothing to resolve, no rule
 * matches, and sudo answers
 *
 *     sudo: a password is required
 *
 * An absent binary and a stale sudo grant are therefore the *same string*.
 * Matching on "not found" could never see the first one, so every lemp, lamp,
 * mern and ols install ended with a red
 *
 *     ✗ Docker   docker is installed but the daemon did not answer
 *
 * on a server that had never had Docker on it — install.sh runs `panel:doctor`
 * last, so that was the final thing every install printed. The structured
 * `$denied` flag is read instead of the prose, and only after presence is
 * known, so the two causes can no longer be confused.
 *
 * Docker missing is reported as a **pass** on every other stack. It is not a
 * warning: nothing is wrong, nothing is degraded, and nothing needs doing.
 * `DockerExposureCheck` already answers "no reachable daemon — nothing to
 * expose" the same way. A yellow line on four stacks out of five is how an
 * operator learns to stop reading this report.
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

        // Read before any stderr matching. `denied` is sudo's own refusal,
        // structured, and it is the one failure where nothing on the server is
        // broken — the panel is missing a line in /etc/sudoers.d, which
        // `panel:sudoers` writes in full. Reported apart from the socket
        // refusal below because the two fixes are unrelated and the socket one
        // tells people to touch the `docker` group, which is root on this box.
        if ($result->denied) {
            return [
                'status' => 'fail',
                'detail' => "the panel's sudo grant does not cover docker"
                    .($result->reference !== '' ? ' (reference '.$result->reference.')' : ''),
                'fix' => 'doctor.fixes.docker_sudo',
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

        // Reachable only by a race — the package was removed between the
        // presence probe above and this call. Kept because the alternative is
        // reporting a uninstall-in-progress as a dead daemon.
        if (str_contains($stderr, 'not found')) {
            return $this->notInstalled();
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

    /**
     * Docker is not on this box.
     *
     * Only a finding when the installer recorded the `docker` stack, where it is
     * the one component the box exists to run. An un-recorded stack (null) takes
     * the benign branch deliberately: a box nobody told us about is far more
     * likely to be one this panel did not build than a Docker host.
     *
     * @return array{status: string, detail: string, fix: string|null}
     */
    private function notInstalled(): array
    {
        if ($this->capabilities->recordedStack() === 'docker') {
            return [
                'status' => 'warn',
                'detail' => 'docker is not installed, but this server was built as a Docker stack',
                'fix' => 'doctor.fixes.docker_missing',
            ];
        }

        return [
            'status' => 'pass',
            'detail' => 'Docker is not installed (optional)',
            'fix' => null,
        ];
    }
}
