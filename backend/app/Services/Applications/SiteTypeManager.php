<?php

namespace App\Services\Applications;

use App\Contracts\SiteType;
use App\Services\Server\Applications\InstallerManager;
use App\Services\Server\Capabilities\ServerCapabilities;
use App\Services\Server\Databases\DatabaseManager;

/**
 * The application catalog — resolves site types and describes them for the
 * card grid.
 *
 * Availability is decided by what the server can actually run, the same rule
 * Services and Databases already follow. Unavailable types are still returned,
 * flagged and greyed rather than hidden: a card the user can't use yet is how
 * they discover the runtime exists, and it becomes the install button once the
 * runtime-install feature ships.
 */
class SiteTypeManager
{
    public function __construct(
        private ServerCapabilities $capabilities,
        private InstallerManager $installers,
        private DatabaseManager $databases,
    ) {}

    /**
     * @return array<int, SiteType>
     */
    public function all(): array
    {
        return array_map(fn (string $class) => app($class), (array) config('server.site_types', []));
    }

    public function find(string $name): ?SiteType
    {
        foreach ($this->all() as $type) {
            if ($type->name() === $name) {
                return $type;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    public function names(): array
    {
        return array_map(fn (SiteType $type) => $type->name(), $this->all());
    }

    /**
     * The card grid: every type, with its field schema and whether this server
     * can run it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function catalog(): array
    {
        return array_map(function (SiteType $type) {
            $profile = $type->servingProfile();
            $blocked = $this->unavailable($type);
            $available = $blocked === null;

            return [
                'name' => $type->name(),
                'title' => __("application.types.{$type->name()}.title"),
                'tagline' => __("application.types.{$type->name()}.tagline"),
                'icon' => $type->icon(),
                'category' => $type->category(),
                'popular' => $type->popular(),
                // Internal: how we build it. The user is never asked to choose.
                'method' => $type->method(),
                'serving_profile' => $profile,
                'needs_database' => $type->needsDatabase(),
                'available' => $available,
                'unavailable_reason' => $blocked['reason'] ?? null,
                // The runtime that would have to be installed to make this card
                // usable, so an unavailable card can offer to fix itself rather
                // than just being greyed out. Null when the card is available.
                //
                // Named for what it holds. It was `installable`, which read as
                // "this app installs itself" — a different question, answered
                // by `has_installer` below, and one the grid genuinely needs
                // in order to tell "click and get WordPress" apart from "click
                // and get an empty directory".
                // Null when nothing installable would fix it — a type this web
                // server does not support is not an "install a runtime" prompt.
                'installable_runtime' => $blocked['runtime'] ?? null,
                'has_installer' => $this->installers->installerForType($type->name()) !== null,
                // So the version picker can offer only what this application
                // runs on. The create endpoint refuses the rest either way —
                // this is here so the form does not present a choice that is
                // going to be rejected, the same rule the card grid follows by
                // reporting `available` instead of failing at submit.
                'node_version_range' => $type->supportedNodeRange(),
                'fields' => $type->fields(),
            ];
        }, $this->all());
    }

    /**
     * Why this server cannot offer a site type, or null when it can.
     *
     * One method rather than two checks, because the card grid and the create
     * endpoint both need this answer and must not be able to disagree — a card
     * shown as available that then fails validation is worse than either.
     *
     * @return array{reason: string, runtime: ?string}|null
     */
    public function unavailable(SiteType $type): ?array
    {
        $runtime = $this->requiredRuntime($type->servingProfile());

        if ($runtime !== null && ! $this->capabilities->supports($runtime)) {
            return ['reason' => __("application.unavailable.{$runtime}"), 'runtime' => $runtime];
        }

        $missing = $this->missingEngines($type);

        if ($missing !== null) {
            return [
                'reason' => __('application.unavailable.database', ['engines' => $missing]),
                'runtime' => null,
            ];
        }

        $webServer = (string) $this->capabilities->webServer();
        $allowed = (array) config("server.web_server_drivers.{$webServer}.site_types", []);

        // An empty list means no restriction, which is the case for nginx and
        // Apache: they serve everything we ship. A web server lists types only
        // when it supports some and not others.
        if ($allowed !== [] && ! in_array($type->name(), $allowed, true)) {
            return [
                'reason' => __('application.unavailable.web_server', [
                    'web_server' => (string) config("server.web_server_drivers.{$webServer}.label", $webServer),
                ]),
                // Nothing to install would fix this, so the card must not
                // offer to fix itself.
                'runtime' => null,
            ];
        }

        return null;
    }

    /**
     * The engines this type needs and this server hasn't got, as a readable
     * list — or null when it needs none, or has one.
     *
     * Only asked of applications that need something **other** than the usual
     * MySQL/MariaDB. NodeBB is why: it takes MongoDB alone, so on a MySQL-only
     * server it has to be greyed rather than offered and then failed with a
     * message telling the user to install MySQL.
     *
     * The ordinary case is deliberately left alone. An application that wants
     * MySQL on a server with no database engine already fails at
     * `create_database` with an accurate message, before anything is
     * downloaded — and greying ten cards on a server whose engine simply is
     * not up yet would hide the catalog rather than explain it.
     */
    private function missingEngines(SiteType $type): ?string
    {
        $installer = $this->installers->installerForType($type->name());

        if ($installer === null || ! $installer->needsDatabase()) {
            return null;
        }

        $accepted = $installer->acceptedEngines();

        if (array_intersect($accepted, ['mysql', 'mariadb']) !== []) {
            return null;
        }

        $installed = $this->databases->engineNames();

        foreach ($accepted as $engine) {
            if (in_array($engine, $installed, true) && $this->databases->engine($engine)->available()) {
                return null;
            }
        }

        return implode(' / ', array_map(
            fn (string $engine) => (string) config("server.databases.engines.{$engine}.label", $engine),
            $accepted,
        ));
    }

    /**
     * Which runtime a serving profile needs — null when it needs none, so
     * static sites and reverse proxies work on any server.
     */
    public function requiredRuntime(string $profile): ?string
    {
        return match ($profile) {
            'php' => 'php',
            'node' => 'node',
            default => null,
        };
    }
}
