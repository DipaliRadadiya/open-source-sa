<?php

namespace App\Actions\Server\Cronjob;

use App\Exceptions\Server\Cronjob\CronjobOperationException;
use App\Models\Cronjob;
use App\Services\ActivityLogger;
use App\Services\Server\CronRunAsUser;
use App\Services\Server\CrontabManager;
use Illuminate\Support\Facades\Log;

class UpdateCronjob
{
    public function __construct(
        private CrontabManager $crontab,
        private ActivityLogger $activityLogger,
        private CronRunAsUser $runAsUser,
    ) {}

    /**
     * @param  array{name?: string, command?: string, expression?: string, system_user_id?: int|null, username?: string|null, active?: bool}  $data
     */
    public function execute(Cronjob $cronjob, array $data): Cronjob
    {
        // Path is derived from the slug, so a rename (new slug) relocates the
        // file. Regenerate the slug when the name changes.
        $oldPath = $this->crontab->path($cronjob);
        $oldSlug = (string) $cronjob->slug;

        if (isset($data['name']) && $data['name'] !== $cronjob->name) {
            $data['slug'] = Cronjob::uniqueSlug($data['name'], $cronjob->id);
        }

        // Resolved through the same check create uses, so a job can never be
        // pointed at an account that does not exist — cron accepts such a file
        // and then fails to run it, silently, on every tick.
        if (array_key_exists('system_user_id', $data) || array_key_exists('username', $data)) {
            $data = array_merge($data, $this->runAsUser->resolve(
                $data['system_user_id'] ?? null,
                $data['username'] ?? $cronjob->username,
            ));
        }

        // Kept so the row can be put back if the file operation refuses. No
        // transaction: one held open across `tee` blocks every other write in
        // the panel for the length of the command, and on SQLite that is the
        // whole panel.
        $before = $cronjob->getOriginal();

        $cronjob->update($data);

        // Re-materialise from the new state: active → (over)write the file,
        // inactive → remove it.
        //
        // Ordered before the old file is removed, not after. If this fails the
        // job is still described on disk exactly as it was, so a rename that
        // cannot be written leaves a working cronjob rather than none.
        try {
            if ($cronjob->active) {
                // Throws with the step that failed — write() knows which of its
                // seven privileged paths went wrong and nothing else can.
                $this->crontab->write($cronjob);
            } else {
                $removed = $this->crontab->remove($cronjob);

                if ($removed->failed()) {
                    throw new CronjobOperationException($removed->reference, step: 'remove');
                }
            }
        } catch (CronjobOperationException $e) {
            $cronjob->forceFill($before)->save();

            $this->activityLogger->log('cronjob.update_failed', $cronjob, [
                'name' => $cronjob->name,
                'step' => $e->step ?? '—',
            ]);

            throw $e;
        }

        if ($this->crontab->path($cronjob) !== $oldPath) {
            $removed = $this->crontab->removePath($oldPath);

            if ($removed->failed()) {
                // The old file still runs. Remove the newly-written file and
                // restore the row so one rename can never schedule two jobs.
                $cleanup = $cronjob->active ? $this->crontab->remove($cronjob) : null;
                if ($cleanup?->failed()) {
                    Log::warning('cronjob rename cleanup failed', ['reference' => $cleanup->reference]);
                }
                $cronjob->forceFill($before)->save();
                throw new CronjobOperationException($removed->reference, step: 'remove_stale');
            }

            // Carry the output history over to the new name rather than
            // stranding it under the old one.
            $this->crontab->moveLog($oldSlug, $cronjob);
        }

        // An adopted job is still described by the file Server Sync found it
        // in. Now that the panel has written its own — or removed it, for a job
        // just switched off — that one has to go, or the command runs twice and
        // switching a job off leaves it running.
        if ($cronjob->source_path) {
            $detached = $this->crontab->detachSource($cronjob);

            if ($detached?->failed()) {
                // Same rule as the rename above: one edit must never leave two
                // schedules behind, so ours goes rather than the original's.
                $cleanup = $cronjob->active ? $this->crontab->remove($cronjob) : null;
                if ($cleanup?->failed()) {
                    Log::warning('cronjob source detach cleanup failed', ['reference' => $cleanup->reference]);
                }
                $cronjob->forceFill($before)->save();
                throw new CronjobOperationException($detached->reference, step: 'detach_source');
            }

            $cronjob->forceFill(['source_path' => null])->save();
        }

        $this->activityLogger->log('cronjob.updated', $cronjob, ['name' => $cronjob->name]);

        return $cronjob;
    }
}
