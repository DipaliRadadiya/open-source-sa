<?php

namespace App\Actions\Server\Cronjob;

use App\Exceptions\Server\Cronjob\CronjobOperationException;
use App\Models\Cronjob;
use App\Services\ActivityLogger;
use App\Services\Server\CrontabManager;

class DeleteCronjob
{
    public function __construct(
        private CrontabManager $crontab,
        private ActivityLogger $activityLogger,
    ) {}

    /**
     * No transaction, deliberately. It never bought anything here — the
     * ordering already does the work — and on SQLite it cost a great deal:
     * one writer at a time, so a transaction held open across `rm` blocks
     * every other write in the panel until the command returns, which is how
     * an unrelated request ends up answering "database is locked".
     */
    public function execute(Cronjob $cronjob): void
    {
        $name = $cronjob->name;

        // Remove the cron.d file first; a failed removal returns before the row
        // is deleted, so DB and disk stay consistent without a transaction
        // having to promise it.
        $result = $this->crontab->remove($cronjob);

        if ($result->failed()) {
            throw new CronjobOperationException($result->reference);
        }

        // The job is gone, so its output log has nothing left to describe.
        $this->crontab->removeLog($cronjob);

        $cronjob->delete();

        $this->activityLogger->log('cronjob.deleted', $cronjob, ['name' => $name]);
    }
}
