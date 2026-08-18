<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * The `?sort=` parameter for a paginated list.
 *
 * `sort=name` ascending, `sort=-name` descending — the JSON:API convention the
 * API standard names, and the one the frontend's table headers map onto
 * directly.
 *
 * Two properties this exists to guarantee, in one place rather than five:
 *
 *  - **The column never comes from the request.** It comes from the whitelist
 *    the endpoint declares; the request only selects one of them. An `orderBy`
 *    built from user input is a SQL injection with extra steps, and no amount
 *    of escaping makes an arbitrary column name safe.
 *  - **An unknown column is a 422, not a silent default.** Sorting by something
 *    the endpoint does not offer and quietly getting `id` back looks exactly
 *    like a sort that worked, so the user reads the wrong order as the real one.
 *
 * A secondary sort on the primary key is always appended. Ordering by a column
 * with ties — a status, a name two rows share — leaves the database free to
 * return those rows in any order it likes, and it need not pick the same one
 * twice. Under pagination that means a row can appear on two pages and another
 * on none, which reads as data loss and is impossible to reproduce on demand.
 */
class ListSort
{
    /**
     * Validation rule accepting each column in both directions.
     *
     * @param  array<int, string>  $columns
     * @return array<int, mixed>
     */
    public static function rule(array $columns): array
    {
        $accepted = [];

        foreach ($columns as $column) {
            $accepted[] = $column;
            $accepted[] = '-'.$column;
        }

        return ['sometimes', 'nullable', 'string', Rule::in($accepted)];
    }

    /**
     * @param  array<int, string>  $columns  the whitelist, first entry used when nothing is asked for
     */
    public static function apply(Builder $query, ?string $sort, array $columns, string $defaultDirection = 'desc'): Builder
    {
        $requested = trim((string) $sort);

        $descending = str_starts_with($requested, '-');
        $column = ltrim($requested, '-');

        // Belt and braces. The FormRequest has already refused anything not on
        // the list, but this class is what puts a string into an ORDER BY, so
        // it checks its own precondition rather than trusting that every future
        // caller remembered to validate first.
        if (! in_array($column, $columns, true)) {
            $column = $columns[0];
            $descending = $defaultDirection === 'desc';
        }

        return $query
            ->orderBy($column, $descending ? 'desc' : 'asc')
            ->orderBy($query->getModel()->getQualifiedKeyName(), 'desc');
    }
}
