<?php

namespace App\Http\Requests\Server\Database;

use App\Support\ListSort;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Search, filter, sort and paging for the databases list.
 *
 * The screen filtered `db.name` in JavaScript over the whole list. That was
 * correct only because the whole list was there; the moment this endpoint pages
 * — which is the point of this request — a client-side filter searches the
 * current page and reports "no results" for a database that exists.
 */
class IndexDatabasesRequest extends FormRequest
{
    /**
     * Ten by default, matching the applications list. The frontend sends
     * `per_page` when the user picks another size from the page-size control.
     */
    public const PER_PAGE = 10;

    /**
     * A fixed set rather than `min:1|max:100`. The sizes are a control on the
     * screen with named options, so anything else is a client bug, and
     * answering it with a 422 says so instead of quietly honouring `per_page=7`.
     */
    public const PAGE_SIZES = [10, 20, 30, 50, 100];

    /**
     * Newest first is the default because a database somebody just created is
     * the one they are looking for.
     */
    public const SORTS = ['created_at', 'name', 'engine', 'users_count'];

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
            // Bounded: it reaches a LIKE, and an unbounded pattern is a slow
            // query somebody can ask for repeatedly.
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],

            'filter' => ['sometimes', 'array'],

            // Against the configured engines, the same source
            // StoreDatabaseRequest validates against — so the filter cannot
            // offer or accept an engine this panel does not know. Validated
            // rather than passed through because a typo would return an empty
            // list, which reads as "you have no databases".
            'filter.engine' => ['sometimes', 'nullable', Rule::in(array_keys((array) config('server.databases.engines', [])))],

            'sort' => ListSort::rule(self::SORTS),

            'per_page' => ['sometimes', Rule::in(self::PAGE_SIZES)],
        ];
    }
}
