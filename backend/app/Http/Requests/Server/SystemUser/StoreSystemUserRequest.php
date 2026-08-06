<?php

namespace App\Http\Requests\Server\SystemUser;

use App\Services\Server\SshKeyManager;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreSystemUserRequest extends FormRequest
{
    /**
     * Linux system accounts that must never be created/managed by the panel.
     */
    private const RESERVED = [
        'root', 'daemon', 'bin', 'sys', 'sync', 'games', 'man', 'lp', 'mail',
        'news', 'uucp', 'proxy', 'www-data', 'backup', 'list', 'irc', 'gnats',
        'nobody', 'systemd-network', 'mysql', 'postgres', 'mongodb', 'redis',
        'ubuntu', 'admin', 'sshd',
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
                function (string $attribute, mixed $value, Closure $fail) {
                    if (! app(SshKeyManager::class)->isValidPublicKey((string) $value)) {
                        $fail(__('errors/system-user.invalid_public_key'));
                    }
                },
            ],
            // All optional, defaulting to the same values CreateSystemUser
            // already used before these existed — the fast path (username
            // only) stays exactly as fast.
            'shell' => ['sometimes', 'string', Rule::in(ChangeShellRequest::SHELLS)],
            'sudo' => ['sometimes', 'boolean'],
            'ssh_access' => ['sometimes', 'boolean'],
            'password' => ['sometimes', 'string', Password::min(10)->mixedCase()->numbers()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'username.not_in' => __('errors/system-user.reserved_username'),
        ];
    }
}
