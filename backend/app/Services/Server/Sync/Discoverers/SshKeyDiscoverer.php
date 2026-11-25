<?php

namespace App\Services\Server\Sync\Discoverers;

use App\Contracts\Discoverable;
use App\Models\SshKey;
use App\Models\SyncRun;
use App\Models\SystemUser;
use App\Services\Server\ServerOps;
use App\Services\Server\SshKeyManager;
use Illuminate\Database\Eloquent\Collection;

/**
 * Keys already in a user's `authorized_keys` that the panel has no record of.
 *
 * A key on the box that the panel cannot list is unaudited access, so these are synced.
 *
 * Runs after system users, because a key belongs to one (including users adopted
 * earlier in the same run).
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

        $systemUsers = SystemUser::query()->with('sshKeys')->get();

        // A preview's own accounts, which applying it would adopt first (see
        // SyncRun::previewedSystemUsers()). Unsaved stand-ins with no keys on record;
        // a preview never reaches adopt(), so the missing id is never used.
        foreach ($run->previewedSystemUsers() as $username => $home) {
            if ($home !== null && ! $systemUsers->contains('username', $username)) {
                $systemUsers->push(
                    (new SystemUser(['username' => $username, 'home_path' => $home]))->setRelation('sshKeys', new Collection),
                );
            }
        }

        foreach ($systemUsers as $systemUser) {
            $path = rtrim((string) $systemUser->home_path, '/').'/.ssh/authorized_keys';

            $result = $this->serverOps->run(
                ['cat', $path],
                ['feature' => 'sync', 'op' => 'discover_ssh_keys', 'system_user' => $systemUser->username],
                timeout: 30,
                expectedExitCodes: [1], // no authorized_keys yet
            );

            // No file is normal for a user who never had a key: not an error, no item.
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
                    // Not parseable (e.g. an option-prefixed entry). Reported, not dropped:
                    // a key the panel cannot read still grants access.
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

        // firstOrCreate on the table's unique pair, so a second run adopts nothing.
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
