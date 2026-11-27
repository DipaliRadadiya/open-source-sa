<?php

namespace App\Exceptions\Server\Application;

use App\Exceptions\Server\ServerOperationException;

/**
 * supervisorctl could not be run, so the worker was not controlled.
 *
 * It exists for one failure the worker screens could not otherwise describe:
 * sudo refusing `supervisorctl` outright. `supervisorctl` is in
 * `server.privilege.binaries`, and a server whose sudoers file predates that
 * never got the line — the grant is rewritten by install.sh and by the
 * update's `sync_privileges` step, and that step is deliberately non-fatal,
 * so a server can be several binaries behind the code it runs and report
 * nothing.
 *
 * What that looks like: supervisord installed, the program file written, and
 * `sudo -n supervisorctl update` answering "a password is required" — which
 * would reach the user as "Server operation failed.", a real cause with the
 * cure removed, when the server is merely missing a line.
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
