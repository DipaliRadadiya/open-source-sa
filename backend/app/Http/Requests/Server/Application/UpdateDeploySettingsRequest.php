<?php

namespace App\Http\Requests\Server\Application;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDeploySettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->canManage('app_deployment');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // A git ref, not free text: it ends up in `git fetch origin <ref>`.
            // The charset is what git itself permits minus the characters a
            // shell would treat specially — the command is an array, so this is
            // belt to that brace rather than the only defence.
            'branch' => ['sometimes', 'string', 'max:255', 'regex:/^[A-Za-z0-9._\/-]+$/'],

            // Deliberately unvalidated beyond a length cap. This is a shell
            // script the user wrote to run on their own server as their own
            // site user — refusing characters would be theatre, since every
            // one of them is legitimate in a script. The control that matters
            // is the privilege drop, not a denylist.
            'deploy_script' => ['sometimes', 'nullable', 'string', 'max:65535'],

            'webhook_enabled' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('deploy_script')) {
            // Normalise line endings: a script pasted from Windows carries \r,
            // and `sh` reads it as part of the command — producing errors like
            // "command not found: composer\r" that are impossible to see.
            $this->merge([
                'deploy_script' => str_replace("\r\n", "\n", (string) $this->input('deploy_script')),
            ]);
        }
    }
}
