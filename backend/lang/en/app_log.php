<?php

/*
 * Copy for an application's own logs.
 *
 * `sources` keys mirror ApplicationLogManager's catalog. `application` is the
 * process output (systemd journal) and only appears for a supervised app —
 * for a Node site the web-server logs describe the proxy, not the app.
 */

return [
    'sources' => [
        'access' => 'Access log',
        'error' => 'Error log',
        'application' => 'Application output',
    ],

    'errors' => [
        'unknown_source' => 'That log does not exist for this application.',
    ],
];
