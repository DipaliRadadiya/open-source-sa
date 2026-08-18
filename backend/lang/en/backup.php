<?php

return [
    'steps' => [
        'dump_database' => 'Dumping the database',
        'archive_files' => 'Creating the archive',
        'upload_artifact' => 'Uploading to storage',
        'verify_artifact' => 'Verifying the upload',
        'prune_old_backups' => 'Removing old backups',
        'rollback' => 'Cleaning up',
    ],
    'status' => [
        'pending' => 'Queued',
        'running' => 'Backing up',
        'verifying' => 'Verifying',
        'verified' => 'Complete',
        'failed' => 'Failed',
    ],
    'type' => [
        'filesystem' => 'Files',
        'database' => 'Database',
        'full' => 'Files and database',
    ],
    'frequency' => [
        'manual' => 'Manual only',
        'daily' => 'Daily',
        'weekly' => 'Weekly',
        'monthly' => 'Monthly',
    ],
    'errors' => [
        'restore_unverified' => 'This backup was never verified, so it cannot be restored.',
        'restore_no_application' => 'The application this backup belongs to no longer exists.',
        'restore_confirm' => 'Type the application domain exactly to confirm the restore.',
        'restore_already_running' => 'A restore for this application is already running.',
        'restore_no_database' => 'This backup does not contain a database.',
        'restore_no_files' => 'This backup does not contain any files.',
        'download_no_artifact' => 'This backup never finished uploading, so there is no archive to download.',
        'download_no_destination' => 'The storage destination this backup was uploaded to no longer exists.',
        'download_missing' => 'The archive is no longer on the storage destination.',
        'not_configured' => 'Backups are not set up for this application yet.',
        'delete_running' => 'This backup is still running, so it cannot be deleted yet. Wait for it to finish or fail.',
        'delete_artifact' => 'The archive could not be removed from the storage destination, so nothing was deleted. Check the destination is reachable and try again.',
        'delete_target_running' => 'A backup for this application is still running. Wait for it to finish before turning backups off.',
        'delete_target_has_backups' => 'This application still has :count backup(s). Confirm that they should be deleted too, or delete them first.',
        'already_running' => 'A backup for this application is already running.',
        'dump_database' => 'The database could not be dumped, so nothing was uploaded.',
        'archive_files' => 'The archive could not be created — usually the server is out of disk space.',
        'upload_artifact' => 'The archive could not be uploaded. Check the storage destination still accepts writes.',
        'verify_artifact' => 'The upload did not match what was sent, so this backup cannot be trusted. Nothing old was removed.',
        'unknown' => 'The backup failed for an unknown reason.',
        'prune_old_backups' => 'Old backups could not be removed. The new backup is safe; storage may just be holding more copies than the retention setting.',
        'retry_not_failed' => 'Only a failed backup can be retried.',
        'retry_no_target' => 'This backup has no associated configuration, so it cannot be retried.',
    ],

    'restore_status' => [
        'pending' => 'Queued',
        'running' => 'Restoring',
        'succeeded' => 'Restored',
        'failed' => 'Restore failed',
    ],

    'restore_steps' => [
        'download_artifact' => 'Downloading the backup',
        'verify_download' => 'Checking the backup is intact',
        'safety_backup' => 'Backing up the current state first',
        'extract_archive' => 'Unpacking the backup',
        'restore_database' => 'Restoring the database',
        'swap_files' => 'Putting the files in place',
        'restart_process' => 'Starting the application',
    ],

    'cloning' => [
        'provisioning' => 'Creating the site',
        'copying_files' => 'Copying files',
        'cloning_database' => 'Cloning the database',
        'starting_process' => 'Starting the application',
    ],

    'cloning_errors' => [
        'crashed' => 'The clone stopped unexpectedly.',
    ],

    'schedule_time' => 'Scheduled time',

    'restore_errors' => [
        'download_artifact' => 'The backup could not be downloaded. Nothing on the server was changed.',
        'verify_download' => 'The downloaded backup is incomplete or corrupt, so it was not used. Nothing on the server was changed.',
        'safety_backup' => 'A backup of the current state could not be taken, so the restore was stopped. Nothing was overwritten.',
        'extract_archive' => 'The backup could not be unpacked. Nothing on the server was changed.',
        'restore_database' => 'The database could not be restored. The safety backup taken beforehand holds the previous state.',
        'swap_files' => 'The files could not be put in place. The previous site directory was restored.',
        'restart_process' => 'The files and database were restored but the application would not start. Check its logs.',
        'missing_backup' => 'The backup was removed before the restore could start.',
        'crashed' => 'The restore stopped unexpectedly. Check the safety backup before trying again.',
        'unknown' => 'The restore failed for an unknown reason.',
    ],
];
