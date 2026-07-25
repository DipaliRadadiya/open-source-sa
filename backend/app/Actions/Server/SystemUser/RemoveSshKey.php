<?php

namespace App\Actions\Server\SystemUser;

use App\Models\SshKey;
use App\Models\SystemUser;
use App\Services\ActivityLogger;
use App\Services\Server\SshKeyManager;

class RemoveSshKey
{
    public function __construct(
        private SshKeyManager $keys,
        private ActivityLogger $activityLogger,
    ) {}

    public function execute(SystemUser $systemUser, SshKey $key): void
    {
        $name = $key->name;

        $key->delete();

        $this->keys->sync($systemUser);

        $this->activityLogger->log('system_user.ssh_key_removed', $systemUser, [
            'username' => $systemUser->username,
            'key' => $name,
        ]);
    }
}
