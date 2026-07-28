<?php

namespace App\Http\Requests\Server\Git;

use App\Rules\SafeProviderHost;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Rename a connection and/or rotate its token. The provider itself is not
 * editable — a different provider is a different connection.
 */
class UpdateGitAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManage('git') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $account = $this->route('account');

        return [
            'label' => ['sometimes', 'string', 'max:100', Rule::unique('git_accounts', 'label')->ignore($account)],
            'token' => ['sometimes', 'string', 'max:500'],
            'host' => [
                Rule::excludeIf($account?->provider !== 'gitlab'),
                'sometimes', 'nullable', 'string', 'max:255', new SafeProviderHost,
            ],
            'workspace' => [
                Rule::excludeIf($account?->provider !== 'bitbucket'),
                'sometimes', 'string', 'max:100', 'regex:/^[A-Za-z0-9._-]+$/',
            ],
        ];
    }
}
