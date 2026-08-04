<?php

/*
 * Copy for an application's background workers.
 *
 * `presets` are the empty state — the flags people otherwise have to remember.
 * `checks` are the things that will stop a worker behaving as expected, found
 * before the user hits them.
 */

return [
    'kinds' => [
        'queue' => 'Queue worker',
        'horizon' => 'Horizon',
        'custom' => 'Custom',
    ],

    'states' => [
        'running' => 'Running',
        'degraded' => 'Partly running',
        'stopped' => 'Stopped',
    ],

    'presets' => [
        'queue' => [
            'title' => 'Queue worker',
            'description' => 'Processes queued jobs. The usual choice.',
        ],
        'horizon' => [
            'title' => 'Horizon',
            'description' => 'Supervises its own queue workers, with a dashboard. Use instead of a queue worker, not alongside it.',
        ],
        'custom' => [
            'title' => 'Custom command',
            'description' => 'Any long-running command, kept alive.',
        ],
    ],

    'checks' => [
        'cache_driver_array' => [
            'title' => 'Workers cannot be restarted automatically',
            'detail' => 'This application uses the "array" cache driver, which does not persist between processes. Laravel restarts workers by leaving a flag in the cache, so the command will succeed and nothing will happen — after a deploy your workers keep running the old code. Use redis, database or file instead.',
        ],
    ],

    'errors' => [
        'queue_conflict' => 'This application already has the other kind of queue worker. Horizon supervises its own workers, so running both means every job is handled twice.',
    ],
];
