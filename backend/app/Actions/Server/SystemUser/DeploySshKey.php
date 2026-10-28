<?php

namespace App\Actions\Server\SystemUser;

use App\Exceptions\Server\ServerOperationException;
use App\Models\SystemUser;
use App\Services\Server\Firewall\SshLockoutGuard;

/**
 * One public key onto several system users at once — what central's "put my
 * key on this server" needs (2026-10-28).
 *
 * Each user is its own add, through {@see AddSshKey}, so each keeps every
 * rule a single add has (written as the user, row and file together). One
 * failing does not stop the rest; the caller gets an answer per user.
 *
 * Warnings say why a key that was added still cannot be used to log in:
 * SSH access switched off for that user, or the firewall closing the SSH
 * port. They never stop the add — the user may be about to fix either.
 */
class DeploySshKey
{
    public function __construct(
        private AddSshKey $addSshKey,
        private SshLockoutGuard $ssh,
    ) {}

    /**
     * @param  array{name: string, public_key: string}  $key
     * @param  iterable<SystemUser>  $systemUsers
     * @return array{results: list<array<string, mixed>>, warnings: list<string>}
     */
    public function execute(array $key, iterable $systemUsers): array
    {
        $results = [];

        foreach ($systemUsers as $systemUser) {
            $row = ['system_user' => ['id' => $systemUser->id, 'username' => $systemUser->username]];

            try {
                $added = $this->addSshKey->execute($systemUser, $key);
                $row += [
                    'status' => $added->wasRecentlyCreated ? 'added' : 'existing',
                    'ssh_key_id' => $added->id,
                    'warnings' => $systemUser->ssh_access ? [] : ['ssh_access_off'],
                ];
            } catch (ServerOperationException $e) {
                $row += ['status' => 'failed', 'reference' => $e->reference, 'warnings' => []];
            }

            $results[] = $row;
        }

        return [
            'results' => $results,
            // Server-wide, so once rather than on every row.
            'warnings' => $this->ssh->sshPortOpen() ? [] : ['ssh_port_closed'],
        ];
    }
}
