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
        'not_configured' => 'Backups are not set up for this application yet.',
        'already_running' => 'A backup for this application is already running.',
        'dump_database' => 'The database could not be dumped, so nothing was uploaded.',
        'archive_files' => 'The archive could not be created — usually the server is out of disk space.',
        'upload_artifact' => 'The archive could not be uploaded. Check the storage destination still accepts writes.',
        'verify_artifact' => 'The upload did not match what was sent, so this backup cannot be trusted. Nothing old was removed.',
        'unknown' => 'The backup failed for an unknown reason.',
        'prune_old_backups' => 'Old backups could not be removed. The new backup is safe; storage may just be holding more copies than the retention setting.',
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
