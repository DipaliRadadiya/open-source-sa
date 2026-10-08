<?php

namespace App\Actions\Server\Application;

use App\Enums\BackupStatus;
use App\Exceptions\Server\Application\StagingBackupFailedException;
use App\Models\Application;
use App\Models\Backup;
use App\Models\BackupTarget;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\StagingManager;
use App\Services\Server\Backups\BackupRunner;

class PushStaging
{
    public function __construct(
        private StagingManager $staging,
        private ActivityLogger $activityLogger,
        private BackupRunner $backups,
    ) {}

    /**
     * @param  bool  $backup  FS-B10: take a backup of the live site through its
     *                        own backup setup first. The push starts only if
     *                        that backup verified — a push that went ahead
     *                        after a failed backup would leave the user
     *                        believing they had a way back.
     * @return Backup|null The backup taken first, when one was asked for.
     */
    public function execute(Application $production, string $mode, bool $backup = false, ?User $actor = null): ?Backup
    {
        $taken = $backup ? $this->backUp($production, $actor) : null;

        $this->staging->push($production, $mode);

        // One sentence per mode (ST-B4): the mode was a raw code inside the
        // sentence — "Pushed staging to my-blog (full)" — in every language.
        // `staging_pushed` stays for rows written before.
        $this->activityLogger->log('application.staging_pushed_'.$mode, $production, [
            'name' => $production->name,
        ]);

        return $taken;
    }

    private function backUp(Application $production, ?User $actor): Backup
    {
        $target = BackupTarget::with(['application', 'storageDestination'])
            ->where('application_id', $production->id)
            ->firstOrFail();

        $taken = $this->backups->run($target, $actor?->id);

        // The same entry a "Back up now" writes, so this backup reads the same
        // in the log as any other.
        $this->activityLogger->log(
            $taken->status === BackupStatus::Verified ? 'backup.completed' : 'backup.failed',
            $taken,
            ['application' => $production->name, 'reason' => $taken->reason ?? ''],
            $actor,
        );

        if ($taken->status !== BackupStatus::Verified) {
            throw new StagingBackupFailedException($taken);
        }

        return $taken;
    }
}
