<?php

namespace App\Http\Requests\Server\SystemUser;

use App\Enums\LoginShell;
use App\Rules\InstalledShell;
use App\Rules\SshPublicKey;
use App\Services\Server\SystemUsers\ChpasswdLine;
use App\Services\Server\SystemUsers\SystemUsernameGenerator;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreSystemUserRequest extends FormRequest
{
    /**
     * Names the panel refuses to create and, read from the sync discoverer, refuses to adopt.
     * Shared so the two lists cannot drift.
     */
    public const RESERVED = [
        'root', 'daemon', 'bin', 'sys', 'sync', 'games', 'man', 'lp', 'mail',
        'news', 'uucp', 'proxy', 'www-data', 'backup', 'list', 'irc', 'gnats',
        'nobody', 'systemd-network', 'mysql', 'postgres', 'mongodb', 'redis',
        'ubuntu', 'admin', 'sshd',
        // Groups useradd would collide with, and the panel's own accounts and groups. The server
        // check catches these too; listed so the sync discoverer never adopts them either.
        'sudo', 'adm', 'docker', 'lxd', 'staff', 'users', 'nogroup', 'syslog',
        'systemd-journal', 'ssh-users', 'sv-app-shell', 'panel',
    ];

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
            'username' => [
                'required', 'string',
                // Linux username rules: start with a letter/underscore, then
                // letters/digits/hyphens/underscores, max 32 chars.
                'regex:/^[a-z_][a-z0-9_-]{0,31}$/',
                'unique:system_users,username',
                Rule::notIn(self::RESERVED),
            ],
            'public_key' => [
                'nullable', 'string',
                new SshPublicKey,
            ],
            // All optional, defaulting to what CreateSystemUser already did (username only).
            'shell' => ['sometimes', 'string', new InstalledShell],
            'sudo' => ['sometimes', 'boolean'],
            'ssh_access' => ['sometimes', 'boolean'],
            'password' => ['sometimes', 'string', Password::defaults(), 'not_regex:'.ChpasswdLine::FORBIDDEN],
        ];
    }

    /**
     * A user given SSH access but a shell that refuses login authenticates and is then
     * disconnected, so the panel would report access the server does not grant. Refused rather
     * than silently corrected: the person can see which of the two fields they meant.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $shell = $this->input('shell');

            if (! $this->boolean('ssh_access') || $shell === null) {
                return;
            }

            if (LoginShell::allowsLoginFor($shell) === false) {
                $validator->errors()->add('ssh_access', __('errors/system-user.ssh_needs_login_shell'));
            }
        });

        // Last, and only for an otherwise valid request, so a refused form runs nothing on the
        // server: a name already used as a user or group there (`docker`, a hand-made account)
        // is a 422, not useradd's failure.
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isEmpty() && ! app(SystemUsernameGenerator::class)->available((string) $this->input('username'))) {
                $validator->errors()->add('username', __('errors/system-user.username_taken_on_server'));
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'username.not_in' => __('errors/system-user.reserved_username'),
            // chpasswd reads one account per line — see ChpasswdLine.
            'password.not_regex' => __('errors/system-user.password_control_characters'),
        ];
    }
}
