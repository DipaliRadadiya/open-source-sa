<?php

namespace App\Services\Server\Doctor\Checks;

use App\Contracts\DoctorCheck;
use App\Models\SystemUser;
use App\Services\Server\SystemUsers\HomeDirectoryAccess;

/**
 * No system user's home lets other local accounts in — see HomeDirectoryAccess.
 *
 * An open home is every file its applications wrote with ordinary permissions
 * — sessions, `.env`, database files — readable by every other site's user.
 * `sites:resync` closes homes on every deploy, so one found open is one resync
 * left open on purpose (a site in the shared PHP pool) or could not reach.
 *
 * Read-only, like SiteRootLockCheck: closing a home before the web server is
 * in its group takes that user's sites down, and a health check is the wrong
 * place to decide that.
 */
class HomeAccessCheck implements DoctorCheck
{
    public function __construct(private HomeDirectoryAccess $homeAccess) {}

    public function key(): string
    {
        return 'home_access';
    }

    public function run(): array
    {
        $open = [];
        $total = 0;

        foreach (SystemUser::query()->get() as $user) {
            // Null: not a home under the home base, or no answer — neither is
            // evidence that it is open.
            if (($isOpen = $this->homeAccess->isOpen($user)) === null) {
                continue;
            }

            $total++;

            if ($isOpen) {
                $open[] = $user->username;
            }
        }

        if ($open === []) {
            return [
                'status' => 'pass',
                'detail' => $total === 0 ? 'no system users' : $total.' home(s) closed to other users',
                'fix' => null,
            ];
        }

        return [
            'status' => 'warn',
            'detail' => 'open to other users: '.implode(', ', $open),
            'fix' => 'doctor.fixes.home_open',
        ];
    }
}
