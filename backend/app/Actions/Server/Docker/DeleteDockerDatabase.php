<?php

namespace App\Actions\Server\Docker;

use App\Models\DockerDatabase;
use App\Services\ActivityLogger;
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

    public function execute(DockerDatabase $database, bool $removeData = false): void
    {
        $volume = $database->volume();

        $this->activityLogger->log('docker_database.deleted', $database, [
            'name' => $database->name,
            'engine' => $database->engine,
            // The question asked after an incident is whether the data went, so
            // that is what the row records.
            'data_removed' => $removeData,
        ]);

        $this->containers->remove($database);

        if ($removeData) {
            $this->docker->removeVolume($volume);
        }

        $database->delete();
    }
}
