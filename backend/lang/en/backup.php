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
        'not_configured' => 'Backups are not set up for this application yet.',
        'already_running' => 'A backup for this application is already running.',
        'dump_database' => 'The database could not be dumped, so nothing was uploaded.',
        'archive_files' => 'The archive could not be created — usually the server is out of disk space.',
        'upload_artifact' => 'The archive could not be uploaded. Check the storage destination still accepts writes.',
        'verify_artifact' => 'The upload did not match what was sent, so this backup cannot be trusted. Nothing old was removed.',
        'unknown' => 'The backup failed for an unknown reason.',
        'prune_old_backups' => 'Old backups could not be removed. The new backup is safe; storage may just be holding more copies than the retention setting.',
    ],
];
