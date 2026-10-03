<?php

namespace App\Http\Requests\Server\Docker;

use App\Rules\RegistryHost;
use App\Rules\SingleLine;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRegistryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManage('docker') ?? false;
    }

    /**
     * Every field optional, because omission means "leave it".
     *
     * Especially the token: the form cannot show the stored one, so it renders an
     * empty box, and a `required` rule there would force somebody to re-paste a
     * working credential every time they fixed a typo in the name.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:100', new SingleLine,
                // Ignoring this row, or renaming a registry to its own name would
                // fail the uniqueness check on itself.
                Rule::unique('registries', 'name')->ignore($this->route('registry')?->id),
            ],

            'registry' => ['sometimes', 'required', 'string', 'max:255', new SingleLine, new RegistryHost],

            'username' => ['sometimes', 'required', 'string', 'max:255', new SingleLine],

            // `nullable` AND absent both mean "keep the stored token". They are
            // the same intent expressed two ways by two clients, and treating a
            // null as "clear it" would turn a form that left the box alone into a
            // silent credential wipe.
            'token' => ['sometimes', 'nullable', 'string', 'max:4096', new SingleLine],
        ];
    }
}
