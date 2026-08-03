<?php

return [

    // Where one deploy got to. Shown as a badge on every row.
    'status' => [
        'queued' => 'En file',
        'running' => 'En cours',
        'succeeded' => 'Réussi',
        'failed' => 'Échoué',
    ],

    // What started it. "Push" rather than "Webhook" because the user
    // thinks in terms of what they did, not how it reached us.
    'trigger' => [
        'manual' => 'Manuel',
        'webhook' => 'Push',
        'redeploy' => 'Relance',
    ],

];
