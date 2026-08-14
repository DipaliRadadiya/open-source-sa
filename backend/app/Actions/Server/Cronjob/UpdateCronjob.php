<?php

namespace App\Actions\Server\Cronjob;

use App\Exceptions\Server\Cronjob\CronjobOperationException;
use App\Models\Cronjob;
use App\Services\ActivityLogger;
use App\Services\Server\CrontabManager;
use Illuminate\Support\Facades\Log;

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
        // Path is derived from the slug, so a rename (new slug) relocates the
        // file. Regenerate the slug when the name changes.
        $oldPath = $this->crontab->path($cronjob);
        $oldSlug = (string) $cronjob->slug;

        if (isset($data['name']) && $data['name'] !== $cronjob->name) {
            $data['slug'] = Cronjob::uniqueSlug($data['name'], $cronjob->id);
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
        $result = $cronjob->active
            ? $this->crontab->write($cronjob)
            : $this->crontab->remove($cronjob);

        if ($result->failed()) {
            $cronjob->forceFill($before)->save();

            throw new CronjobOperationException($result->reference);
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
                throw new CronjobOperationException($removed->reference);
            }

            // Carry the output history over to the new name rather than
            // stranding it under the old one.
            $this->crontab->moveLog($oldSlug, $cronjob);
        }

        $this->activityLogger->log('cronjob.updated', $cronjob, ['name' => $cronjob->name]);

        return $cronjob;
    }
}
