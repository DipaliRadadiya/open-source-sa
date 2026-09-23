<?php

namespace App\Actions\Server\SystemUser;

use App\Models\SshKey;
use App\Models\SystemUser;
use App\Services\ActivityLogger;
use App\Services\Server\SshKeyManager;
use Illuminate\Support\Facades\DB;
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

        // One transaction around the row and the write: sync() renders the
        // file from the rows, so the row has to exist first — and if the write
        // is refused, the row must not outlive it. It did: the panel listed a
        // key the server never received (2026-09-23, a planted symlink the
        // now user-run write correctly refused).
        $key = DB::transaction(function () use ($systemUser, $data, $fingerprint): SshKey {
            $key = $systemUser->sshKeys()->create([
                'name' => $data['name'],
                'public_key' => trim($data['public_key']),
                'fingerprint' => $fingerprint,
            ]);

            $this->keys->sync($systemUser);

            return $key;
        });

        $this->activityLogger->log('system_user.ssh_key_added', $systemUser, [
            'username' => $systemUser->username,
            'key' => $data['name'],
        ]);

        return $key;
    }
}
