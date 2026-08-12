<?php

namespace App\Http\Requests\Server\Application;

use Illuminate\Foundation\Http\FormRequest;

class SaveEnvironmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route middleware already enforces both halves — the grant and
        // whether this site type has an environment file at all. Repeated here
        // only so a route registered later without the middleware still cannot
        // write to someone's site.
        return $this->user()?->canManage('app_environment') ?? false;
    }

    /**
     * Laravel's ConvertEmptyStringsToNull turns an empty body into `null`
     * before any rule runs, so "the user cleared the editor" and "the client
     * sent null" are already indistinguishable by the time validation sees
     * them. Coerced back to a string here so the rules below can stay strict
     * about the case that IS distinguishable: the key missing altogether.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('raw') && $this->input('raw') === null) {
            $this->merge(['raw' => '']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // The whole file, as text. Not a list of pairs: rebuilding the
            // file from pairs discards the comments, ordering and grouping
            // that are the user's, and they would never get them back.
            //
            // `present`, not `required`: an empty file is a legitimate thing to
            // save, and `required` rejects `""`, so clearing the editor
            // answered with "The raw field is required" about a field the user
            // had just emptied on purpose. `present` still refuses a request
            // that omits the key altogether, which is the case worth catching —
            // that one is a client bug, and treating it as "empty" would blank
            // someone's environment on a malformed request.
            'raw' => ['present', 'string', 'max:262144'],
        ];
    }
}
