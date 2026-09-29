<?php

namespace App\Http\Requests\Server\Docker;

use App\Rules\RegistryHost;
use App\Rules\SingleLine;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRegistryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManage('docker') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('registries', 'name'), new SingleLine],

            'registry' => ['required', 'string', 'max:255', new SingleLine, new RegistryHost],

            'username' => ['required', 'string', 'max:255', new SingleLine],

            // Required on create, unlike on update. A registry row with no token
            // is a row that cannot do the one thing it exists for, and storing it
            // moves the failure from this form to somebody's deploy.
            //
            // `SingleLine` matters more here than anywhere: a token pasted with a
            // trailing newline is a token that fails to authenticate while
            // looking correct in every screen that shows it — and this one shows
            // it nowhere, so there would be nothing to look at.
            'token' => ['required', 'string', 'max:4096', new SingleLine],
        ];
    }
}
