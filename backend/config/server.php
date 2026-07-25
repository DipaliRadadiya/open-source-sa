<?php

return [

    /*
    |--------------------------------------------------------------------------
    | System user home base
    |--------------------------------------------------------------------------
    |
    | Parent directory under which system users' home directories are created
    | (e.g. /home/<username>). Overridable so tests can point it at a writable
    | temp path.
    |
    */

    'home_base' => env('SERVER_HOME_BASE', '/home'),

    /*
    |--------------------------------------------------------------------------
    | Cron.d directory
    |--------------------------------------------------------------------------
    |
    | Directory where managed cron job files are written (one file per job).
    | Cron reads this automatically. Overridable so tests can point it at a
    | writable temp path.
    |
    */

    'cron_d' => env('SERVER_CRON_D', '/etc/cron.d'),

];
