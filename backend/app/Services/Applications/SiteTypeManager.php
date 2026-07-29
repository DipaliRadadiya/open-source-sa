<?php

namespace App\Services\Applications;

use App\Contracts\SiteType;
use App\Services\Server\Applications\InstallerManager;
use App\Services\Server\Capabilities\ServerCapabilities;

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
            $runtime = $this->requiredRuntime($profile);
            $available = $runtime === null || $this->capabilities->supports($runtime);

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
                'unavailable_reason' => $available ? null : __("application.unavailable.{$runtime}"),
                // The runtime that would have to be installed to make this card
                // usable, so an unavailable card can offer to fix itself rather
                // than just being greyed out. Null when the card is available.
                //
                // Named for what it holds. It was `installable`, which read as
                // "this app installs itself" — a different question, answered
                // by `has_installer` below, and one the grid genuinely needs
                // in order to tell "click and get WordPress" apart from "click
                // and get an empty directory".
                'installable_runtime' => $available ? null : $runtime,
                'has_installer' => $this->installers->installerForType($type->name()) !== null,
                'fields' => $type->fields(),
            ];
        }, $this->all());
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
