<?php

return [
    'busy' => 'The server is busy with another system task (a package install or update may be running). Nothing was changed — try again in a moment.',
    'stale_lock' => 'A leftover lock file is blocking all user management on this server. Nothing is using it — an interrupted command left it behind. Run `php artisan panel:doctor` for the exact files to remove.',
];
