<?php

namespace App\Actions\Server\Cronjob;

use App\Exceptions\Server\Cronjob\CronjobOperationException;
use App\Models\Cronjob;
use App\Services\ActivityLogger;
use App\Services\Server\CronRunAsUser;
use App\Services\Server\CrontabManager;
use App\Support\ActivityChanges;
use Illuminate\Support\Facades\Log;

class UpdateCronjob
{
    public function __construct(
        private CrontabManager $crontab,
        private ActivityLogger $activityLogger,
        private CronRunAsUser $runAsUser,
    ) {}

    /**
     * @param  array{name?: string, command?: string, expression?: string, system_user_id?: int|null, username?: string|null, active?: bool, application_owned?: bool}  $data
     */
    public function execute(Cronjob $cronjob, array $data): Cronjob
    {
        // The path derives from the slug, so a rename (new slug) relocates the file.
        $oldPath = $this->crontab->path($cronjob);
        $oldSlug = (string) $cronjob->slug;

        if (isset($data['name']) && $data['name'] !== $cronjob->name) {
            $data['slug'] = Cronjob::uniqueSlug($data['name'], $cronjob->id);
        }

        // Same check create uses: cron accepts a file for a missing account and then silently
        // fails to run it on every tick.
        if (array_key_exists('system_user_id', $data) || array_key_exists('username', $data)) {
            $data = array_merge($data, $this->runAsUser->resolve(
                $data['system_user_id'] ?? null,
                $data['username'] ?? $cronjob->username,
            ));
        }

        // Kept so the row can be put back if the file operation refuses. No transaction: one held
        // open across `tee` would block every other write (on SQLite, the whole panel).
        $before = $cronjob->getOriginal();

        // A job the panel created for a site is removed with the site. Once its command is edited
        // it becomes the user's. A caller that only swaps the PHP version (SwitchCronjobsPhp)
        // passes `application_owned` and the job stays the site's.
        if (isset($data['command']) && $data['command'] !== $cronjob->command && ! array_key_exists('application_owned', $data)) {
            $data['application_owned'] = false;
        }

        // Moved to another site (or to none): the user's from now on, not deleted with either site.
        if (array_key_exists('application_id', $data) && (int) $data['application_id'] !== (int) $cronjob->application_id && ! array_key_exists('application_owned', $data)) {
            $data['application_owned'] = false;
        }

        $cronjob->update($data);

        // Re-materialise from the new state: active writes the file, inactive removes it. Done
        // before the old file is removed, so a rename that cannot be written leaves a working job.
        try {
            if ($cronjob->active) {
                // Throws with the step that failed; only write() knows which one it was.
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

            // Carry the output history over to the new name.
            $this->crontab->moveLog($oldSlug, $cronjob);
        }

        // An adopted job is still described by the file Server Sync found it in. That file has to
        // go once the panel writes its own, or the command runs twice (and "off" stays running).
        if ($cronjob->source_path) {
            $detached = $this->crontab->detachSource($cronjob);

            if ($detached?->failed()) {
                // As with the rename: one edit must never leave two schedules, so ours goes.
                $cleanup = $cronjob->active ? $this->crontab->remove($cronjob) : null;
                if ($cleanup?->failed()) {
                    Log::warning('cronjob source detach cleanup failed', ['reference' => $cleanup->reference]);
                }
                $cronjob->forceFill($before)->save();
                throw new CronjobOperationException($detached->reference, step: 'detach_source');
            }

            $cronjob->forceFill(['source_path' => null, 'source_line' => null])->save();
        }

        $this->activityLogger->log('cronjob.updated', $cronjob, [
            'name' => $cronjob->name,
            'changes' => ActivityChanges::between($before, $cronjob->only(['name', 'username', 'command', 'expression', 'active']), ['name', 'username', 'command', 'expression', 'active']),
        ]);

        return $cronjob;
    }
}
