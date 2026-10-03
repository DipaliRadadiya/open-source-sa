<?php

namespace App\Http\Requests\Server\Application;

use App\Models\Application;
use App\Rules\DeployScriptPhpInstalled;
use App\Services\Server\Applications\GitDeployer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

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
            // One exception: a v7-style `{PHP81}` must name an installed PHP.
            'deploy_script' => ['sometimes', 'nullable', 'string', 'max:65535', new DeployScriptPhpInstalled],

            'webhook_enabled' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * A new branch must exist in the repository (bug #71): any name was
     * saved, and the next deploy failed on a ref that was never there.
     * Asked only when the branch actually changes, since it reaches the git
     * host.
     *
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $application = $this->route('application');

            if (! $application instanceof Application || ! $this->filled('branch')
                || $validator->errors()->has('branch') || $this->input('branch') === $application->branch) {
                return;
            }

            $probe = clone $application;
            $probe->branch = (string) $this->input('branch');

            if (($reason = app(GitDeployer::class)->checkRemoteBranch($probe)) !== null) {
                $validator->errors()->add('branch', __("validation.git_{$reason}", ['branch' => $probe->branch]));
            }
        }];
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
