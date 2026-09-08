<?php

namespace App\Exceptions\Server\Application;

use App\Exceptions\Server\ServerOperationException;

/**
 * supervisorctl could not be run, so the worker was not controlled.
 *
 * It exists for one failure the worker screens could not previously describe:
 * sudo refusing `supervisorctl` outright. `supervisorctl` joined
 * `server.privilege.binaries` when workers moved to supervisord on 2026-09-07,
 * and a server whose sudoers file predates that never got the line — the grant
 * is rewritten by install.sh and by the update's `sync_privileges` step, and
 * that step is deliberately non-fatal, so a server can be several binaries
 * behind the code it runs and report nothing.
 *
 * What that looked like on 2026-09-08: supervisord installed, the program file
 * written, and `sudo -n supervisorctl update` answering "a password is
 * required" — surfaced to the user as "Server operation failed." A real cause
 * with the cure removed, and the second time in two days that this feature
 * told someone their server was broken when it was merely missing a line.
 *
 * The base class does the rest: `denied` selects `errors/server.sudo_denied`,
 * which names `artisan panel:sudoers`, over this class's own message.
 */
class WorkerControlException extends ServerOperationException
{
    protected function messageKey(): string
    {
        return 'errors/application.worker_control_failed';
    }

    protected function code(): string
    {
        return 'worker_control_failed';
    }
}
