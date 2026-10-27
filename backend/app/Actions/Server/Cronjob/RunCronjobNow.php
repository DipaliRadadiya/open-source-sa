<?php

namespace App\Actions\Server\Cronjob;

use App\Exceptions\Server\Cronjob\CronjobOperationException;
use App\Models\Cronjob;
use App\Services\ActivityLogger;
use App\Services\Server\CrontabManager;
use Illuminate\Validation\ValidationException;

/**
 * "Run now": start the job once, immediately, in the background.
 *
 * Its output and exit land in the job's own log, marked as manual, so the
 * run shows up in the history beside cron's.
 */
class RunCronjobNow
{
    public function __construct(
        private CrontabManager $crontab,
        private ActivityLogger $activityLogger,
    ) {}

    /**
     * @throws ValidationException when the job cannot be run now
     * @throws CronjobOperationException when the server refused to start it
     */
    public function execute(Cronjob $cronjob): void
    {
        // A paused job is paused on purpose; running it is turning it on.
        if (! $cronjob->active) {
            throw ValidationException::withMessages(['cronjob' => [__('errors/cronjob.run_paused')]]);
        }

        if (! $this->crontab->userExists($cronjob->username)) {
            throw ValidationException::withMessages(['cronjob' => [__('errors/cronjob.invalid_user')]]);
        }

        // Only a Run now still going is refused. A run cron started is
        // allowed alongside, as cron itself would allow two of its own.
        if ($this->crontab->manualRunActive($cronjob)) {
            throw ValidationException::withMessages(['cronjob' => [__('errors/cronjob.run_already_running')]]);
        }

        $this->crontab->ensureLog($cronjob);

        $result = $this->crontab->startManualRun($cronjob);

        if ($result->failed()) {
            // Two clicks at once: the unit name is taken by the other one.
            if ($this->crontab->manualRunActive($cronjob)) {
                throw ValidationException::withMessages(['cronjob' => [__('errors/cronjob.run_already_running')]]);
            }

            $this->activityLogger->log('cronjob.run_failed', $cronjob, ['name' => $cronjob->name]);

            throw new CronjobOperationException(
                $result->reference,
                busy: $result->busy,
                staleLock: $result->staleLock,
                denied: $result->denied,
                step: 'run_now',
            );
        }

        $this->activityLogger->log('cronjob.ran', $cronjob, ['name' => $cronjob->name]);
    }
}
