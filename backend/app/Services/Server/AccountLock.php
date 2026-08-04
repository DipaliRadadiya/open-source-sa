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
 * The kernel exposes a *single* lock over /etc/passwd (and /etc/group,
 * /etc/shadow) shared by useradd, userdel, usermod, gpasswd, chpasswd and
 * passwd. Two of those running at once — even for *different* users — collide
 * with "cannot lock /etc/passwd; try again later". Per-username locking does
 * not help, because the OS lock is global, not per-user. So the panel funnels
 * every account command through one shared lock and runs them strictly one at
 * a time, mirroring the kernel's own single lock.
 *
 * This is the app-level half of the defence. ServerOps' transient retry is the
 * other half: it covers holders the panel cannot coordinate with (apt,
 * cloud-init, a hand-run adduser). Serialization removes self-collision; the
 * retry rides over external collision.
 */
class AccountLock
{
    /**
     * Run $callback while holding the global account-mutation lock. Callers
     * queue; only one runs at a time. A caller that waits out the whole window
     * gets an AccountBusyException (503 "try again") because nothing started.
     *
     * @template T
     *
     * @param  callable():T  $callback
     * @return T
     */
    public function run(callable $callback): mixed
    {
        $key = (string) config('server.account_lock.key', 'account:mutation');
        // TTL is the ceiling if the holder is *killed* mid-command; in normal
        // operation the lock is released the instant the callback returns. It
        // must exceed the slowest account command including ServerOps' own
        // transient retries (command timeout x attempts), or the lock would
        // expire mid-command and let a queued op collide.
        $ttl = max(1, (int) config('server.account_lock.ttl', 600));
        // How long a queued command waits for the lock before reporting busy.
        $wait = max(1, (int) config('server.account_lock.wait', 60));

        try {
            return Cache::lock($key, $ttl)->block($wait, $callback);
        } catch (LockTimeoutException) {
            // Waited the whole window and never acquired the lock — another
            // account command is still running. Nothing here started, so this
            // is "busy, try again", not a fault. Mirrors ServerOps' own busy
            // semantics for the same reason: the caller changed nothing.
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
