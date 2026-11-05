<?php

namespace App\Services\Server\Setup\Components;

use App\Contracts\SetupComponent;
use App\Services\Server\Capabilities\ServerCapabilities;
use App\Services\Server\ServerOps;

/**
 * Docker, the engine a Docker-stack server exists to run.
 *
 * The installer puts it in and everything on that stack depends on it, yet the
 * page listed Redis and fail2ban and not the one thing the box is for (RC-34).
 * It is also listed on any other stack that happens to have Docker installed,
 * because the same engine runs the containers there too.
 *
 * Reported, not installed: `action()` is null. The installer owns Docker's apt
 * repository and daemon configuration, and there is no panel endpoint that
 * does either. Not recommended for the same reason `wp_cli` is not: adding a
 * row to the recommended set would mark every existing server's setup
 * unfinished overnight.
 */
class DockerComponent implements SetupComponent
{
    private ?bool $present = null;

    private ?string $version = null;

    private bool $versionRead = false;

    public function __construct(
        private ServerOps $serverOps,
        private ServerCapabilities $capabilities,
    ) {}

    /**
     * The Docker stack always lists it, even when Docker has gone missing —
     * that is the case the row exists to show. Every other stack lists it only
     * when the engine is really there.
     */
    public function applies(): bool
    {
        return $this->capabilities->recordedStack() === 'docker' || $this->present();
    }

    public function key(): string
    {
        return 'docker';
    }

    public function installed(): bool
    {
        return $this->present();
    }

    public function recommended(): bool
    {
        return false;
    }

    /**
     * The engine's own version, from the same `docker info` probe that
     * `panel:doctor` and the container supervisor use. Asked of the daemon
     * rather than the client binary: a stopped daemon then reads as "no
     * version" instead of a version for something that cannot run a container.
     */
    public function detail(): ?string
    {
        if (! $this->present()) {
            return null;
        }

        if (! $this->versionRead) {
            $this->versionRead = true;

            $result = $this->serverOps->run(
                ['docker', 'info', '--format', '{{.ServerVersion}}'],
                ['feature' => 'setup', 'op' => 'docker_version'],
                timeout: 20,
            );

            $version = trim($result->output());
            $this->version = $result->answered && $version !== '' ? $version : null;
        }

        return $this->version === null ? null : 'Docker '.$this->version;
    }

    public function action(): ?array
    {
        return null;
    }

    public function options(): array
    {
        return [];
    }

    private function present(): bool
    {
        return $this->present ??= $this->serverOps->binaryExists('docker');
    }
}
