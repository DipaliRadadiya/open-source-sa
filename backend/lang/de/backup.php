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
        'restore_unverified' => 'Diese Sicherung wurde nie verifiziert und kann daher nicht wiederhergestellt werden.',
        'restore_no_application' => 'Die Anwendung zu dieser Sicherung existiert nicht mehr.',
        'restore_confirm' => 'Geben Sie die Domain der Anwendung exakt ein, um die Wiederherstellung zu bestätigen.',
        'restore_already_running' => 'Für diese Anwendung läuft bereits eine Wiederherstellung.',
        'restore_no_database' => 'Diese Sicherung enthält keine Datenbank.',
        'restore_no_files' => 'Diese Sicherung enthält keine Dateien.',
        'not_configured' => 'Für diese Anwendung sind noch keine Sicherungen eingerichtet.',
        'already_running' => 'Für diese Anwendung läuft bereits eine Sicherung.',
        'dump_database' => 'Die Datenbank konnte nicht exportiert werden, daher wurde nichts hochgeladen.',
        'archive_files' => 'Das Archiv konnte nicht erstellt werden — meist ist kein Speicherplatz mehr frei.',
        'upload_artifact' => 'Das Archiv konnte nicht hochgeladen werden. Prüfen Sie, ob das Speicherziel weiterhin Schreibzugriffe annimmt.',
        'verify_artifact' => 'Der Upload stimmt nicht mit dem Gesendeten überein; dieser Sicherung ist nicht zu trauen. Es wurde nichts Altes gelöscht.',
        'unknown' => 'Die Sicherung schlug aus unbekanntem Grund fehl.',
        'prune_old_backups' => 'Alte Sicherungen konnten nicht entfernt werden. Die neue Sicherung ist unversehrt; im Speicher liegen möglicherweise mehr Kopien als eingestellt.',
    ],

    'restore_status' => [
        'pending' => 'In der Warteschlange',
        'running' => 'Wird wiederhergestellt',
        'succeeded' => 'Wiederhergestellt',
        'failed' => 'Wiederherstellung fehlgeschlagen',
    ],

    'restore_steps' => [
        'download_artifact' => 'Sicherung wird heruntergeladen',
        'verify_download' => 'Sicherung wird auf Vollständigkeit geprüft',
        'safety_backup' => 'Aktueller Stand wird zuerst gesichert',
        'extract_archive' => 'Sicherung wird entpackt',
        'restore_database' => 'Datenbank wird wiederhergestellt',
        'swap_files' => 'Dateien werden eingesetzt',
        'restart_process' => 'Anwendung wird gestartet',
    ],

    'restore_errors' => [
        'download_artifact' => 'Die Sicherung konnte nicht heruntergeladen werden. Auf dem Server wurde nichts geändert.',
        'verify_download' => 'Die heruntergeladene Sicherung ist unvollständig oder beschädigt und wurde nicht verwendet. Auf dem Server wurde nichts geändert.',
        'safety_backup' => 'Der aktuelle Stand konnte nicht gesichert werden, daher wurde die Wiederherstellung abgebrochen. Es wurde nichts überschrieben.',
        'extract_archive' => 'Die Sicherung konnte nicht entpackt werden. Auf dem Server wurde nichts geändert.',
        'restore_database' => 'Die Datenbank konnte nicht wiederhergestellt werden. Die zuvor angelegte Sicherung enthält den vorherigen Stand.',
        'swap_files' => 'Die Dateien konnten nicht eingesetzt werden. Das vorherige Verzeichnis wurde wiederhergestellt.',
        'restart_process' => 'Dateien und Datenbank wurden wiederhergestellt, aber die Anwendung startete nicht. Prüfen Sie ihre Protokolle.',
        'missing_backup' => 'Die Sicherung wurde entfernt, bevor die Wiederherstellung starten konnte.',
        'crashed' => 'Die Wiederherstellung wurde unerwartet beendet. Prüfen Sie die Sicherung, bevor Sie es erneut versuchen.',
        'unknown' => 'Die Wiederherstellung ist aus unbekanntem Grund fehlgeschlagen.',
    ],
];
