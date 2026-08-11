<?php

/*
 * Human labels for the login shells a system user can be given. The stored
 * value is the binary path `usermod -s` needs; these are what the panel shows
 * instead, because "/usr/sbin/nologin" does not tell a non-sysadmin that they
 * are turning login off.
 */

return [
    'bash' => [
        'title' => 'Full shell access (bash)',
        'description' => 'The standard Linux shell. The user can log in over SSH and run commands.',
    ],
    'sh' => [
        'title' => 'Basic shell (sh)',
        'description' => 'A minimal shell. The user can log in and run commands, with fewer conveniences than bash.',
    ],
    'zsh' => [
        'title' => 'Full shell access (zsh)',
        'description' => 'Like bash, with a different set of conveniences. The user can log in and run commands.',
    ],
    'nologin' => [
        'title' => 'No login',
        'description' => 'The user owns its files and runs the site, but cannot log in. Recommended for sites that nobody needs shell access to.',
    ],
    'false' => [
        'title' => 'No login (legacy)',
        'description' => 'Login is refused immediately. Same effect as “No login”; kept for servers that already use it.',
    ],
];
