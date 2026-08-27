<?php

return [

    'install_steps' => [
        'queued' => 'In Warteschlange',
        'checking_conflicts' => 'Konflikte mit anderen Datenbank-Engines werden geprüft',
        'preparing_repository' => 'Paketquelle wird vorbereitet',
        'updating_package_index' => 'Paketindex wird aktualisiert',
        'preparing' => 'Pakete werden vorbereitet',
        'downloading' => 'Pakete werden heruntergeladen',
        'unpacking' => 'Pakete werden entpackt',
        'configuring' => 'Pakete werden konfiguriert',
        'starting_service' => 'Datenbankdienst wird gestartet',
        'verifying_connection' => 'Datenbankverbindung wird geprüft',
        'creating_panel_account' => 'Datenbankkonto für das Panel wird erstellt',
    ],

    /*
    | Why an export failed. Stored as a stable code on the row and worded here
    | at read time, so the sentence lands in the *viewer's* locale rather than
    | the locale of whoever pressed the button.
    */

    'export_failed' => [
        'dump_failed' => 'Der Datenbank-Dump ist fehlgeschlagen. Nennen Sie dem Support die Referenz unten.',
        'database_missing' => 'Die Datenbank wurde gelöscht, bevor der Export laufen konnte.',
        'worker' => 'Der Export wurde unerwartet beendet. Möglicherweise ein Timeout — bitte erneut versuchen.',
        'unknown' => 'Der Export ist fehlgeschlagen. Nennen Sie dem Support die Referenz unten.',
    ],

];
