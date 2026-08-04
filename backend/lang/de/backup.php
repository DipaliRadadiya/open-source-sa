<?php

return [
    'steps' => [
        'dump_database' => 'Datenbank wird exportiert',
        'archive_files' => 'Archiv wird erstellt',
        'upload_artifact' => 'Wird zum Speicher hochgeladen',
        'verify_artifact' => 'Upload wird überprüft',
        'prune_old_backups' => 'Alte Sicherungen werden entfernt',
        'rollback' => 'Aufräumen',
    ],
    'status' => [
        'pending' => 'In Warteschlange',
        'running' => 'Sicherung läuft',
        'verifying' => 'Wird überprüft',
        'verified' => 'Abgeschlossen',
        'failed' => 'Fehlgeschlagen',
    ],
    'type' => [
        'filesystem' => 'Dateien',
        'database' => 'Datenbank',
        'full' => 'Dateien und Datenbank',
    ],
    'frequency' => [
        'manual' => 'Nur manuell',
        'daily' => 'Täglich',
        'weekly' => 'Wöchentlich',
        'monthly' => 'Monatlich',
    ],
    'errors' => [
        'not_configured' => 'Für diese Anwendung sind noch keine Sicherungen eingerichtet.',
        'already_running' => 'Für diese Anwendung läuft bereits eine Sicherung.',
        'dump_database' => 'Die Datenbank konnte nicht exportiert werden, daher wurde nichts hochgeladen.',
        'archive_files' => 'Das Archiv konnte nicht erstellt werden — meist ist kein Speicherplatz mehr frei.',
        'upload_artifact' => 'Das Archiv konnte nicht hochgeladen werden. Prüfen Sie, ob das Speicherziel weiterhin Schreibzugriffe annimmt.',
        'verify_artifact' => 'Der Upload stimmt nicht mit dem Gesendeten überein; dieser Sicherung ist nicht zu trauen. Es wurde nichts Altes gelöscht.',
        'unknown' => 'Die Sicherung schlug aus unbekanntem Grund fehl.',
        'prune_old_backups' => 'Alte Sicherungen konnten nicht entfernt werden. Die neue Sicherung ist unversehrt; im Speicher liegen möglicherweise mehr Kopien als eingestellt.',
    ],
];
