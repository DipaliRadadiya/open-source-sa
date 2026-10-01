<?php

return [

    // Where one deploy got to. Shown as a badge on every row.
    'status' => [
        'queued' => 'In Warteschlange',
        'running' => 'Läuft',
        'succeeded' => 'Erfolgreich',
        'failed' => 'Fehlgeschlagen',
    ],

    // What started it. "Push" rather than "Webhook" because the user
    // thinks in terms of what they did, not how it reached us.
    'trigger' => [
        'manual' => 'Manuell',
        'webhook' => 'Push',
        'redeploy' => 'Erneut ausgeführt',
        'initial' => 'Erstes Deployment',
    ],

    'script_php_missing' => 'Ihr Deploy-Skript verwendet :variables, aber PHP :versions ist auf diesem Server nicht installiert. Installieren Sie es auf der PHP-Seite oder verwenden Sie {php} für die eigene Version der Website.',

];
