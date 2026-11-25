<?php

namespace App\Services\Server;

use App\Exceptions\Server\SystemUser\AccountBusyException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Serialize every operation that mutates the OS user/group database.
 *
 * The OS has a single lock over /etc/passwd (and group/shadow) shared by useradd, userdel,
 * usermod, gpasswd, chpasswd and passwd; two at once, even for different users, fail with
 * "cannot lock /etc/passwd". So every account command runs through one shared lock.
 *
 * This removes self-collision; ServerOps' transient retry covers external holders (apt, cloud-init).
 */
class AccountLock
{
    /**
     * Run $callback while holding the global account-mutation lock. Callers queue; a caller that
     * waits out the whole window gets an AccountBusyException (503 "try again").
     *
     * @template T
     *
     * @param  callable():T  $callback
     * @return T
     */
    public function run(callable $callback): mixed
    {
        $key = (string) config('server.account_lock.key', 'account:mutation');
        // TTL is the ceiling if the holder is killed mid-command. It must exceed the slowest
        // account command including ServerOps' transient retries, or the lock expires mid-command.
        $ttl = max(1, (int) config('server.account_lock.ttl', 600));
        // How long a queued command waits before reporting busy.
        $wait = max(1, (int) config('server.account_lock.wait', 60));

        try {
            return Cache::lock($key, $ttl)->block($wait, $callback);
        } catch (LockTimeoutException) {
            // Nothing started, so this is "busy, try again", not a fault (same as ServerOps' busy).
            $reference = (string) Str::uuid();
            Log::channel('server-ops')->warning('account operation lock: wait timed out', [
                'reference' => $reference,
                'key' => $key,
                'waited_seconds' => $wait,
            ]);

            throw new AccountBusyException($reference, busy: true);
        }
    }
}
