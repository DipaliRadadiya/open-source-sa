<?php

return [
    'busy' => 'The server is busy with another system task (a package install or update may be running). Nothing was changed — try again in a moment.',
    'databases_not_managed' => 'This server does not manage databases. It runs containers, and an application that needs a database brings its own.',
    'operation_timed_out' => 'The server took too long on this operation and stopped it. Nothing is broken — the work was too large for the time a single request allows. Anything bigger than this belongs in a Backup, which runs in the background and has no such limit.',
    'stale_lock' => 'A leftover lock file is blocking all user management on this server. Nothing is using it — an interrupted command left it behind. Run `php artisan panel:doctor` for the exact files to remove.',
    'sudo_denied' => 'This server\'s sudo grant is older than the panel running on it, so this command was refused before it ran. Nothing was changed and trying again will not help. Run `sudo php artisan panel:sudoers` on the server to rewrite the grant from the panel\'s own list, then retry.',
    // Before an action that writes a lot to the disk (2026-10-29).
    'not_enough_disk_space' => 'Not enough free disk space on the server: this needs about :needed and only :free is free to use. Free up space and try again.',
];
