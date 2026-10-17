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
        'container' => 'Container',
        'access' => 'Access log',
        'error' => 'Error log',
        'application' => 'Application output',
        'application_error' => 'Application errors',
        'waf_detect' => 'Firewall detections',
    ],

    'errors' => [
        'range_unsupported' => 'A line number only applies to a log file. The system journal and container output have no fixed line numbers — read the latest lines instead.',
        'fields_unsupported' => 'IP, status, method and URL filters only apply to the access log. Use the text search for this log.',

        'not_downloadable' => 'This log is not a file — a container\'s output is kept by Docker. Read it on screen instead.',
        'unknown_source' => 'That log does not exist for this application.',
        'clear_shared' => 'On OpenLiteSpeed the firewall detections are part of this application\'s access log. Clear the access log instead.',
    ],
];
