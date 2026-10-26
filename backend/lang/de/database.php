<?php

return [

    'install_steps' => [
        'queued' => 'In Warteschlange',
        'checking_conflicts' => 'Konflikte mit anderen Datenbank-Engines werden geprüft',
        'preparing_repository' => 'Paketquelle wird vorbereitet',
        'waiting_for_package_manager' => 'Warten, bis ein anderer Paketvorgang abgeschlossen ist',
        'updating_package_index' => 'Paketindex wird aktualisiert',
        'preparing' => 'Pakete werden vorbereitet',
        'downloading' => 'Pakete werden heruntergeladen',
        'unpacking' => 'Pakete werden entpackt',
        'configuring' => 'Pakete werden konfiguriert',
        'starting_service' => 'Datenbankdienst wird gestartet',
        'verifying_cluster' => 'Datenbank-Cluster wird überprüft',
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

    'import_failed' => [
        'unknown' => 'Der Import ist fehlgeschlagen. Nennen Sie dem Support die Referenz unten.',
        'database_missing' => 'Die Datenbank wurde gelöscht, bevor der Import laufen konnte.',
        'no_account' => 'Diese Datenbank hat keinen Benutzer, als der der Import laufen kann: einen, dessen Passwort das Panel kennt und der sich von diesem Server verbindet.',
        'wrong_format' => 'Diese Datei ist kein Dump, den diese Datenbank importieren kann. MySQL und MariaDB nehmen .sql oder .sql.gz, PostgreSQL nimmt .sql, .sql.gz oder ein pg_dump-Custom-Archiv, MongoDB nimmt eine mongodump-Datei mit --archive.',
        'client_command' => 'Zeile :line der Datei enthält einen psql-Befehl (einen Backslash-Befehl wie \\!). Solche Befehle können Programme auf dem Server ausführen, daher wurde die Datei abgelehnt. Exportieren Sie die Datenbank erneut als reines SQL mit pg_dump.',
        'truncated' => 'Die Datei endet mitten in ihren Daten und ist daher unvollständig. Exportieren Sie sie erneut.',
        'unreadable' => 'Die Datei konnte nicht als PostgreSQL-Archiv gelesen werden.',
        'backup_failed' => 'Die Sicherung vor dem Import ist fehlgeschlagen, daher wurde nichts importiert.',
        'load_failed' => 'Die Datei konnte nicht in die Datenbank geladen werden. Nennen Sie dem Support die Referenz unten.',
        'worker' => 'Der Import wurde unerwartet beendet. Möglicherweise ist eine Zeitüberschreitung aufgetreten.',
        'abandoned' => 'Der Upload wurde nicht innerhalb eines Tages abgeschlossen und wurde entfernt.',
        'restored' => 'Die Datenbank wurde aus der automatischen Sicherung wiederhergestellt, es ging nichts verloren.',
        'untouched' => 'Die Datenbank wurde nicht verändert.',
        'not_restored' => 'Die automatische Sicherung konnte nicht von selbst zurückgespielt werden. Sie steht in der Export-Liste: Laden Sie sie herunter und importieren Sie sie, um die Datenbank wiederherzustellen.',
        'no_copy' => 'Es wurde keine Sicherheitskopie erstellt (für diesen Import ausgeschaltet), daher konnte nichts zurückgespielt werden: Die Datenbank ist jetzt möglicherweise leer oder nur teilweise geladen.',
    ],

];
