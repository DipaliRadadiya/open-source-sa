<?php

namespace App\Actions\Server\Cronjob;

use App\Exceptions\Server\Cronjob\CronjobOperationException;
use App\Models\Cronjob;
use App\Services\ActivityLogger;
use App\Services\Server\Cron\CronPhpBinary;

/**
 * Point the cron jobs a user ticked at another PHP version.
 *
 * Only the interpreter's word in each command changes, and each job goes
 * through the ordinary edit, so its cron.d file is written with every check
 * an edit has and put back if the write fails. The job stays the site's
 * (`application_owned` is kept): swapping the PHP it runs on is not the user
 * taking it over.
 *
 * One job at a time. A failure stops there and throws; the jobs before it are
 * switched, each with its own activity line, and the one that failed is left
 * exactly as it was.
 */
class SwitchCronjobsPhp
{
    public function __construct(
        private UpdateCronjob $update,
        private CronPhpBinary $binary,
        private ActivityLogger $activityLogger,
    ) {}

    /**
     * @param  iterable<Cronjob>  $cronjobs  already checked to be the site's and switchable
     * @return array{switched: array<int, Cronjob>, unchanged: array<int, Cronjob>}
     *
     * @throws CronjobOperationException
     */
    public function execute(iterable $cronjobs, string $version): array
    {
        $switched = [];
        $unchanged = [];

        foreach ($cronjobs as $cronjob) {
            $from = $this->binary->version((string) $cronjob->command);
            $command = $this->binary->switchTo((string) $cronjob->command, $version);

            // Already on it: rewriting the file would change nothing.
            if ($command === null || $command === $cronjob->command) {
                $unchanged[] = $cronjob;

                continue;
            }

            $this->update->execute($cronjob, [
                'command' => $command,
                'application_owned' => (bool) $cronjob->application_owned,
            ]);

            $this->activityLogger->log('cronjob.php_switched', $cronjob, [
                'name' => $cronjob->name,
                // `default` stays a code; the activity screen words it.
                'from' => $from,
                'to' => $version,
            ]);

            $switched[] = $cronjob;
        }

        return ['switched' => $switched, 'unchanged' => $unchanged];
    }
}
