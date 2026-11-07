<?php

namespace App\Actions\Server\Docker;

use App\Models\DockerDatabase;
use App\Services\ActivityLogger;
use App\Services\Server\Docker\DatabaseContainerFailedException;
use App\Services\Server\Docker\DatabaseContainerManager;
use App\Services\Server\Docker\DockerResources;

/**
 * Remove a containerised database.
 *
 * The data is a separate decision from the container, and it defaults to keeping
 * it. Deleting a site does the same: a plain delete removes what the panel manages
 * and leaves the volume, because the one irreversible thing here is somebody's
 * data and the panel should not be the thing that guesses.
 *
 * Containers first, volume second, and that order is not cosmetic: `docker volume
 * rm` refuses a volume any container references, running or not, so a removal
 * attempted before `compose down` fails — which the site path already learned on a
 * real box.
 */
class DeleteDockerDatabase
{
    public function __construct(
        private ActivityLogger $activityLogger,
        private DatabaseContainerManager $containers,
        private DockerResources $docker,
    ) {}

    /**
     * The row goes last, and only when the server side really went: a row
     * deleted over a failure is a running container the panel no longer lists.
     * A retry after a failure is safe — `down` on a container already gone,
     * and `volume rm` on a volume already gone, both count as done.
     *
     * @throws DatabaseContainerFailedException
     */
    public function execute(DockerDatabase $database, bool $removeData = false): void
    {
        $volume = $database->volume();

        $this->containers->remove($database);

        if ($removeData) {
            $removed = $this->docker->removeVolume($volume);

            if ($removed->failed() && ! str_contains(strtolower($removed->errorOutput()), 'no such volume')) {
                throw new DatabaseContainerFailedException('volume_remove', $removed->reference);
            }
        }

        $this->activityLogger->log('docker_database.deleted', $database, [
            'name' => $database->name,
            'engine' => $database->engine,
            // The question asked after an incident is whether the data went, so
            // that is what the row records.
            'data_removed' => $removeData,
        ]);

        $database->delete();
    }
}
