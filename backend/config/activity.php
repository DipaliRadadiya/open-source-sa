<?php

/*
 * Which half of the panel an activity row is about.
 *
 * `account` describes the panel's own people — who logged in, who changed a
 * password, who was given which role. `server` describes the machine. One
 * question decides every row, and the two answers are two different screens.
 *
 * Every type in lang/activity.php must appear here exactly once; a test
 * asserts it. Without that, a feature added later belongs to neither scope and
 * quietly disappears from both screens — visible to nobody, and nothing fails.
 */

return [
    /*
    | Events no code records any more — the feature is gone. Their sentences
    | stay in lang/activity.php so old rows still read properly; they are left
    | out of the admin log's filter options, where they could only ever match
    | nothing. `database.optimized`/`repaired`: removed 2026-09-08 (see
    | routes/api/server/databases.php). `application.php_unisolated`: the
    | shared pool is no longer a choice.
    */
    'retired' => [
        'database.optimized',
        'database.repaired',
        'application.php_unisolated',
    ],

    'scopes' => [
        'account' => [
            'user',
            'role',
            'permission',
            // Who allowed the vendor's central panel in, and who took that
            // permission back. A consent decision, not a machine operation.
            'central',
        ],

        'server' => [
            'application',
            'backup',
            // The compiler toolchain. A fact about what this machine can
            // build, so it belongs beside the runtimes rather than beside the
            // people administering the panel.
            'build_tools',
            'cronjob',
            'database',
            'disk_cleaner',
            // Containerised database engines. Server, beside `database`, for the
            // same reason: it is an engine running on this machine, and the only
            // difference is that this one is in a container.
            'docker_database',
            'fail2ban',
            'firewall',
            'git_account',
            'log',
            'node',
            'panel_update',
            'php',
            // Stored registry credentials. Server rather than account, and the
            // distinction is worth stating: it is a credential the MACHINE uses to
            // pull images, not one of the panel's people — the same reading that
            // puts `git_account` and `storage_destination` on this side.
            'registry',
            'server',
            'service',
            'setting',
            // Where this machine's backups are sent. A connection made on the
            // server's behalf, and the row sits beside the backups it exists
            // to carry — not beside the people who administer the panel.
            'storage_destination',
            // Reading a migrated box into the panel. Squarely a machine
            // operation: it creates users, sites and databases in one go.
            'sync',
            'system_user',
            'wp_cli',
        ],
    ],
];
