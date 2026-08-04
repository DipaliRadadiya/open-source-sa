<?php

return [
    'checks' => [
        'privilege' => 'Privilegierte Befehle',
        'services' => 'Dienste',
        'writable_paths' => 'Beschreibbare Pfade',
        'database' => 'Datenbank',
        'health_endpoint' => 'Health-Endpunkt',
        'binaries' => 'Benötigte Werkzeuge',
        'web_server' => 'Webserver',
        'queue' => 'Queue-Worker',
    ],
    'fixes' => [
        'privilege' => 'Das Panel kann keine Befehle als root ausführen. Prüfen Sie, ob /etc/sudoers.d/ die Panel-Berechtigung enthält und die Datei visudo -c besteht.',
        'privilege_disabled' => 'Die Rechteerweiterung ist deaktiviert, das Panel läuft aber nicht als root. Entfernen Sie SERVER_OPS_SUDO=false aus .env.',
        'services_missing' => 'Eine erwartete Unit existiert nicht. Setzen Sie PANEL_FRONTEND_SERVICE und PANEL_QUEUE_SERVICE in .env auf die tatsächlichen Namen.',
        'services_down' => 'Starten Sie sie mit systemctl start und prüfen Sie journalctl -u <Unit>.',
        'writable_paths' => 'Übertragen Sie den Besitz an das Panel-Konto: chown -R <Panel-Benutzer> auf die genannten Pfade.',
        'database_unreachable' => 'Prüfen Sie die DB_-Einstellungen in .env und ob der Datenbankdienst läuft.',
        'database_pending' => 'Führen Sie php artisan migrate --force aus. Der Code wurde ohne seine Schemaänderungen aktualisiert.',
        'health_unreachable' => 'Prüfen Sie, ob APP_URL in .env der Adresse des Panels entspricht und ob Webserver und php-fpm laufen.',
        'health_version_mismatch' => 'Laufender Code und ausgelieferte Version weichen ab. Leeren Sie die Caches mit php artisan optimize:clear und laden Sie php-fpm neu.',
        'binaries_required' => 'Installieren Sie die fehlenden Pakete. Ohne sie funktionieren Kernfunktionen überhaupt nicht.',
        'binaries_optional' => 'Jedes fehlende Werkzeug deaktiviert die daneben genannte Funktion. Über die Einrichtungsseite installieren oder ignorieren, wenn nicht benötigt.',
        'web_server_missing' => 'Kein unterstützter Webserver gefunden. Installieren Sie nginx oder Apache.',
        'web_server_undrivable' => 'Das Panel kann für diesen Webserver keine Konfiguration schreiben, daher lassen sich keine Sites anlegen. Wechseln Sie zu nginx oder Apache.',
        'web_server_config' => 'Die Webserver-Konfiguration ist ungültig. Führen Sie deren Konfigurationstest aus — der nächste Reload schlägt fehl, bis das behoben ist.',
        'queue_stalled' => 'Jobs stehen in der Warteschlange, aber nichts verarbeitet sie. Starten Sie den Queue-Dienst neu; Bereitstellungen, Deployments und Installationen werden sonst nie fertig.',
        'queue_failed_jobs' => 'Einige Hintergrund-Jobs sind fehlgeschlagen. Prüfen Sie die Tabelle failed_jobs — stillschweigend verworfene Arbeit ist oft der Grund, warum eine Funktion nichts zu tun schien.',
        'queue_unreadable' => 'Die Queue-Tabellen konnten nicht gelesen werden. Führen Sie php artisan migrate --force aus.',
    ],
];
