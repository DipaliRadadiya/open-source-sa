<?php

return [
    'apt_cache' => ['label' => 'Package cache', 'description' => 'Downloaded .deb package files that are no longer needed.', 'note' => 'Removes only cached downloads under /var/cache/apt/archives — installed packages keep working.'],
    'apt_orphans' => ['label' => 'Unused packages', 'description' => 'Automatically-installed packages and old kernels no longer required.', 'note' => 'Removes packages nothing depends on anymore and superseded kernels; the running kernel is kept.'],
    'journal' => ['label' => 'System journal', 'description' => 'systemd journal entries older than the retention window.', 'note' => 'Trims old journal history beyond the retention window; recent entries are kept.'],
    'rotated_logs' => ['label' => 'Rotated logs', 'description' => 'Old compressed and rotated log archives under /var/log.', 'note' => 'Deletes already-rotated archives (.gz / .1 / .old) under /var/log; current logs are untouched.'],
    'service_logs' => ['label' => 'Service logs', 'description' => 'Empties the current log files of running services (kept, not deleted).', 'note' => 'Empties the current service log files (truncated to 0 bytes) — services keep writing to them, nothing is deleted.'],
    'tmp' => ['label' => 'Temporary files', 'description' => 'Old files in /tmp and /var/tmp.', 'note' => 'Deletes files in /tmp and /var/tmp older than the retention window.'],
];
