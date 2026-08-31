<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Portable case-insensitive free-text search for an Eloquent list query.
 *
 * Laravel compiles whereLike(caseSensitive: false) to the native equivalent
 * for each supported database: LIKE on SQLite/MySQL and ILIKE on PostgreSQL.
 * Columns are declared by the caller, never accepted from request input.
 */
class ListSearch
{
    /**
     * @param  array<int, string>  $columns
     */
    public static function apply(Builder $query, string $search, array $columns): Builder
    {
        $search = trim($search);

        if ($search === '' || $columns === []) {
            return $query;
        }

        $like = '%'.$search.'%';

        return $query->where(function (Builder $query) use ($columns, $like) {
            foreach (array_values($columns) as $index => $column) {
                if ($index === 0) {
                    $query->whereLike($column, $like);

                    continue;
                }

                $query->orWhereLike($column, $like);
            }
        });
    }
}
