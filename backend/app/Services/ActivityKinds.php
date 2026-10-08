<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * What kind of thing an activity row records — something made, changed,
 * removed, or an attempt that failed (FS-C15) — and whether it is a
 * security event (OLD-18).
 *
 * Read from the action's name, which already says it: every failure ends in
 * `failed`, every removal in `deleted`/`removed`/… The filter turns a kind
 * into the exact action names of the catalog, so the query stays an exact
 * match on the indexed `action` column rather than a LIKE.
 */
class ActivityKinds
{
    public const KINDS = ['created', 'changed', 'removed', 'failed'];

    private const PATTERNS = [
        // Checked in this order: `install_failed` is a failure, not an install.
        'failed' => '/(^|_)failed(_unknown)?$/',
        // `(^|_)` keeps `uninstalled` out of `installed` and `disconnected`
        // out of `connected`.
        'removed' => '/(^|_)(deleted|removed|uninstalled|detached|disconnected|emptied)$/',
        'created' => '/(^|_)(created|registered|added|installed|imported|attached|connected|provisioned|cloned|issued|uploaded|exported|adopted)$/',
    ];

    public function __construct(private ActivityCatalog $catalog) {}

    public function of(string $action): string
    {
        foreach (self::PATTERNS as $kind => $pattern) {
            if (preg_match($pattern, $action) === 1) {
                return $kind;
            }
        }

        return 'changed';
    }

    public function apply(Builder $query, string $kind): Builder
    {
        $actions = $this->catalog->keys()
            ->map(fn (string $key) => Str::after($key, '.'))
            ->unique();

        if ($kind === 'changed') {
            return $query->whereNotIn('action', $actions->reject(fn (string $a) => $this->of($a) === 'changed')->values()->all());
        }

        return $query->whereIn('action', $actions->filter(fn (string $a) => $this->of($a) === $kind)->values()->all());
    }

    public function isSecurity(string $type, string $action): bool
    {
        return in_array("{$type}.{$action}", $this->securityKeys(), true);
    }

    public function applySecurity(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            foreach ($this->securityKeys() as $key) {
                $query->orWhere(fn (Builder $q) => $q
                    ->where('type', Str::before($key, '.'))
                    ->where('action', Str::after($key, '.')));
            }
        });
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function options(): array
    {
        return array_map(fn (string $kind) => [
            'value' => $kind,
            'label' => __("activity_kind.{$kind}"),
        ], self::KINDS);
    }

    /**
     * @return array<int, string>
     */
    private function securityKeys(): array
    {
        return (array) config('activity.security', []);
    }
}
