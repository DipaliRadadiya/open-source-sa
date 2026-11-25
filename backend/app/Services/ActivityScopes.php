<?php

namespace App\Services;

/**
 * Maps an activity `type` to the half of the panel it describes.
 *
 * The map is config so a new feature type declares its scope in one place; a type that belongs to
 * no scope is filtered out of both screens silently.
 */
class ActivityScopes
{
    /**
     * @return array<int, string>
     */
    public function all(): array
    {
        return array_keys($this->map());
    }

    /**
     * The types belonging to a scope, for a `whereIn` on the indexed column.
     *
     * @return array<int, string>
     */
    public function types(string $scope): array
    {
        return array_values((array) ($this->map()[$scope] ?? []));
    }

    /**
     * The scope a type belongs to, or null for a type nobody has classified.
     */
    public function for(string $type): ?string
    {
        foreach ($this->map() as $scope => $types) {
            if (in_array($type, (array) $types, true)) {
                return $scope;
            }
        }

        return null;
    }

    /**
     * Scopes with their translated labels, in the viewer's locale (so the frontend carries no
     * second copy of the names).
     *
     * @param  array<int, string>|null  $only  Limit to these scopes.
     * @return array<int, array{value: string, label: string}>
     */
    public function options(?array $only = null): array
    {
        return collect($this->all())
            ->when($only !== null, fn ($scopes) => $scopes->intersect($only))
            ->map(fn (string $scope) => [
                'value' => $scope,
                'label' => __("activity_scope.{$scope}"),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function map(): array
    {
        return (array) config('activity.scopes', []);
    }
}
