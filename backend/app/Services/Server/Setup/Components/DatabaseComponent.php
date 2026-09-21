<?php

namespace App\Services\Server\Setup\Components;

use App\Contracts\SetupComponent;
use App\Services\Server\Databases\DatabaseManager;
use App\Services\Server\Databases\Installers\EngineInstallerManager;

/**
 * The database engine — the one pick-one row, and the only thing most people will
 * need before their first site. WordPress and the rest of the marketplace cannot
 * install without one.
 */
class DatabaseComponent implements SetupComponent
{
    public function __construct(
        private DatabaseManager $databases,
        private EngineInstallerManager $installers,
    ) {}

    public function key(): string
    {
        return 'database';
    }

    /**
     * Any engine running counts. They are mutually exclusive on 3306, so "which
     * one" is a detail of the row rather than a second question.
     */
    public function installed(): bool
    {
        return collect($this->databases->detectedVersions())->contains(fn (?string $version) => $version !== null);
    }

    public function recommended(): bool
    {
        return true;
    }

    public function detail(): ?string
    {
        $versions = collect($this->databases->detectedVersions())->filter(fn (?string $version) => $version !== null);

        if ($versions->isEmpty()) {
            return null;
        }

        $engine = (string) $versions->keys()->first();

        return trim(((string) config("server.databases.engines.{$engine}.label")).' '.((string) $versions->first()));
    }

    public function action(): ?array
    {
        // The engine is chosen, so the endpoint is per-option rather than one
        // button. See options().
        return null;
    }

    /**
     * One entry per engine, with its own endpoint. MongoDB comes back
     * `installable: false` — it is operable but has no installer yet (it needs its
     * own apt repository), and saying so beats a button that cannot work.
     */
    public function options(): array
    {
        return array_map(function (string $name) {
            $version = $this->databases->detectedVersions()[$name] ?? null;
            $installable = $this->installers->canInstall($name);

            return [
                'value' => $name,
                'label' => (string) config("server.databases.engines.{$name}.label"),
                'installed' => $version !== null,
                'version' => $version,
                'installable' => $installable,
                // MariaDB first, and pre-selected: it is what Ubuntu packages
                // directly, so there is no third-party repository to add and no
                // version that falls out of support with the release.
                'recommended' => $name === 'mariadb',
                'action' => $installable
                    ? ['method' => 'POST', 'endpoint' => "/api/databases/engines/{$name}"]
                    : null,
            ];
        }, $this->databases->engineNames());
    }
}
