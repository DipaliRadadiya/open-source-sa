<?php

namespace App\Http\Requests\Server\Firewall;

use App\Models\FirewallRule;
use App\Support\ListSort;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Search, filter, sort and paging for the firewall rules list.
 *
 * Separate from `GET /firewall`, whose `listening[]` is built by shelling out to `ss`:
 * paging there would rerun that subprocess on every page click.
 */
class IndexFirewallRulesRequest extends FormRequest
{
    public const PER_PAGE = 10;

    public const PAGE_SIZES = [10, 20, 30, 50, 100];

    public const SORTS = ['created_at', 'port_from', 'action', 'protocol'];

    public function authorize(): bool
    {
        return $this->user()?->canView('firewall') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Matches port, source and description. Ports match as text on purpose:
            // "80" finds 80 and 8080.
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],

            'filter' => ['sometimes', 'array'],

            'filter.enabled' => ['sometimes', 'nullable', 'boolean'],

            'filter.action' => ['sometimes', 'nullable', Rule::in(['allow', 'deny'])],

            // Validated against the real set so a typo is a 422, not an empty list.
            'filter.origin' => ['sometimes', 'nullable', Rule::in([FirewallRule::ORIGIN_USER, FirewallRule::ORIGIN_DEFAULT, FirewallRule::ORIGIN_DATABASE])],

            'sort' => ListSort::rule(self::SORTS),

            'per_page' => ['sometimes', Rule::in(self::PAGE_SIZES)],
        ];
    }
}
