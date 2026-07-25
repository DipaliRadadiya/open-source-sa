<?php

namespace App\Actions\Server\Cronjob;

use App\Exceptions\Server\Cronjob\CronjobOperationException;
use App\Models\Cronjob;
use App\Services\ActivityLogger;
use App\Services\Server\CrontabManager;
use Illuminate\Support\Facades\DB;

class UpdateCronjob
{
    public function __construct(
        private CrontabManager $crontab,
        private ActivityLogger $activityLogger,
    ) {}

    /**
     * @param  array{name?: string, command?: string, expression?: string, active?: bool}  $data
     */
    public function execute(Cronjob $cronjob, array $data): Cronjob
    {
        return DB::transaction(function () use ($cronjob, $data) {
            // Path is derived from the name, so a rename relocates the file.
            $oldPath = $this->crontab->path($cronjob);

            $cronjob->update($data);

            if ($this->crontab->path($cronjob) !== $oldPath) {
                $this->crontab->removePath($oldPath);
            }

            // Re-materialise from the new state: active → (over)write the file,
            // inactive → remove it. A failed op aborts the transaction.
            $result = $cronjob->active
                ? $this->crontab->write($cronjob)
                : $this->crontab->remove($cronjob);

            if ($result->failed()) {
                throw new CronjobOperationException($result->reference);
            }

            $this->activityLogger->log('cronjob.updated', $cronjob, ['name' => $cronjob->name]);

            return $cronjob;
        });
    }
}
