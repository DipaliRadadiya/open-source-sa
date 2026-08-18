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

            'sort' => ListSort::rule(self::SORTS),

            'per_page' => ['sometimes', Rule::in(self::PAGE_SIZES)],
        ];
    }
}
