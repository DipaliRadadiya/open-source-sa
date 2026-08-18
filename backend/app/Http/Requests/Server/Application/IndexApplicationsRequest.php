<?php

namespace App\Http\Requests\Server\Application;

use App\Http\Resources\ApplicationResource;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Search and paging for the applications list.
 *
 * Deliberately just those two. The obvious extra filters — by system user, by
 * status, by site type — were considered and left out: the list is one screen
 * of sites, and a search box that matches the name or the domain is how people
 * actually look for one. Filters that nothing uses are still surface to
 * validate, document and keep working.
 */
class IndexApplicationsRequest extends FormRequest
{
    /**
     * Ten, because the list is a card grid rather than a dense table and the
     * per-row cost is real: {@see ApplicationResource}
     * asks systemd for the state of every application that runs a process, so
     * a page is a number of subprocesses as much as a number of rows.
     */
    public const PER_PAGE = 10;

    public function authorize(): bool
    {
        return $this->user()?->canView('application') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Free text over name and domain. Bounded because it reaches a
            // LIKE, and an unbounded pattern is a slow query somebody can ask
            // for repeatedly.
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],

            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
