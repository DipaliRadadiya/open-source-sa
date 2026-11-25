<?php

namespace App\Services\Server\Setup\Components;

use App\Contracts\SetupComponent;
use App\Services\Server\Capabilities\ServerCapabilities;
use App\Services\Server\ServerOps;

/**
 * Docker, the engine a Docker-stack server exists to run.
 *
 * Also listed on any other stack that has Docker installed.
 *
 * Reported, not installed: `action()` is null, since the installer owns Docker's apt
 * repository and daemon configuration. Not recommended, like `wp_cli`: a new
 * recommended row would mark every existing server's setup unfinished.
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
     * The Docker stack always lists it, even when Docker has gone missing (the case the
     * row exists to show); other stacks only when the engine is there.
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
     * The engine's version, from the `docker info` probe shared with `panel:doctor`.
     * Read from the daemon, not the client binary, so a stopped daemon shows no version.
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
