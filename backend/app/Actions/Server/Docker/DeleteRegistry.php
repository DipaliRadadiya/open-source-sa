<?php

namespace App\Actions\Server\Docker;

use App\Models\Registry;
use App\Services\ActivityLogger;

class DeleteRegistry
{
    public function __construct(private ActivityLogger $activityLogger) {}

    /**
     * Delete the credential, and leave the sites that used it alone.
     *
     * The foreign key is `nullOnDelete`, so a site keeps running — a container
     * already pulled does not need the registry again until its next pull, and
     * that pull fails with a named reason. Both alternatives are worse: blocking
     * the delete makes a leaked credential unrevokable while any site references
     * it, and cascading would delete somebody's website because they rotated a
     * token.
     */
    public function execute(Registry $registry): void
    {
        // Counted before the delete, because afterwards there is nothing to ask.
        // This is the number the UI warns with and the number an audit wants.
        $affected = $registry->applications()->count();

        $this->activityLogger->log('registry.deleted', $registry, [
            'name' => $registry->name,
            'registry' => $registry->registry,
            'applications_detached' => $affected,
        ]);

        $registry->delete();
    }
}
