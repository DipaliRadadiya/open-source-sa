<?php

namespace App\Http\Requests\Server\Application;

use Illuminate\Foundation\Http\FormRequest;

class SaveEnvironmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route middleware already checks the grant and the site type; repeated so a route
        // registered later without it still cannot write to someone's site.
        return $this->user()?->canManage('app_environment') ?? false;
    }

    /**
     * ConvertEmptyStringsToNull turns an empty body into `null` before any rule runs. Coerce it
     * back to a string so the rules can stay strict about the key missing altogether.
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
            // The whole file, as text, not pairs: rebuilding from pairs loses the user's
            // comments, ordering and grouping.
            //
            // `present`, not `required`: an empty file is valid and `required` rejects `""`.
            // A missing key is still refused: treating it as empty would blank the environment.
            'raw' => ['present', 'string', 'max:262144'],
            // Keep a copy of what this replaces, so it can be put back. On unless turned off.
            'safety_copy' => ['sometimes', 'boolean'],
        ];
    }

    public function safetyCopy(): bool
    {
        return $this->boolean('safety_copy', true);
    }
}
