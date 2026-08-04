<?php

namespace App\Services\Server\Doctor\Checks;

use App\Contracts\DoctorCheck;
use Illuminate\Support\Facades\Process;

/**
 * Is a stale lock file blocking every account operation?
 *
 * `useradd` writes `<file>.<pid>` and hard-links it to `<file>.lock`. If a
 * `.lock` is already there the link fails and the tool reports "cannot lock
 * /etc/passwd; try again later" — advice that will never come true, because
 * nothing cleans the file up. One interrupted useradd (an installer killed
 * partway, an OOM) breaks system users, and therefore applications, for good.
 *
 * ServerOps retries that message as transient, which is right when apt holds
 * the lock and useless when the holder is a corpse. This check tells the two
 * apart the only way that works: ask whether any process actually has the
 * file open.
 */
class AccountLocksCheck implements DoctorCheck
{
    /** Written by shadow-utils while it edits the account databases. */
    private const LOCKS = [
        '/etc/passwd.lock',
        '/etc/shadow.lock',
        '/etc/group.lock',
        '/etc/gshadow.lock',
        '/etc/subuid.lock',
        '/etc/subgid.lock',
    ];

    public function key(): string
    {
        return 'account_locks';
    }

    public function run(): array
    {
        $present = array_values(array_filter(self::LOCKS, 'file_exists'));

        if ($present === []) {
            return ['status' => 'pass', 'detail' => 'no lock files present', 'fix' => null];
        }

        // A lock with a live holder is a normal moment in time — apt installing
        // a package that adds a system user, say. Reporting that as broken
        // would cry wolf, so ask who holds it.
        if ($this->heldBySomething($present)) {
            return [
                'status' => 'pass',
                'detail' => 'locked right now by a running process — normal',
                'fix' => null,
            ];
        }

        return [
            'status' => 'fail',
            'detail' => 'stale: '.implode(', ', $present),
            'fix' => 'doctor.fixes.account_locks',
        ];
    }

    /**
     * `fuser` exits 0 when at least one process holds one of the files. Absent
     * fuser we cannot prove a holder exists — and the safe answer then is to
     * assume there is one, because the advice for a stale lock is "delete
     * these files" and that must never be given on a guess.
     */
    private function heldBySomething(array $paths): bool
    {
        $result = Process::timeout(10)->run(array_merge(['fuser'], $paths));

        if ($result->exitCode() === 127) {
            return true;
        }

        return $result->successful();
    }
}
