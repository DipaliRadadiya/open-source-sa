<?php

namespace App\Actions\Server\Cronjob;

use App\Exceptions\Server\Cronjob\CronjobOperationException;
use App\Models\Cronjob;
use App\Services\ActivityLogger;
use App\Services\Server\CrontabManager;
use Illuminate\Support\Facades\DB;

class DeleteCronjob
{
    public function __construct(
        private CrontabManager $crontab,
        private ActivityLogger $activityLogger,
    ) {}

    public function execute(Cronjob $cronjob): void
    {
        DB::transaction(function () use ($cronjob) {
            $name = $cronjob->name;

            // Remove the cron.d file first; a failed removal aborts before the
            // row is deleted, so DB and disk stay consistent.
            $result = $this->crontab->remove($cronjob);

            if ($result->failed()) {
                throw new CronjobOperationException($result->reference);
            }

            // The job is gone, so its output log has nothing left to describe.
            $this->crontab->removeLog($cronjob);

            $cronjob->delete();

            $this->activityLogger->log('cronjob.deleted', $cronjob, ['name' => $name]);
        });
    }
}
