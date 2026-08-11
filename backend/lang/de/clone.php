<?php

return [
    'status' => [
        'pending' => 'In Warteschlange',
        'running' => 'Wird geklont',
        'completed' => 'Abgeschlossen',
        'failed' => 'Fehlgeschlagen',
    ],

    'current_step' => [
        'provisioning' => 'Website wird erstellt',
        'copying_files' => 'Dateien werden kopiert',
        'cloning_database' => 'Datenbank wird geklont',
        'starting_process' => 'Anwendung wird gestartet',
    ],

    'cloning_errors' => [
        'crashed' => 'Der Klonvorgang wurde unerwartet beendet.',
    ],
];
