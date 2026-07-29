<?php

namespace App\Services\Applications;

use App\Contracts\SiteType;
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
    public function __construct(private ServerCapabilities $capabilities) {}

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
                // What could be installed to make it available. Null until the
                // runtime-install feature exists; the button appears then with
                // no frontend change.
                'installable' => $available ? null : $runtime,
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
