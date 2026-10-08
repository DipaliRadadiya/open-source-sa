<?php

namespace App\Http\Requests\Server\SystemUser;

use App\Services\Server\SshKeyManager;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StoreSshKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManage('system_user') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'public_key' => [
                'required', 'string',
                function (string $attribute, mixed $value, Closure $fail) {
                    if (! app(SshKeyManager::class)->isValidPublicKey((string) $value)) {
                        $fail(__('errors/system-user.invalid_public_key'));
                    }
                },
            ],
        ];
    }

    /**
     * The form calls `name` "Label" (SU-B2): "The name field must not be
     * greater than 255 characters" named a field nobody can see.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['name' => __('validation.attributes.label')];
    }
}
