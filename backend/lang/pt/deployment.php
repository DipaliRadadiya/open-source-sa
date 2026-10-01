<?php

return [

    // Where one deploy got to. Shown as a badge on every row.
    'status' => [
        'queued' => 'Em fila',
        'running' => 'A decorrer',
        'succeeded' => 'Concluído',
        'failed' => 'Falhou',
    ],

    // What started it. "Push" rather than "Webhook" because the user
    // thinks in terms of what they did, not how it reached us.
    'trigger' => [
        'manual' => 'Manual',
        'webhook' => 'Push',
        'redeploy' => 'Reexecução',
        'initial' => 'Primeira implementação',
    ],

    'script_php_missing' => 'O seu script de deploy usa :variables, mas o PHP :versions não está instalado neste servidor. Instale-o na tela de PHP ou use {php} para a versão do próprio site.',

];
