<?php

namespace App\Http\Requests\Server\Git;

use App\Rules\SafeProviderHost;
use App\Services\Git\GitProviderManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGitAccountRequest extends FormRequest
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
        return [
            'provider' => ['required', Rule::in(app(GitProviderManager::class)->providerNames())],
            'label' => ['required', 'string', 'max:100', Rule::unique('git_accounts', 'label')],
            'token' => ['required', 'string', 'max:500'],

            // Self-hosted GitLab only.
            'host' => [
                Rule::excludeIf($this->input('provider') !== 'gitlab'),
                'nullable', 'string', 'max:255', new SafeProviderHost,
            ],

            // Bitbucket addresses everything through a workspace; its scoped
            // access tokens are not user tokens, so we cannot discover it.
            'workspace' => [
                Rule::excludeIf($this->input('provider') !== 'bitbucket'),
                'required', 'string', 'max:100', 'regex:/^[A-Za-z0-9._-]+$/',
            ],
        ];
    }
}
