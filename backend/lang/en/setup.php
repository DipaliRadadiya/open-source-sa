<?php

return [

    'installing' => 'Installing :component',

    'detail' => [
        'cache_in_use' => 'in use for the panel cache',
    ],

    'components' => [
        'database' => [
            'title' => 'Database',
            'description' => 'Needed before you can install WordPress or any application that stores data.',
        ],
        'php' => [
            'title' => 'PHP',
            'description' => 'Add another version when a site needs one.',
        ],
        'node' => [
            'title' => 'Node.js',
            'description' => 'Managed with fnm, so sites can pin their own version.',
        ],
        'redis' => [
            'title' => 'Redis',
            'description' => 'Used for the panel cache. Without it the panel falls back to the database, which works but is slower.',
        ],
        'fail2ban' => [
            'title' => 'fail2ban',
            'description' => 'Blocks repeated failed logins against SSH and your sites.',
        ],
    ],

];
