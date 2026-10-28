<?php

namespace App\Http\Requests\Server\SshKey;

use App\Rules\SshPublicKey;
use Illuminate\Foundation\Http\FormRequest;

class DeploySshKeyRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'public_key' => ['required', 'string', new SshPublicKey],
            'system_user_ids' => ['required', 'array', 'min:1', 'max:100'],
            'system_user_ids.*' => ['integer', 'distinct', 'exists:system_users,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['name' => __('validation.attributes.label')];
    }
}
