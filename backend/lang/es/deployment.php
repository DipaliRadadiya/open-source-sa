<?php

return [

    // Where one deploy got to. Shown as a badge on every row.
    'status' => [
        'queued' => 'En cola',
        'running' => 'En curso',
        'succeeded' => 'Correcto',
        'failed' => 'Fallido',
    ],

    // What started it. "Push" rather than "Webhook" because the user
    // thinks in terms of what they did, not how it reached us.
    'trigger' => [
        'manual' => 'Manual',
        'webhook' => 'Push',
        'redeploy' => 'Reejecución',
        'initial' => 'Primer despliegue',
    ],

    'script_php_missing' => 'Tu script de despliegue usa :variables, pero PHP :versions no está instalado en este servidor. Instálalo en la pantalla de PHP o usa {php} para la versión del propio sitio.',

];
