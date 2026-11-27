<?php

namespace App\Exceptions\Server\Application;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Workers need supervisord, and this server has not got it.
 *
 * Workers run as supervisord programs, and `install.sh` installs the package —
 * which reaches **new installs only**. A panel installed before workers moved
 * to supervisord has no supervisor, no `/etc/supervisor/conf.d`, and no way to
 * acquire either: the updater ships code, never packages. Without this, creating
 * a worker fails with `tee: /etc/supervisor/conf.d/…: No such file or directory`
 * behind a generic "Server operation failed", which names the symptom and hides
 * both the cause and the cure.
 *
 * 422 rather than 500: nothing is broken. The server is missing a package, the
 * request was refused before anything was written, and the one-line fix is in
 * the message.
 */
class SupervisorMissingException extends Exception
{
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => __('errors/application.supervisor_missing'),
        ], 422);
    }
}
