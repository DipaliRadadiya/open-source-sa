<?php

return [
    'database_engine_not_used' => 'Diese Anwendung verwendet keine Datenbank.',
    'database_engine_unsupported' => 'Diese Anwendung kann diese Datenbank-Engine nicht verwenden. :application unterstützt eine andere.',
    'database_engine_unavailable' => 'Diese Datenbank-Engine läuft auf diesem Server nicht. Installiere oder starte sie zuerst.',
    'database_engine_too_old' => 'Das :engine auf diesem Server ist zu alt für :application, das :minimum oder neuer benötigt. Aktualisiere es oder wähle eine andere Datenbank-Engine.',

    // Deleting a site can take its databases with it (`remove_databases`).
    // The first refusal is the caller lacking `database` manage; the second
    // is the honest half-success — the site went, a database did not.
    'database_removal_not_permitted' => 'Du kannst diese Website löschen, aber nicht ihre Datenbanken. Bitte einen Administrator um Datenbankzugriff oder lösche die Website, ohne sie zu entfernen.',
    'databases_not_removed' => 'Die Website wurde gelöscht, aber diese Datenbanken sind noch auf dem Server: :databases. Entferne sie auf der Datenbank-Seite oder nenne dem Support die Referenz.',

    'primary_domain_not_removable' => 'Die primäre Domain kann nicht entfernt werden. Machen Sie zuerst eine andere Domain zur primären.',
    'primary_domain_not_editable' => 'Eine primäre Domain kann nicht bearbeitet werden. Mache zuerst eine andere Domain zur primären.',
    'domain_taken' => 'Diese Domain wird auf diesem Server bereits verwendet.',
    'domain_taken_by' => 'Diese Domain wird bereits von der Anwendung „:application“ verwendet.',
    'unsupported_web_server' => 'Das Panel kann für :web_server keine Website-Konfiguration schreiben.',
    'no_web_server' => 'kein Webserver erkannt',
    'provision_failed' => 'Die Einrichtung der Website ist im Schritt „:step" fehlgeschlagen.',
    'not_a_git_application' => 'Diese Anwendung ist kein Git-Deployment — es gibt nichts abzurufen.',
    'no_database_engine' => 'Keine Datenbank-Engine verfügbar. Installiere und konfiguriere MySQL oder MariaDB, bevor du diese Anwendung anlegst.',
    'no_process' => '„:name“ führt keinen eigenen Prozess aus.',
    'process_failed' => 'Die Anwendung konnte nicht :action werden. Nenne dem Support die Referenz.',
    'system_user_missing' => 'Der Systembenutzer von :name fehlt, daher kann das Panel nicht mit den Dateien dieser Anwendung arbeiten. Sie können die Anwendung weiterhin löschen.',
    'no_port_available' => 'Kein freier Port zwischen :from und :to. Gib einen frei oder erweitere den Bereich.',

    'webhook_not_a_git_application' => 'Deploy-on-Push ist nur für Anwendungen verfügbar, die aus einem Git-Repository bereitgestellt werden.',

    'already_disabled' => 'Diese Anwendung ist bereits deaktiviert.',
    'not_disabled' => 'Diese Anwendung ist nicht deaktiviert.',
    'availability_failed' => 'Das Ändern der Verfügbarkeit der Anwendung ist auf dem Server fehlgeschlagen.',
    'basic_auth_failed' => 'Das Ändern des Passwortschutzes ist auf dem Server fehlgeschlagen.',
    'environment_failed' => 'Das Panel konnte die Umgebungsdatei der Anwendung auf dem Server nicht prüfen und meldet daher weder das eine noch das andere.',
    'bot_blocker_failed' => 'Das Ändern der KI-Bot-Blocker-Richtlinie ist auf dem Server fehlgeschlagen.',
    'bot_agent_invalid' => 'Geben Sie einen einzelnen Bot-Namen ein, z. B. GPTBot oder SemrushBot – nur Buchstaben, Zahlen, Punkte und Bindestriche.',
    'bot_agent_too_broad' => 'Das ist zu allgemein – damit würden auch Suchmaschinen wie Google und Bing blockiert. Verwenden Sie den vollständigen Bot-Namen.',
    'bot_agent_search_engine' => 'Das ist eine Suchmaschine, kein KI-Crawler. Eine Blockierung würde Ihre Website aus den Suchergebnissen entfernen.',
    'web_root_failed' => 'Das Ändern des Web-Roots ist auf dem Server fehlgeschlagen.',
    'web_root_not_found' => 'Das Web-Root-Verzeichnis wurde auf dem Server nicht gefunden. Prüfen Sie den Web-Root in den Anwendungseinstellungen und stellen Sie die Anwendung erneut bereit, falls sie nie erstellt wurde.',
    'waf_unsupported' => 'Die 8G-Firewall ist auf :server noch nicht verfügbar.',
    'waf_failed' => 'Das Ändern der Firewall-Einstellungen ist auf dem Server fehlgeschlagen.',
    'staging_failed' => 'Der Staging-Vorgang ist auf dem Server fehlgeschlagen.',
    'staging_rollback_failed' => 'Der Staging-Push ist fehlgeschlagen und die Produktionswebsite konnte nicht wiederhergestellt werden. Die Website bleibt deaktiviert. Nennen Sie dem Support die Referenz.',
    'clone_failed' => 'Der Klonvorgang ist auf dem Server fehlgeschlagen.',
    'fail2ban_failed' => 'Der Fail2ban-Vorgang ist auf dem Server fehlgeschlagen.',

    'permissions_fix_failed' => 'Das Zurücksetzen der Dateiberechtigungen ist auf dem Server fehlgeschlagen.',

    'unsafe_path' => 'Dieser Pfad ist nicht zulässig.',
    'file_too_large' => 'Diese Datei ist zu groß für den Editor. Laden Sie sie stattdessen herunter — Downloads haben keine Größenbeschränkung.',
    'file_not_text' => 'Diese Datei scheint kein Text zu sein und kann hier nicht geöffnet werden.',
    'file_not_previewable' => 'Diese Datei ist kein Bild, es gibt also nichts anzuzeigen. Laden Sie sie herunter, um sie auf Ihrem Rechner zu öffnen.',
    'file_svg_not_previewable' => 'SVG-Dateien werden hier nicht angezeigt, da ein SVG Code enthalten kann. Laden Sie die Datei herunter, um sie anzusehen.',
    'file_too_large_to_preview' => 'Dieses Bild ist zu groß für die Anzeige. Laden Sie es stattdessen herunter — Downloads haben keine Größenbeschränkung.',

    'archive_failed' => [
        'timed_out' => 'Das Archiv hat länger gedauert als der Server zulässt und wurde gestoppt. Versuchen Sie eine kleinere Auswahl.',
        'command_failed' => 'Der Server konnte das Archiv nicht fertigstellen. Es wurde nichts halb geschrieben zurückgelassen.',
        'application_missing' => 'Die Website wurde entfernt, bevor das Archiv erstellt werden konnte.',
        'worker' => 'Der Vorgang wurde auf dem Server unerwartet beendet und ist nicht fertig geworden.',
        'unknown' => 'Das Archiv wurde nicht fertiggestellt.',
    ],
    'file_operation_failed' => 'Der Dateivorgang ist auf dem Server fehlgeschlagen.',

    'file_not_archive' => 'Hier können nur .zip- und .tar.gz-Archive entpackt werden.',
    'archive_unreadable' => 'Dieses Archiv konnte nicht gelesen werden. Es ist möglicherweise beschädigt.',
    'archive_empty' => 'Dieses Archiv enthält nichts.',
    'archive_too_many_entries' => 'Dieses Archiv enthält zu viele Dateien, um es hier zu entpacken.',
    'archive_too_large' => 'Dieses Archiv wäre entpackt zu groß.',
    'archive_has_symlink' => 'Dieses Archiv enthält einen symbolischen Link, der nicht zulässig ist.',
    'archive_unsafe_entry' => 'Dieses Archiv enthält einen Dateipfad, der nicht zulässig ist.',

    'upload_exists' => '„:name“ ist hier bereits vorhanden. Löschen Sie die Datei zuerst, wenn Sie sie ersetzen möchten – ein Upload überschreibt keine Datei.',

    'path_exists' => 'An diesem Pfad existiert bereits etwas.',
    'cannot_delete_root' => 'Der Stammordner der Website kann nicht gelöscht werden.',
    'target_not_archive' => 'Der Name des neuen Archivs muss auf .zip, .tar.gz oder .tgz enden.',
    'unknown_backup' => 'Das ist keine bekannte Sicherung dieser Datei.',

    'upload_directory_missing' => 'Der Ordner für diesen Upload existiert nicht mehr.',
    'upload_insufficient_space' => 'Auf dem Server ist nicht genügend freier Speicherplatz für diesen Upload vorhanden.',

    'bulk_count_mismatch' => 'Die bestätigte Anzahl stimmt nicht mit der Anzahl der ausgewählten Elemente überein.',
    'sources_not_in_one_directory' => 'Alle zu komprimierenden Elemente müssen im selben Ordner liegen.',
    'release_failed' => 'Das Verzeichnis der Website konnte auf dem Server nicht erstellt werden.',
    'supervisor_missing' => 'Worker benötigen supervisord, das auf diesem Server nicht installiert ist. Installieren Sie es mit `apt-get install supervisor` und legen Sie den Worker erneut an.',
    'supervisor_already_installed' => 'Supervisor ist auf diesem Server bereits installiert.',
    'worker_control_failed' => 'Der Worker konnte auf dem Server nicht gesteuert werden.',

    // Which system account a new site runs as. Generating one creates a
    // real Linux account, which is why it needs its own permission.
    'generate_system_user_forbidden' => 'Sie dürfen keine Systembenutzer anlegen, daher kann für diese Website kein neuer erzeugt werden. Wählen Sie stattdessen einen vorhandenen Systembenutzer.',
    'system_user_conflict' => 'Wählen Sie entweder einen neuen oder einen vorhandenen Systembenutzer, nicht beides.',
    'system_user_name_unavailable' => 'Für diese Website konnte kein Systembenutzername reserviert werden – der Server konnte nicht gefragt werden, welche Namen bereits vergeben sind. Versuchen Sie es erneut oder wählen Sie einen vorhandenen Systembenutzer.',

    // The Lock button for a site folder the panel did not create; see
    // SiteRootLock::adopt(). Keyed by its result.
    'root_lock' => [
        'unsafe' => 'Der Website-Ordner :path ist kein normaler Ordner oder hat sich während der Prüfung geändert und wurde daher nicht angetastet. Prüfe ihn auf dem Server, bevor du es erneut versuchst.',
        'missing' => 'Der Website-Ordner :path existiert auf dem Server nicht.',
        'failed' => 'Der Server konnte den Website-Ordner nicht sperren. Es wurde nichts geändert. Details stehen im Server-Log.',
        'unsupported' => 'Das Dateisystem dieses Servers unterstützt die Ordnersperre nicht, daher wurde der Website-Ordner unverändert gelassen.',
        'foreign_owner' => 'Der Website-Ordner :path gehört einem anderen Konto, nicht dem Benutzer dieser Website, und wurde daher nicht angetastet. Prüfe, wem er gehören sollte, bevor du ihn sperrst.',
        'writable' => 'Andere Konten können in den Website-Ordner :path schreiben, daher würde eine Sperre nicht halten. Entferne die Schreibrechte für Gruppe und Alle (zum Beispiel `chmod 755`) und versuche es erneut.',
        'locks_out_user' => 'Das Sperren des Website-Ordners :path würde den Benutzer dieser Website aussperren: Seine Rechte geben ihm nur als Eigentümer Zugriff. Gib der Gruppe des Ordners Lese- und Öffnungsrechte (zum Beispiel `chmod 750`), stelle sicher, dass der Benutzer in dieser Gruppe ist, und versuche es erneut.',
    ],
];
