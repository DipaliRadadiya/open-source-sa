<?php

namespace App\Http\Requests\Admin;

use App\Support\ListSort;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Search, sort and paging for the roles list.
 *
 * `List…` rather than `Index…` to match its neighbours here — ListUsersRequest,
 * ListActivityLogRequest. The server side of the API settled on `Index…`; the
 * admin side on `List…`, and consistency within a directory beats consistency
 * with a directory nobody reads at the same time.
 *
 * By name ascending, not newest first: roles are a short, stable list somebody
 * scans alphabetically, not a feed. This is also the first ordering the
 * endpoint has ever had — it was a bare `get()`, which under pagination means
 * a row can appear on two pages and another on none.
 */
class ListRolesRequest extends FormRequest
{
    public const PER_PAGE = 10;

    public const PAGE_SIZES = [10, 20, 30, 50, 100];

    public const SORTS = ['name', 'created_at'];

    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],

            'sort' => ListSort::rule(self::SORTS),

            'per_page' => ['sometimes', Rule::in(self::PAGE_SIZES)],
        ];
    }
}
