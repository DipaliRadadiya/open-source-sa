<?php

return [
    'sync_failed' => 'Failed to apply the cron job on the server.',
    // One sentence per privileged step. They all used to share
    // `sync_failed`, so a full disk and a missing group read the same.
    'step' => [
        'log_dir' => 'The cron job log directory could not be created. Check there is free disk space and that /var/log is writable.',
        'log_touch' => "The cron job's log file could not be created. Usually the disk is full.",
        'log_chown' => 'The log file could not be handed to the account this job runs as. Check that account still exists.',
        'log_chmod' => "The log file's permissions could not be set.",
        'rotation' => 'The log rotation policy could not be installed, so the job was not scheduled — its output would grow without limit.',
        'write' => 'The cron file could not be written. Check there is free disk space.',
        'chmod' => "The cron file's permissions could not be set. Cron ignores a file it does not trust, so the job was not scheduled.",
        'remove' => 'The cron file could not be removed, so the job is still scheduled on the server.',
        'remove_stale' => 'The old cron file could not be removed after the rename. Nothing was changed, so the job is not scheduled twice.',
        'detach_source' => 'The original cron file this job was imported from could not be removed. Nothing was changed, so the command does not run twice.',
    ],
    'invalid_expression' => 'The schedule is not a valid cron expression.',
    'invalid_user' => 'The selected user does not exist on the server.',
    'unresolved_placeholder' => 'The command still contains the {path} placeholder — replace it with the application directory.',
    'no_newline' => 'This value may not contain line breaks.',
    'reserved_name' => 'This name is reserved and cannot be used.',
];
