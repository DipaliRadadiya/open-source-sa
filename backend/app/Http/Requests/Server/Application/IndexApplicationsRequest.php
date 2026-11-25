<?php

namespace App\Http\Requests\Server\Application;

use App\Enums\ApplicationStatus;
use App\Http\Resources\ApplicationResource;
use App\Services\Applications\SiteTypeManager;
use App\Support\ListSort;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Search, filters (status, site type) and paging for the applications list.
 *
 * Filters are validated against the real sets: a typo would otherwise return an empty list,
 * which reads as "you have no applications".
 */
class IndexApplicationsRequest extends FormRequest
{
    /**
     * Ten, because each row costs real work: {@see ApplicationResource} asks systemd for the state
     * of every application that runs a process.
     */
    public const PER_PAGE = 10;

    public const PAUSED = 'paused';

    /**
     * The columns the applications table shows, in order. Newest first by default. Owner is absent
     * on purpose: it is a relation, so sorting by it would need a join.
     */
    public const SORTS = ['created_at', 'name', 'domain', 'status', 'site_type', 'directory_size_bytes'];

    /**
     * Null size means "not measured yet", not zero bytes. Sorted as the smallest value so the
     * unmeasured group at one end instead of mixing with genuinely empty sites.
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
            // Free text over name and domain. Bounded because it reaches a LIKE.
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],

            // `paused` is not a stored status (a paused site stays `active` with `disabled_at` set),
            // but it is what the badge shows, so the filter offers it.
            'filter.status' => ['sometimes', 'nullable', Rule::in([...array_column(ApplicationStatus::cases(), 'value'), self::PAUSED])],

            // Against the registered site types (the catalog's own list), not a free string.
            'filter.site_type' => ['sometimes', 'nullable', Rule::in(app(SiteTypeManager::class)->names())],

            'sort' => ListSort::rule(self::SORTS),

            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
