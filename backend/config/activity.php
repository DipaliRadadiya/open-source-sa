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
    'scopes' => [
        'account' => [
            'user',
            'role',
            'permission',
        ],

        'server' => [
            'application',
            'cronjob',
            'database',
            'disk_cleaner',
            'fail2ban',
            'firewall',
            'git_account',
            'log',
            'php',
            'runtime',
            'server',
            'service',
            'setting',
            'system_user',
        ],
    ],
];
