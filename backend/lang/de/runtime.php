<?php

return [

    /*
    | Why an install failed, keyed by the `reason` code stored on the
    | install row. Built at read time in the *viewer's* locale — the
    | raw apt or fnm output is never shown, only referenced.
    */

    'install_failed' => [
        'package_not_found' => 'Kein Paket für :version. Prüfe, ob das PHP-Repository konfiguriert und erreichbar ist.',
        'apt_lock' => 'Es läuft bereits ein anderer Paketvorgang. Versuche es gleich noch einmal.',
        'network' => 'Das Paket-Repository war nicht erreichbar. Prüfe die Netzwerkverbindung des Servers.',
        'no_space' => 'Auf dem Server ist kein Speicherplatz mehr frei.',
        'worker' => 'Die Installation wurde unerwartet beendet. Möglicherweise ein Timeout — versuche es erneut.',
        'unknown' => 'Die Installation ist fehlgeschlagen. Nenne dem Support die untenstehende Referenz.',
        'dpkg_broken' => 'Die Paketdatenbank dieses Servers muss repariert werden, bevor etwas anderes installiert werden kann.',
        'port_in_use_by_mysql' => 'MySQL ist bereits installiert und belegt diesen Port. Entfernen Sie es zuerst, oder nutzen Sie es weiter.',
        'port_in_use_by_mariadb' => 'MariaDB ist bereits installiert und belegt diesen Port. Entfernen Sie es zuerst, oder nutzen Sie es weiter.',
        'root_unreachable' => 'Es ist installiert, aber das Panel konnte sich nicht anmelden. Der Administrator-Zugang wurde gegenüber dem Standard geändert; das Panel benötigt diese Daten, um fortzufahren.',
        'grant_failed' => 'Es ist installiert, aber das Panel konnte kein eigenes Konto darin anlegen.',
        'repository_failed' => 'Das MongoDB-Paketrepository konnte nicht hinzugefügt werden. Prüfen Sie, ob der Server repo.mongodb.org erreicht.',
        'unreachable' => 'Es wurde installiert, antwortet aber nicht. Geben Sie die untenstehende Referenz beim Support an.',
        'auth_required' => 'MongoDB ist hier bereits installiert und verlangt eine Anmeldung, die dem Panel fehlt. Hinterlegen Sie die Zugangsdaten in den Verbindungseinstellungen und versuchen Sie es erneut.',
        'auth_config_present' => 'MongoDB ist installiert und seine Konfiguration enthält bereits einen security-Abschnitt. Das Panel hat ihn unangetastet gelassen — aktivieren Sie authorization dort selbst und versuchen Sie es erneut.',
        'auth_failed' => 'Es wurde installiert, aber die Authentifizierung ließ sich nicht aktivieren. Geben Sie die untenstehende Referenz beim Support an.',
    ],

    'uninstall_failed' => [
        'failed' => 'PHP :version konnte nicht entfernt werden. Nenne dem Support die untenstehende Referenz.',
        'worker' => 'Das Entfernen von PHP :version wurde unerwartet beendet. Möglicherweise ein Timeout — versuche es erneut.',
        'unknown' => 'PHP :version konnte nicht entfernt werden. Nenne dem Support die untenstehende Referenz.',
    ],

    'extension_install_failed' => [
        'package_not_found' => 'Kein Paket für :extension unter PHP :version. Für diese Version existiert es möglicherweise nicht.',
        'apt_lock' => 'Es läuft bereits ein anderer Paketvorgang. Versuche es gleich noch einmal.',
        'network' => 'Das Paket-Repository war nicht erreichbar. Prüfe die Netzwerkverbindung des Servers.',
        'no_space' => 'Auf dem Server ist kein Speicherplatz mehr frei.',
        'worker' => 'Die Installation von :extension wurde unerwartet beendet. Möglicherweise ein Timeout — versuche es erneut.',
        'unknown' => 'Die Installation von :extension ist fehlgeschlagen. Nenne dem Support die untenstehende Referenz.',
        'enable_failed' => ':extension wurde installiert, konnte aber nicht aktiviert werden. Versuche den Schalter erneut.',
    ],

    'fail2ban_install_failed' => [
        'package_not_found' => 'Es ist kein fail2ban-Paket verfügbar. Prüfen Sie, ob die Paketquellen des Servers konfiguriert und erreichbar sind.',
        'apt_lock' => 'Es läuft bereits ein anderer Paketvorgang. Versuchen Sie es gleich noch einmal.',
        'network' => 'Das Paket-Repository war nicht erreichbar. Prüfen Sie die Netzwerkverbindung des Servers.',
        'no_space' => 'Auf dem Server ist kein Speicherplatz mehr frei.',
        'worker' => 'Die Installation wurde unerwartet beendet. Möglicherweise ein Timeout — bitte erneut versuchen.',
        'unknown' => 'Die Installation von fail2ban ist fehlgeschlagen. Nennen Sie dem Support die Referenz unten.',
    ],

];
