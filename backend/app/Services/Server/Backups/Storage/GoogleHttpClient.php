<?php

namespace App\Services\Server\Backups\Storage;

use GuzzleHttp\Client as GuzzleClient;

/**
 * The HTTP client both Drive disks upload through.
 *
 * Exists for one setting: **cURL's low-speed abort**. A resumable upload that
 * Google has hung up on does not raise an error — the client sits in `poll()`
 * on a half-closed socket, transferring nothing, until something else kills it.
 * On the run that prompted this that "something else" was the job timeout an
 * hour later, and in the meantime the only queue worker was occupied, so every
 * other backup and restore on the box queued behind a transfer that was already
 * dead.
 *
 * `CURLOPT_LOW_SPEED_LIMIT`/`TIME` is cURL's own answer to exactly this, and it
 * is better than any timeout we could impose from outside because it measures
 * **bytes, not wall-clock**. A 24 GB archive on a thin link legitimately takes
 * hours; the same archive on a dead socket moves nothing. A wall-clock limit
 * cannot tell those apart and must therefore be set high enough to be useless.
 * A byte-rate floor separates them exactly.
 *
 * The floor is one byte per second, deliberately: this is a liveness check, not
 * a performance target. Anything above zero means the transfer is progressing
 * and is none of this class's business — a user on a slow link is not having a
 * failure, and a guard that fails them would be worse than the bug it replaces.
 *
 * `timeout => 0` is equally deliberate. Guzzle's total-request timeout would
 * apply to the whole multi-hour upload, so any finite value here reintroduces
 * the "killed at the limit regardless of health" bug this pair exists to
 * remove. `connect_timeout` stays finite because establishing a connection is
 * not a long operation under any circumstances.
 */
class GoogleHttpClient
{
    public static function make(): GuzzleClient
    {
        $stallSeconds = (int) config('server.backups.upload_stall_seconds', 1200);

        return new GuzzleClient([
            // No ceiling on the transfer itself; liveness is the low-speed
            // abort's job, below.
            'timeout' => 0,
            'connect_timeout' => 30,
            'curl' => [
                CURLOPT_LOW_SPEED_LIMIT => 1,
                CURLOPT_LOW_SPEED_TIME => $stallSeconds,
            ],
        ]);
    }
}
