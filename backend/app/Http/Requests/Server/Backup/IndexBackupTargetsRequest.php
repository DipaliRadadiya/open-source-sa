<?php

namespace App\Http\Requests\Server\Backup;

use App\Support\ListSort;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Search, filter, sort and paging for the backup coverage list.
 *
 * This list is one row per application, so it grows with the number of sites on
 * the server rather than with anything the user does here.
 *
 * `filter[protected]` is the reason this screen exists — it answers "which of
 * my sites are not being backed up", which was previously only reachable by
 * reading every row. By name ascending, because the row is identified by the
 * site's name and somebody is looking for one they can already name.
 */
class IndexBackupTargetsRequest extends FormRequest
{
    public const PER_PAGE = 10;

    public const PAGE_SIZES = [10, 20, 30, 50, 100];

    public const SORTS = ['name', 'domain', 'created_at'];

    /** `kind=database` (RC-23): databases have no domain, and do have an engine. */
    public const DATABASE_SORTS = ['name', 'engine', 'created_at'];

    public function authorize(): bool
    {
        return $this->user()?->canView('backup') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],

            'filter' => ['sometimes', 'array'],

            'filter.protected' => ['sometimes', 'nullable', 'boolean'],

            // RC-23: `database` lists the databases that are not part of a
            // site — only those can have a schedule of their own — instead of
            // the applications. Absent means `application`, as before.
            'kind' => ['sometimes', Rule::in(['application', 'database'])],

            'sort' => ListSort::rule($this->input('kind') === 'database' ? self::DATABASE_SORTS : self::SORTS),

            'per_page' => ['sometimes', Rule::in(self::PAGE_SIZES)],
        ];
    }
}
