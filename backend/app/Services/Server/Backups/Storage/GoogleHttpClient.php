<?php

namespace App\Services\Server\Backups\Storage;

use GuzzleHttp\Client as GuzzleClient;

/**
 * The HTTP client both Drive disks upload through.
 *
 * Exists for one setting: **cURL's low-speed abort**. A resumable upload that Google has hung up
 * on raises no error; the client sits on a half-closed socket until the job timeout, blocking
 * the queue worker for every other backup and restore.
 *
 * `CURLOPT_LOW_SPEED_LIMIT`/`TIME` measures **bytes, not wall-clock**, so a huge archive on a
 * thin link still passes while a dead socket is aborted. A wall-clock limit cannot tell them apart.
 *
 * The floor is one byte per second on purpose: a liveness check, not a performance target.
 *
 * `timeout => 0` is also deliberate: Guzzle's total-request timeout would cap the whole
 * multi-hour upload. `connect_timeout` stays finite.
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
