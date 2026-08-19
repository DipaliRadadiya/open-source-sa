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
            $this->activityLogger->log('cronjob.delete_failed', $cronjob, [
                'name' => $cronjob->name,
                'step' => 'remove',
            ]);

            throw new CronjobOperationException($result->reference, step: 'remove');
        }

        // An adopted job also has the file Server Sync read it from, which the
        // panel never wrote and `remove()` therefore never touches. Deleting
        // without this reports the job gone while it goes on running — checked
        // before the row is dropped, so a failure leaves the panel still
        // describing what is on the server.
        $detached = $this->crontab->detachSource($cronjob);

        if ($detached?->failed()) {
            $this->activityLogger->log('cronjob.delete_failed', $cronjob, [
                'name' => $cronjob->name,
                'step' => 'detach_source',
            ]);

            throw new CronjobOperationException($detached->reference, step: 'detach_source');
        }

        // The job is gone, so its output log has nothing left to describe.
        $this->crontab->removeLog($cronjob);

        $cronjob->delete();

        $this->activityLogger->log('cronjob.deleted', $cronjob, ['name' => $name]);
    }
}
