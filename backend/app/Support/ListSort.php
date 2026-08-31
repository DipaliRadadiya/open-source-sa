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

        // Belt and braces. The FormRequest has already refused anything not on
        // the list, but this class is what puts a string into an ORDER BY, so
        // it checks its own precondition rather than trusting that every future
        // caller remembered to validate first.
        if (! in_array($column, $columns, true)) {
            $column = $columns[0];
            $descending = $defaultDirection === 'desc';
        }

        // Where NULL sorts is a per-database decision — SQLite and MySQL put it
        // first ascending, PostgreSQL puts it last — and this panel supports all
        // three. A column where "unknown" has a natural place therefore says so
        // rather than inheriting whichever engine the user chose, or the same
        // list would order differently on two installs of the same panel.
        //
        // The expression is built from the whitelisted column name, never from
        // the request, which is what keeps it out of the injection this class
        // exists to prevent.
        if (in_array($column, $nullsSmallest, true)) {
            $query->orderByRaw(
                $query->getGrammar()->wrap($column).' is null '.($descending ? 'asc' : 'desc')
            );
        }

        $direction = $descending ? 'desc' : 'asc';

        // Raw text ordering can put every uppercase name before every
        // lowercase one (SQLite's default), so `Banana` sorts before `apple`.
        // LOWER is shared by SQLite, MySQL/MariaDB and PostgreSQL, unlike each
        // engine's collation syntax. The column is still taken exclusively
        // from the caller's whitelist, never from unchecked request input.
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
     * Unlike {@see apply()}, this is for fixed backend ordering rather than a
     * client-selected `?sort=` value. Callers must pass a column declared in
     * code, never request input. The primary-key tie-breaker keeps equal names
     * stable across repeated responses.
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
