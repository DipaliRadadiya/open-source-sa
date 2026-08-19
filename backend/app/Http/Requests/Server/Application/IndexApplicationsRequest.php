<?php

namespace App\Http\Requests\Server\Application;

use App\Enums\ApplicationStatus;
use App\Http\Resources\ApplicationResource;
use App\Services\Applications\SiteTypeManager;
use App\Support\ListSort;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Search, filters and paging for the applications list.
 *
 * Two filters, both driven by what the screen already groups by: status and
 * site type. Filtering by system user or by server was offered and declined —
 * a filter nothing uses is still surface to validate, document and keep
 * working.
 *
 * Both are validated against the real sets rather than accepted as strings. A
 * typo in `filter[status]` would otherwise return an empty list, which reads
 * to the user as "you have no applications" — the same failure the backup
 * list had before its filters were validated.
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

    /**
     * The columns the applications table shows, in the order it shows them.
     *
     * Newest first by default — a site somebody just created is the one they
     * came here to find. Owner is absent on purpose: it lives on a relation, so
     * sorting by it means a join, and the column shows a username the list can
     * already be searched by.
     */
    public const SORTS = ['created_at', 'name', 'domain', 'status', 'site_type', 'directory_size_bytes'];

    /**
     * Size is null for a site nothing has measured yet, and that is not the
     * same as a site of zero bytes. Sorted as the smallest value so the
     * unmeasured group together at one end rather than being mixed in with
     * genuinely empty sites — which is the decision the table already made
     * client-side, before paging moved sorting to the server.
     *
     * @var array<int, string>
     */
    public const NULLS_SMALLEST = ['directory_size_bytes'];

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

            'filter.status' => ['sometimes', 'nullable', Rule::enum(ApplicationStatus::class)],

            // Against the registered site types, not a free string — the same
            // list the catalog is built from, so the filter cannot offer or
            // accept a type the panel does not have.
            'filter.site_type' => ['sometimes', 'nullable', Rule::in(app(SiteTypeManager::class)->names())],

            'sort' => ListSort::rule(self::SORTS),

            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
