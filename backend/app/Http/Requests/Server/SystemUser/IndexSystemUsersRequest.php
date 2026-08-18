<?php

namespace App\Http\Requests\Server\SystemUser;

use App\Support\ListSort;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Search, sort and paging for the system users list.
 *
 * No filters. The screen groups by nothing, and a filter nothing uses is still
 * surface to validate, document and keep working — the same call the
 * applications list made when a filter by system user was offered and declined.
 */
class IndexSystemUsersRequest extends FormRequest
{
    public const PER_PAGE = 10;

    public const PAGE_SIZES = [10, 20, 30, 50, 100];

    public const SORTS = ['created_at', 'username'];

    public function authorize(): bool
    {
        return $this->user()?->canView('system_user') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Username only. The row also shows which applications the account
            // owns, but those come from a relation — searching them means a
            // join, and nobody looks for a Linux account by the name of a site
            // it happens to run.
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],

            'sort' => ListSort::rule(self::SORTS),

            'per_page' => ['sometimes', Rule::in(self::PAGE_SIZES)],
        ];
    }
}
