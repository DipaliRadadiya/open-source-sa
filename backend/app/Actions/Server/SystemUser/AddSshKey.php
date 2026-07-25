<?php

namespace App\Actions\Server\SystemUser;

use App\Models\SshKey;
use App\Models\SystemUser;
use App\Services\ActivityLogger;
use App\Services\Server\SshKeyManager;
use Illuminate\Validation\ValidationException;

class AddSshKey
{
    public function __construct(
        private SshKeyManager $keys,
        private ActivityLogger $activityLogger,
    ) {}

    /**
     * @param  array{name: string, public_key: string}  $data
     */
    public function execute(SystemUser $systemUser, array $data): SshKey
    {
        $fingerprint = $this->keys->fingerprint($data['public_key']);

        if ($systemUser->sshKeys()->where('fingerprint', $fingerprint)->exists()) {
            throw ValidationException::withMessages([
                'public_key' => [__('errors/system-user.duplicate_public_key')],
            ]);
        }

        $key = $systemUser->sshKeys()->create([
            'name' => $data['name'],
            'public_key' => trim($data['public_key']),
            'fingerprint' => $fingerprint,
        ]);

        $this->keys->sync($systemUser);

        $this->activityLogger->log('system_user.ssh_key_added', $systemUser, [
            'username' => $systemUser->username,
            'key' => $data['name'],
        ]);

        return $key;
    }
}
