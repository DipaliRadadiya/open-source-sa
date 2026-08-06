<?php

return [
    'primary_domain_not_removable' => 'Die primäre Domain kann nicht entfernt werden. Machen Sie zuerst eine andere Domain zur primären.',
    'unsupported_web_server' => 'Das Panel kann für :web_server keine Website-Konfiguration schreiben.',
    'no_web_server' => 'kein Webserver erkannt',
    'provision_failed' => 'Die Einrichtung der Website ist im Schritt „:step" fehlgeschlagen.',
    'not_a_git_application' => 'Diese Anwendung ist kein Git-Deployment — es gibt nichts abzurufen.',
    'no_database_engine' => 'Keine Datenbank-Engine verfügbar. Installiere und konfiguriere MySQL oder MariaDB, bevor du diese Anwendung anlegst.',
    'no_process' => '„:name“ führt keinen eigenen Prozess aus.',
    'process_failed' => 'Die Anwendung konnte nicht :action werden. Nenne dem Support die Referenz.',
    'no_port_available' => 'Kein freier Port zwischen :from und :to. Gib einen frei oder erweitere den Bereich.',

    'webhook_not_a_git_application' => 'Deploy-on-Push ist nur für Anwendungen verfügbar, die aus einem Git-Repository bereitgestellt werden.',

    'already_disabled' => 'Diese Anwendung ist bereits deaktiviert.',
    'not_disabled' => 'Diese Anwendung ist nicht deaktiviert.',
    'availability_failed' => 'Das Ändern der Verfügbarkeit der Anwendung ist auf dem Server fehlgeschlagen.',
    'basic_auth_failed' => 'Das Ändern des Passwortschutzes ist auf dem Server fehlgeschlagen.',
    'bot_blocker_failed' => 'Das Ändern der KI-Bot-Blocker-Richtlinie ist auf dem Server fehlgeschlagen.',
    'web_root_failed' => 'Das Ändern des Web-Roots ist auf dem Server fehlgeschlagen.',
    'waf_failed' => 'Das Ändern der Firewall-Einstellungen ist auf dem Server fehlgeschlagen.',
    'staging_failed' => 'Der Staging-Vorgang ist auf dem Server fehlgeschlagen.',
    'clone_failed' => 'Der Klonvorgang ist auf dem Server fehlgeschlagen.',
    'fail2ban_failed' => 'Der Fail2ban-Vorgang ist auf dem Server fehlgeschlagen.',

    'permissions_fix_failed' => 'Das Zurücksetzen der Dateiberechtigungen ist auf dem Server fehlgeschlagen.',

    'unsafe_path' => 'Dieser Pfad ist nicht zulässig.',
    'file_too_large' => 'Diese Datei ist zu groß, um sie hier zu öffnen. Nutze SFTP für große Dateien.',
    'file_not_text' => 'Diese Datei scheint kein Text zu sein und kann hier nicht geöffnet werden.',
    'file_operation_failed' => 'Der Dateivorgang ist auf dem Server fehlgeschlagen.',

    'file_not_archive' => 'Hier können nur .zip- und .tar.gz-Archive entpackt werden.',
    'archive_unreadable' => 'Dieses Archiv konnte nicht gelesen werden. Es ist möglicherweise beschädigt.',
    'archive_empty' => 'Dieses Archiv enthält nichts.',
    'archive_too_many_entries' => 'Dieses Archiv enthält zu viele Dateien, um es hier zu entpacken.',
    'archive_too_large' => 'Dieses Archiv wäre entpackt zu groß.',
    'archive_has_symlink' => 'Dieses Archiv enthält einen symbolischen Link, der nicht zulässig ist.',
    'archive_unsafe_entry' => 'Dieses Archiv enthält einen Dateipfad, der nicht zulässig ist.',

    'path_exists' => 'An diesem Pfad existiert bereits etwas.',
    'cannot_delete_root' => 'Der Stammordner der Website kann nicht gelöscht werden.',
    'target_not_zip' => 'Der Name des neuen Archivs muss auf .zip enden.',
    'unknown_backup' => 'Das ist keine bekannte Sicherung dieser Datei.',

];
