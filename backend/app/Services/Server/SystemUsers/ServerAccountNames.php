<?php

namespace App\Services\Server\SystemUsers;

use App\Services\Server\ServerOps;

/**
 * Is a name already a user *or a group* on this server?
 *
 * Both, because `useradd` refuses either: it makes a group of the same name,
 * so `docker`, `sudo` or `lxd` — groups with no user — fail with
 * "group … exists" (RC-27, 2026-10-22). Asking only `getent passwd` let the
 * panel accept such a name and then lose it inside the provisioning job.
 */
class ServerAccountNames
{
    public function __construct(private ServerOps $serverOps) {}

    /**
     * True when taken, false when free, null when the server could not be
     * asked — callers decide what an unanswerable check means for them.
     */
    public function taken(string $name): ?bool
    {
        foreach (['passwd', 'group'] as $database) {
            // getent exits 2 and prints nothing on a miss (SystemUsernameGenerator
            // explains why that, not `id`, is the shape ServerOps reads).
            $result = $this->serverOps->run(
                ['getent', $database, $name],
                ['feature' => 'system_user', 'op' => 'probe_'.$database],
                expectedExitCodes: [2],
            );

            if (! $result->answered) {
                return null;
            }

            // A hit prints its entry (`docker:x:988:`). Success with nothing
            // printed names no account, so it is not counted as one.
            if ($result->ok && trim($result->output()) !== '') {
                return true;
            }
        }

        return false;
    }
}
