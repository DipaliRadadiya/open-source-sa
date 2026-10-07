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
        'failed' => 'Das Klonen ist fehlgeschlagen. Nennen Sie dem Support die Referenz.',
        'abandoned' => 'Dieser Klon wurde nie gestartet und wurde freigegeben. Starten Sie ihn erneut.',
        'copy_failed' => 'Das Kopieren der Anwendung ist auf dem Server fehlgeschlagen. Nennen Sie dem Support die Referenz.',
        'setup_failed' => 'Die Kopie konnte auf dem Server nicht eingerichtet werden. Nennen Sie dem Support die Referenz.',
    ],

    'errors' => [
        'already_running' => 'Diese Anwendung wird bereits geklont. Warten Sie, bis der Vorgang abgeschlossen ist, und starten Sie dann einen weiteren.',
    ],
];
