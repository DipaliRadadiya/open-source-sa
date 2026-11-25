<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * The `?sort=` parameter for a paginated list.
 *
 * `sort=name` ascending, `sort=-name` descending (the JSON:API convention).
 *
 *  - **The column never comes from the request.** It comes from the endpoint's whitelist; the
 *    request only selects one. An `orderBy` built from user input is SQL injection.
 *  - **An unknown column is a 422, not a silent default**, so a wrong order is never read as
 *    the requested one.
 *
 * A secondary sort on the primary key is always appended: with ties the database may return
 * rows in any order, so under pagination a row can appear on two pages and another on none.
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
     * @param  array<int, string>  $nullsSmallest  columns whose NULLs must sort below every value
     * @param  array<int, string>  $caseInsensitive  text columns compared without regard to letter case
     */
    public static function apply(
        Builder $query,
        ?string $sort,
        array $columns,
        string $defaultDirection = 'desc',
        array $nullsSmallest = [],
        array $caseInsensitive = [],
    ): Builder {
        $requested = trim((string) $sort);

        $descending = str_starts_with($requested, '-');
        $column = ltrim($requested, '-');

        // Belt and braces: this class puts a string into an ORDER BY, so it re-checks the
        // whitelist instead of trusting the caller's validation.
        if (! in_array($column, $columns, true)) {
            $column = $columns[0];
            $descending = $defaultDirection === 'desc';
        }

        // Where NULL sorts differs per database (SQLite/MySQL first ascending, PostgreSQL
        // last), so columns with a natural place for "unknown" say so explicitly.
        // Built from the whitelisted column name, never from the request.
        if (in_array($column, $nullsSmallest, true)) {
            $query->orderByRaw(
                $query->getGrammar()->wrap($column).' is null '.($descending ? 'asc' : 'desc')
            );
        }

        $direction = $descending ? 'desc' : 'asc';

        // Raw ordering can sort `Banana` before `apple` (SQLite default). LOWER works on SQLite,
        // MySQL/MariaDB and PostgreSQL, unlike collation syntax. Column is whitelisted.
        if (in_array($column, $caseInsensitive, true)) {
            $query->orderByRaw('lower('.$query->getGrammar()->wrap($column).') '.$direction);
        } else {
            $query->orderBy($column, $direction);
        }

        return $query->orderBy($query->getModel()->getQualifiedKeyName(), 'desc');
    }

    /**
     * Order an internally-selected text column without case bias.
     *
     * Unlike {@see apply()}, for fixed backend ordering, not a client `?sort=` value: callers
     * must pass a column declared in code, never request input.
     */
    public static function caseInsensitive(Builder $query, string $column, string $direction = 'asc'): Builder
    {
        $direction = strtolower($direction);

        if (! in_array($direction, ['asc', 'desc'], true)) {
            $direction = 'asc';
        }

        return $query
            ->orderByRaw('lower('.$query->getGrammar()->wrap($column).') '.$direction)
            ->orderBy($query->getModel()->getQualifiedKeyName(), 'desc');
    }
}
