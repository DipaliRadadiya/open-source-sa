<?php

namespace App\Http\Requests\Server\SshKey;

use Illuminate\Foundation\Http\FormRequest;

class RemoveSshKeysRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // `permission:system_user,manage` on the route
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // As GET /ssh-keys shows it: `SHA256:` and unpadded base64.
            'fingerprint' => ['required', 'string', 'regex:/^SHA256:[A-Za-z0-9+\/]{43}$/'],
            // Only these users; every user holding the key when absent.
            'system_user_ids' => ['sometimes', 'array', 'min:1', 'max:100'],
            'system_user_ids.*' => ['integer', 'distinct'],
        ];
    }
}
