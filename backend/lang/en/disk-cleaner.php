<?php

return [
    'apt_cache' => ['label' => 'Package cache', 'description' => 'Downloaded .deb package files that are no longer needed.'],
    'apt_orphans' => ['label' => 'Unused packages', 'description' => 'Automatically-installed packages and old kernels no longer required.'],
    'journal' => ['label' => 'System journal', 'description' => 'systemd journal entries older than the retention window.'],
    'rotated_logs' => ['label' => 'Rotated logs', 'description' => 'Old compressed and rotated log archives under /var/log.'],
    'service_logs' => ['label' => 'Service logs', 'description' => 'Empties the current log files of running services (kept, not deleted).'],
    'tmp' => ['label' => 'Temporary files', 'description' => 'Old files in /tmp and /var/tmp.'],
];
