<?php

namespace App\Actions\Server\SystemUser;

use App\Models\SshKey;
use App\Models\SystemUser;
use App\Services\ActivityLogger;
use App\Services\Server\SshKeyManager;
use Illuminate\Support\Facades\DB;

class RemoveSshKey
{
    public function __construct(
        private SshKeyManager $keys,
        private ActivityLogger $activityLogger,
    ) {}

    public function execute(SystemUser $systemUser, SshKey $key): void
    {
        $name = $key->name;

        // The mirror image of AddSshKey: if the rewrite fails the key is
        // still in authorized_keys and still grants access, so the row that
        // says so has to come back rather than tell the admin it is gone.
        DB::transaction(function () use ($systemUser, $key): void {
            $key->delete();

            $this->keys->sync($systemUser);
        });

        $this->activityLogger->log('system_user.ssh_key_removed', $systemUser, [
            'username' => $systemUser->username,
            'key' => $name,
        ]);
    }
}
