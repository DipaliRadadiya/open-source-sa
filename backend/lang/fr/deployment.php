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
        'initial' => 'Premier déploiement',
    ],

    'script_php_missing' => 'Votre script de déploiement utilise :variables, mais PHP :versions n’est pas installé sur ce serveur. Installez-le depuis l’écran PHP, ou utilisez {php} pour la version propre au site.',

];
