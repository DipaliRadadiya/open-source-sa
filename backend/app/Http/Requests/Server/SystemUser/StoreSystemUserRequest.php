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
     * Names the panel refuses to create — and, read from the sync discoverer,
     * names it refuses to adopt. Shared rather than copied so the two lists
     * cannot drift into disagreeing about what counts as a system account.
     */
    public const RESERVED = [
        'root', 'daemon', 'bin', 'sys', 'sync', 'games', 'man', 'lp', 'mail',
        'news', 'uucp', 'proxy', 'www-data', 'backup', 'list', 'irc', 'gnats',
        'nobody', 'systemd-network', 'mysql', 'postgres', 'mongodb', 'redis',
        'ubuntu', 'admin', 'sshd',
        // Groups useradd would collide with, and the panel's own accounts
        // and groups (RC-27). The server check catches these too; listed so
        // the sync discoverer never adopts them either.
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
            // All optional, defaulting to the same values CreateSystemUser
            // already used before these existed — the fast path (username
            // only) stays exactly as fast.
            'shell' => ['sometimes', 'string', new InstalledShell],
            'sudo' => ['sometimes', 'boolean'],
            'ssh_access' => ['sometimes', 'boolean'],
            'password' => ['sometimes', 'string', Password::defaults(), 'not_regex:'.ChpasswdLine::FORBIDDEN],
        ];
    }

    /**
     * A user given SSH access but a shell that refuses login authenticates
     * successfully and is then disconnected — the panel would report access
     * the server does not grant. Refused rather than silently corrected: the
     * two fields are set on the same form, so the person can see which one
     * they meant.
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

        // Last, and only for an otherwise valid request, so a refused form runs
        // nothing on the server: a name that is already a user or group there
        // (`docker`, `lxd`, an account made by hand) is a 422 now, as on the
        // application form, rather than useradd's failure (RC-27).
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
