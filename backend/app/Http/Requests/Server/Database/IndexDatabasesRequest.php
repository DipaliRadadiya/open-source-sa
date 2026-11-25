<?php

namespace App\Http\Requests\Server\Database;

use App\Support\ListSort;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Search, filter, sort and paging for the databases list.
 *
 * Filtering is server-side: the endpoint pages, so a client-side filter would search only the
 * current page and report "no results" for a database that exists.
 */
class IndexDatabasesRequest extends FormRequest
{
    /** Ten by default, matching the applications list. */
    public const PER_PAGE = 10;

    /** A fixed set, not `min:1|max:100`: anything else is a client bug, so answer 422. */
    public const PAGE_SIZES = [10, 20, 30, 50, 100];

    /** Newest first is the default: a database somebody just created is the one they are looking for. */
    public const SORTS = ['created_at', 'name', 'engine', 'users_count', 'size_bytes'];

    public function authorize(): bool
    {
        return $this->user()?->canView('database') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Bounded: it reaches a LIKE, and an unbounded pattern is a slow query.
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],

            'filter' => ['sometimes', 'array'],

            // Same engine source as StoreDatabaseRequest. Validated because a typo would return an
            // empty list, which reads as "you have no databases".
            'filter.engine' => ['sometimes', 'nullable', Rule::in(array_keys((array) config('server.databases.engines', [])))],

            // An application's screen asks "which database is attached to me?" and "which could
            // be?"; both are this list with a where clause, so no second endpoint is needed.
            'filter.application_id' => ['sometimes', 'nullable', 'integer', Rule::exists('applications', 'id')],
            'filter.attached' => ['sometimes', 'nullable', 'boolean'],

            'sort' => ListSort::rule(self::SORTS),

            'per_page' => ['sometimes', Rule::in(self::PAGE_SIZES)],
        ];
    }
}
