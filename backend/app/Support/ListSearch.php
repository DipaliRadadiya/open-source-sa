<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Grammars\PostgresGrammar;

/**
 * Portable case-insensitive free-text search for an Eloquent list query.
 *
 * Case-insensitive the native way for each supported database: LIKE on
 * SQLite/MySQL and ILIKE on PostgreSQL. The search text is matched literally.
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

        // What was typed is matched as text: `%` and `_` are LIKE wildcards,
        // so a search for `shop_db` also found `shopxdb` and `%` found every
        // row. `!` is the escape character because SQLite has
        // none by default and a backslash means different things in MySQL
        // and PostgreSQL string literals.
        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';

        $grammar = $query->getQuery()->getGrammar();
        $operator = $grammar instanceof PostgresGrammar ? 'ilike' : 'like';

        return $query->where(function (Builder $query) use ($columns, $like, $grammar, $operator) {
            foreach (array_values($columns) as $column) {
                $query->orWhereRaw($grammar->wrap($column)." {$operator} ? escape '!'", [$like]);
            }
        });
    }
}
