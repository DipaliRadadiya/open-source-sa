<?php

namespace App\Services\Server\Sync\Discoverers;

use App\Contracts\Discoverable;
use App\Models\SshKey;
use App\Models\SyncRun;
use App\Models\SystemUser;
use App\Services\Server\ServerOps;
use App\Services\Server\SshKeyManager;

/**
 * Keys already in a user's `authorized_keys` that the panel has no record of.
 *
 * Worth syncing for a reason the other resources do not have: a key on the box
 * the panel cannot list is unaudited access. Someone reading the SSH Keys
 * screen of a migrated server would conclude nobody can log in, while three
 * old laptops still can.
 *
 * Runs after system users, because a key belongs to one — including users
 * adopted moments earlier in the same run.
 */
class SshKeyDiscoverer implements Discoverable
{
    public function __construct(
        private ServerOps $serverOps,
        private SshKeyManager $keys,
    ) {}

    public function resourceType(): string
    {
        return 'ssh_key';
    }

    public function dependsOn(): array
    {
        return ['system_user'];
    }

    public function discover(SyncRun $run): array
    {
        $found = [];

        foreach (SystemUser::query()->with('sshKeys')->get() as $systemUser) {
            $path = rtrim((string) $systemUser->home_path, '/').'/.ssh/authorized_keys';

            $result = $this->serverOps->run(
                ['cat', $path],
                ['feature' => 'sync', 'op' => 'discover_ssh_keys', 'system_user' => $systemUser->username],
                timeout: 30,
            );

            // No file is the normal case for a user who has never had a key.
            // Not an error, and not worth an item.
            if ($result->failed()) {
                continue;
            }

            $tracked = $systemUser->sshKeys->pluck('fingerprint')->all();

            foreach (preg_split('/\r?\n/', trim($result->output())) ?: [] as $line) {
                $line = trim($line);

                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }

                if (! $this->keys->isValidPublicKey($line)) {
                    // A line that is not a key at all — an option-prefixed
                    // entry, or a comment style we do not parse. Reported
                    // rather than dropped, because a key the panel cannot read
                    // is still a key that grants access.
                    $found[] = [
                        'key' => $systemUser->username.':unparsed:'.substr(md5($line), 0, 8),
                        'label' => $systemUser->username,
                        'skip' => 'unreadable_key',
                        'evidence' => ['system_user' => $systemUser->username, 'path' => $path],
                    ];

                    continue;
                }

                $fingerprint = $this->keys->fingerprint($line);

                if (in_array($fingerprint, $tracked, true)) {
                    continue;
                }

                $parts = preg_split('/\s+/', $line);
                $comment = $parts[2] ?? '';

                $found[] = [
                    'key' => $systemUser->username.':'.$fingerprint,
                    'label' => $comment !== '' ? $comment : $fingerprint,
                    'confidence' => 100,
                    'evidence' => [
                        'system_user' => $systemUser->username,
                        'fingerprint' => $fingerprint,
                        'type' => $parts[0] ?? null,
                    ],
                    'attributes' => [
                        'system_user_id' => $systemUser->id,
                        // The comment is what a person recognises a key by;
                        // the fingerprint is what makes it unique.
                        'name' => $comment !== '' ? $comment : 'Imported key',
                        'public_key' => $line,
                        'fingerprint' => $fingerprint,
                    ],
                ];
            }
        }

        return $found;
    }

    public function adopt(array $item): ?object
    {
        $attributes = $item['attributes'] ?? [];

        // firstOrCreate on the pair the table already treats as unique, so a
        // second run over the same box adopts nothing and fails nothing.
        return SshKey::firstOrCreate(
            [
                'system_user_id' => $attributes['system_user_id'],
                'fingerprint' => $attributes['fingerprint'],
            ],
            [
                'name' => $attributes['name'],
                'public_key' => $attributes['public_key'],
            ],
        );
    }
}
