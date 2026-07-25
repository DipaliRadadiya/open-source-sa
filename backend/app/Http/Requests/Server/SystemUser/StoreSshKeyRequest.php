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
}
