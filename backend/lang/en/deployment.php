<?php

return [

    // Where one deploy got to. Shown as a badge on every row.
    'status' => [
        'queued' => 'Queued',
        'running' => 'Running',
        'succeeded' => 'Succeeded',
        'failed' => 'Failed',
    ],

    // What started it. "Push" rather than "Webhook" because the user
    // thinks in terms of what they did, not how it reached us.
    'trigger' => [
        'manual' => 'Manual',
        'webhook' => 'Push',
        'redeploy' => 'Re-run',
        'initial' => 'First deploy',
    ],

    'script_php_missing' => 'Your deploy script uses :variables, but PHP :versions is not installed on this server. Install it on the PHP screen, or use {php} for the site\'s own version.',

];
