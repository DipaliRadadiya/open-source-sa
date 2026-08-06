<?php

/*
 * Copy for the panel self-update screen. `steps` mirrors UpdateScript::STEPS
 * one-for-one — the runner writes the step key into its state file and the
 * screen translates it, so a step added there needs a line added here.
 */

return [
    'status' => [
        'pending' => 'Starting',
        'running' => 'Updating',
        'succeeded' => 'Up to date',
        'failed' => 'Update failed',
    ],

    'steps' => [
        'maintenance_on' => 'Putting the panel into maintenance mode',
        'backup_database' => 'Backing up the database',
        'fetch_release' => 'Downloading the release',
        'checkout_release' => 'Switching to the new version',
        'composer_install' => 'Installing PHP dependencies',
        'migrate' => 'Updating the database schema',
        'seed_permissions' => 'Syncing permissions',
        'resync_site_configs' => 'Refreshing site configs',
        'configure_services' => 'Tuning services',
        'optimize' => 'Rebuilding caches',
        'frontend_build' => 'Building the interface (this is the slow part)',
        'restart_services' => 'Restarting services',
        'maintenance_off' => 'Leaving maintenance mode',
        'health_check' => 'Verifying the new version',
        'rollback' => 'Restoring the previous version',
    ],

    /*
     * A failure reason is the step that failed, so the message says what was
     * being attempted rather than quoting an error the user cannot act on.
     * The panel is always rolled back to the previous version first.
     */
    'reasons' => [
        'launch' => 'The update could not be started.',
        'maintenance_on' => 'The panel could not be put into maintenance mode.',
        'backup_database' => 'The database backup failed, so the update stopped before changing anything.',
        'fetch_release' => 'The release could not be downloaded. Check the server can reach the internet.',
        'checkout_release' => 'The new version could not be applied to the installation directory.',
        'composer_install' => 'PHP dependencies could not be installed.',
        'migrate' => 'The database schema update failed. The database backup taken beforehand is in storage/app/panel-backups.',
        'seed_permissions' => 'Permissions could not be synced.',
        'resync_site_configs' => 'Site configs could not be refreshed.',
        'configure_services' => 'Services could not be tuned.',
        'optimize' => 'Caches could not be rebuilt.',
        'frontend_build' => 'The interface could not be built. This usually means the server ran out of memory or disk space.',
        'restart_services' => 'Services could not be restarted.',
        'maintenance_off' => 'The panel could not be taken out of maintenance mode.',
        'health_check' => 'The updated panel did not answer correctly, so the previous version was restored.',
        'unknown' => 'The update failed for an unknown reason.',
    ],

    'errors' => [
        'in_progress' => 'An update is already running.',
        'no_update' => 'The panel is already on the newest version.',
        'bad_version' => 'The published version could not be read.',
        'preflight_failed' => 'The server is not ready to update. Check the preflight results.',
    ],
];
