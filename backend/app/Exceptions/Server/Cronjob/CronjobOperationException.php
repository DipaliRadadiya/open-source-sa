<?php

namespace App\Exceptions\Server\Cronjob;

use App\Exceptions\Server\ServerOperationException;

class CronjobOperationException extends ServerOperationException
{
    /**
     * The steps a cron job write goes through, each of which can fail on its
     * own and for its own reason.
     *
     * Named because they used to be anonymous: every one of these produced the
     * same sentence — "Failed to apply the cron job on the server" — so a full
     * disk, a missing group and a read-only /etc were indistinguishable to the
     * person who had to fix one of them. The step is the difference between an
     * error and a diagnosis.
     *
     * @var array<int, string>
     */
    public const STEPS = [
        'log_dir',      // mkdir -p the shared cron log directory
        'log_touch',    // create this job's log file
        'log_chown',    // hand it to the account the job runs as
        'log_chmod',    // 0640 — a job's output is not for other accounts
        'rotation',     // install the logrotate policy
        'write',        // the cron.d file itself
        'chmod',        // 0644, or cron ignores it
        'remove',       // deleting the file for a job switched off
        'remove_stale', // deleting the old file after a rename
        'detach_source', // deleting the file an adopted job came from
    ];

    public function __construct(
        string $reference,
        bool $busy = false,
        bool $staleLock = false,
        /**
         * Which step failed. Null only where the caller genuinely cannot say —
         * every path that knows should pass it.
         */
        public readonly ?string $step = null,
    ) {
        parent::__construct($reference, $busy, $staleLock);
    }

    protected function messageKey(): string
    {
        $key = 'errors/cronjob.step.'.$this->step;

        // Falls back rather than rendering the key at the user, the same
        // contract DatabaseExport::message() follows. A step added here without
        // its sentence degrades to the old generic message instead of leaking
        // `errors/cronjob.step.whatever` onto the screen.
        return $this->step !== null && trans()->has($key)
            ? $key
            : 'errors/cronjob.sync_failed';
    }

    protected function code(): string
    {
        return $this->step !== null
            ? 'cronjob_'.$this->step
            : 'cronjob_operation_failed';
    }
}
