<?php

namespace App\Http\Requests\Server\Application;

use App\Enums\DomainType;
use App\Models\Certificate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Change what an attached name *does* — never what it is called.
 *
 * `domain` is deliberately absent. Renaming would leave the old name on any
 * Let's Encrypt certificate covering it, and `certbot renew` re-validates
 * every name in a lineage and fails the whole renewal if one of them cannot be
 * validated — so a rename would quietly stop the certificate covering the
 * site's *remaining, perfectly good* names from ever renewing, and the first
 * anyone would hear of it is a browser warning up to ninety days later.
 * {@see Certificate::staleDomains()}. Removing and re-adding is
 * the honest way to do that, because it is visibly two decisions.
 *
 * `primary` is absent for a different reason: promoting a name renames the
 * vhost file and both log files, which is what `makePrimary()` exists for.
 */
class UpdateApplicationDomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->canManage('app_domain');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['sometimes', Rule::in([DomainType::Alias->value, DomainType::Redirect->value])],
            // `required_if` alone is not enough here. On a create the absent
            // type defaults to alias, so there is nothing to require against;
            // on an update the row may *already* be a redirect and the request
            // may change only the target, leaving `type` out entirely. The
            // effective type is therefore resolved against the stored row in
            // the controller's action rather than guessed from this payload.
            'redirect_to' => ['nullable', 'string', 'max:2048', 'url'],
            'redirect_status' => ['sometimes', Rule::in([301, 302, 307, 308])],
        ];
    }
}
