<?php

return [

    // Where one deploy got to. Shown as a badge on every row.
    'status' => [
        'queued' => 'कतार में',
        'running' => 'चल रहा है',
        'succeeded' => 'सफल',
        'failed' => 'विफल',
    ],

    // What started it. "Push" rather than "Webhook" because the user
    // thinks in terms of what they did, not how it reached us.
    'trigger' => [
        'manual' => 'मैनुअल',
        'webhook' => 'पुश',
        'redeploy' => 'पुनः चलाया',
        'initial' => 'पहला डिप्लॉय',
    ],

];
