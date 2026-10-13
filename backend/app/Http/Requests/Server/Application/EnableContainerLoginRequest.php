<?php

namespace App\Http\Requests\Server\Application;

use App\Models\Application;
use App\Services\Server\AppShell\ContainerLoginInfo;
use App\Services\Server\Settings\SecuritySettings;
use App\Services\Server\SshKeyManager;
use App\Services\Server\SystemUsers\ChpasswdLine;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

/**
 * `POST /applications/{application}/container/access` (RC-28).
 *
 * The key and the password follow the System Users rules exactly — the same
 * key check, the same password policy — because they end up in the same
 * place through the same actions.
 *
 * Two refusals are about the server rather than the input:
 *
 *  - an owner that also owns non-Docker apps loses ordinary SSH and SFTP to
 *    them the moment this is on, so that has to be confirmed in the request;
 *  - with password login off server-wide, a user with no key could never log
 *    in, so a key is required.
 */
class EnableContainerLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        // A site that is not a container answers like the other container
        // endpoints do, and before its input is judged: there is no container
        // login to confirm or to bring a key for.
        $application = $this->route('application');

        if ($application instanceof Application) {
            abort_unless($application->supports('app_compose'), 404);
            abort_unless($application->serving_profile === 'docker', 422, __('errors/application.not_a_container'));
        }

        return $this->user()?->canManage('system_user') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ssh_key' => [
                'nullable', 'string', 'max:16384',
                function (string $attribute, mixed $value, Closure $fail) {
                    if (! app(SshKeyManager::class)->isValidPublicKey((string) $value)) {
                        $fail(__('errors/system-user.invalid_public_key'));
                    }
                },
            ],
            'password' => ['nullable', 'string', Password::defaults(), 'not_regex:'.ChpasswdLine::FORBIDDEN],
            'confirm_container_only' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.not_regex' => __('errors/system-user.password_control_characters'),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $application = $this->route('application');
            $owner = $application instanceof Application ? $application->systemUser : null;

            if ($owner === null) {
                $validator->errors()->add('container_login', __('errors/system-user.container_login_no_owner'));

                return;
            }

            $others = app(ContainerLoginInfo::class)->otherApps($owner);

            if ($others > 0 && ! $this->boolean('confirm_container_only')) {
                $validator->errors()->add('confirm_container_only', trans_choice('errors/system-user.container_login_confirm', $others, [
                    'username' => $owner->username,
                    'count' => $others,
                ]));
            }

            if (! $this->filled('ssh_key') && $owner->sshKeys()->doesntExist() && ! app(SecuritySettings::class)->passwordLogin()) {
                $validator->errors()->add('ssh_key', __('errors/system-user.container_login_needs_key', [
                    'username' => $owner->username,
                ]));
            }
        });
    }
}
