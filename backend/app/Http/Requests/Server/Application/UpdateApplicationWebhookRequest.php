<?php

namespace App\Http\Requests\Server\Application;

use App\Services\Git\Webhooks\WebhookManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Turning deploy-on-push on or off, and setting the secret it verifies with.
 *
 * `secret` is optional in both directions and means two different things:
 * omitted for GitHub or Bitbucket, the panel generates one; omitted for GitLab
 * with a signing token, there is nothing to generate and the request is refused,
 * because GitLab mints that value itself and only shows it once.
 */
class UpdateApplicationWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManage('app_deployment') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            // Which host will be calling. Defaults to the connected account's
            // provider; required outright for a public repository, which has no
            // account to infer it from.
            'provider' => [
                'required_if:enabled,true',
                'nullable',
                'string',
                Rule::in(app(WebhookManager::class)->providerNames()),
            ],
            // Long enough for GitLab's base64 signing token; the lower bound
            // stops someone pasting a four-character "secret" that a few
            // thousand requests would find.
            'secret' => ['sometimes', 'nullable', 'string', 'min:16', 'max:255'],
            // Mint a new one and invalidate the old, for a secret that leaked.
            'rotate' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'secret.min' => __('validation.webhook_secret_min'),
        ];
    }
}
