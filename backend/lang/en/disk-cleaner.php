<?php

return [
    'apt_cache' => ['label' => 'Package cache', 'description' => 'Downloaded .deb package files.', 'note' => 'Delete all empties the cache; a keep choice removes only downloads older than it. Installed packages keep working.'],
    'apt_orphans' => ['label' => 'Unused packages', 'description' => 'Automatically-installed packages and old kernels no longer required.', 'note' => 'Removes packages nothing depends on anymore and superseded kernels; the running kernel is kept.'],
    'journal' => ['label' => 'System journal', 'description' => 'systemd journal history.', 'note' => 'Removes journal entries older than your keep choice; Delete all removes all of them.'],
    'rotated_logs' => ['label' => 'Rotated logs', 'description' => 'Old compressed and rotated log archives under /var/log.', 'note' => 'Deletes rotated archives (.gz / .1 / .old) older than your keep choice, or all of them with Delete all. Current logs are untouched.'],
    'service_logs' => ['label' => 'Service logs', 'description' => 'Current log files of running services.', 'note' => 'Delete all empties them (the files stay and services keep writing). A keep choice empties only logs nothing has written to within it.'],
    'site_logs' => ['label' => 'Application logs', 'description' => 'Every hosted application\'s access and error logs and their rotated copies.', 'note' => 'Deletes rotated copies older than your keep choice and empties logs nothing has written to within it. Delete all empties every log and deletes every copy: the visitor and error history is gone.'],
    'tmp' => ['label' => 'Temporary files', 'description' => 'Files in /tmp and /var/tmp.', 'note' => 'Deletes files older than your keep choice. Delete all still keeps files written in the last hour, so running installs and uploads are not broken.'],
    // The keep choice in words, for the activity log.
    'keep_days' => ['default' => 'standard retention', 'delete_all' => 'deleted all', 'keep' => 'kept :days day|kept :days days'],
];
