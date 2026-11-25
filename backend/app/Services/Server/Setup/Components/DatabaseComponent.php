<?php

namespace App\Services\Server\Setup\Components;

use App\Contracts\SetupComponent;
use App\Services\Server\Capabilities\ServerCapabilities;
use App\Services\Server\Databases\DatabaseManager;
use App\Services\Server\Databases\Installers\EngineInstallerManager;

/** The database engine: the one pick-one row, needed before WordPress and most marketplace apps. */
class DatabaseComponent implements SetupComponent
{
    public function __construct(
        private DatabaseManager $databases,
        private EngineInstallerManager $installers,
        private ServerCapabilities $capabilities,
    ) {}

    /** Only where the box hosts something that could use one (a container-only server has no engine). */
    public function applies(): bool
    {
        return $this->capabilities->hosts('php') || $this->capabilities->hosts('node');
    }

    public function key(): string
    {
        return 'database';
    }

    /** Any engine running counts; they are mutually exclusive on 3306. */
    public function installed(): bool
    {
        return collect($this->databases->detectedVersions())->contains(fn (?string $version) => $version !== null);
    }

    /**
     * Only where something the box hosts could use one (the panel's own data is SQLite).
     * Asked of the hosted profiles, not the stack name, so a new stack needs no edit here.
     */
    public function recommended(): bool
    {
        foreach (['php', 'node'] as $profile) {
            if ($this->capabilities->hosts($profile)) {
                return true;
            }
        }

        return false;
    }

    public function detail(): ?string
    {
        $versions = collect($this->databases->detectedVersions())->filter(fn (?string $version) => $version !== null);

        if ($versions->isEmpty()) {
            return null;
        }

        $engine = (string) $versions->keys()->first();
        $version = (string) $versions->first();

        // "MariaDB 10.11.14", not the server's own `10.11.14-MariaDB-0ubuntu0.24.04.1`.
        if (preg_match('/^\d+(\.\d+){0,2}/', $version, $short) === 1) {
            $version = $short[0];
        }

        return trim(((string) config("server.databases.engines.{$engine}.label")).' '.$version);
    }

    public function action(): ?array
    {
        // The endpoint is per-option, see options().
        return null;
    }

    /**
     * One entry per engine, with its own endpoint. MongoDB comes back `installable: false`: it
     * has no installer yet (it needs its own apt repository).
     */
    public function options(): array
    {
        $versions = $this->databases->detectedVersions();
        // From the versions already detected: this page is held to one probe each.
        $installed = array_map(fn ($version) => $version !== null, $versions);

        return array_map(function (string $name) use ($versions, $installed) {
            $version = $versions[$name] ?? null;
            // Why an engine is not offered: no vendor build for this release, or MySQL and
            // MariaDB cannot share a server.
            $unavailable = $this->installers->unavailableReason($name)
                ?? $this->installers->conflictReason($name, $installed);
            $installable = $this->installers->canInstall($name) && $unavailable === null;

            return [
                'value' => $name,
                'label' => (string) config("server.databases.engines.{$name}.label"),
                'installed' => $version !== null,
                'version' => $version,
                'installable' => $installable,
                'unavailable' => $unavailable,
                // Pre-selected: Ubuntu packages it directly, so no third-party repository to add.
                'recommended' => $name === 'mariadb',
                'action' => $installable
                    ? ['method' => 'POST', 'endpoint' => "/api/databases/engines/{$name}"]
                    : null,
            ];
        }, $this->databases->engineNames());
    }
}
