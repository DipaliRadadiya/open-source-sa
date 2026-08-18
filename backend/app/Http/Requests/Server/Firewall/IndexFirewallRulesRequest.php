<?php

namespace App\Http\Requests\Server\Firewall;

use App\Support\ListSort;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Search, filter, sort and paging for the firewall rules list.
 *
 * This backs a new endpoint rather than paging the `rules` already inside
 * `GET /firewall`. That response is not a list — it also carries the firewall's
 * enabled state, the caller's own address, the ports currently listening and
 * the risky ones — and `listening[]` is built by shelling out to `ss`. Paging
 * inside it would run that subprocess again on every page click, for data the
 * page turn has nothing to do with.
 *
 * `GET /firewall` keeps returning `rules` unchanged, so nothing breaks the
 * moment this ships; the screen moves across when it is ready to.
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
            // Across port, source and description — the three things somebody
            // has in mind when hunting a rule. Ports are matched as text
            // deliberately: "80" should find 80 and 8080, because someone
            // scanning for a rule about the web server wants both.
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],

            'filter' => ['sometimes', 'array'],

            // Disabled rules are the ones worth being able to isolate: a rule
            // switched off is invisible in its effect but still on the screen.
            'filter.enabled' => ['sometimes', 'nullable', 'boolean'],

            'filter.action' => ['sometimes', 'nullable', Rule::in(['allow', 'deny'])],

            // Hand-made rules against the ones the panel seeded on enable, or
            // that came from a remote database user. Validated against the real
            // set so a typo is a 422 and not an empty list reading as "you have
            // no rules".
            'filter.origin' => ['sometimes', 'nullable', Rule::in(['user', 'default', 'db_user'])],

            'sort' => ListSort::rule(self::SORTS),

            'per_page' => ['sometimes', Rule::in(self::PAGE_SIZES)],
        ];
    }
}
