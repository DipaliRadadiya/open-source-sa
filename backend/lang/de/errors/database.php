<?php

return [
    'operation_failed' => 'Der Datenbankvorgang ist auf dem Server fehlgeschlagen.',
    'collation_mismatch' => 'Die gewählte Kollation gehört nicht zum gewählten Zeichensatz.',
    'engine_not_installable' => 'Das Panel kann diese Datenbank-Engine noch nicht installieren. Installieren Sie sie selbst, das Panel erkennt sie dann.',

    'engine_install' => [
        'package_not_found' => 'Das Paket für diese Engine ist aus den Paketquellen dieses Servers nicht verfügbar.',
        'apt_lock' => 'Es läuft bereits eine andere Paketoperation. Warten Sie, bis sie beendet ist, und versuchen Sie es erneut.',
        'no_space' => 'Es ist nicht genügend freier Speicherplatz vorhanden, um diese Engine zu installieren.',
        'network' => 'Der Server konnte seine Paketquellen nicht erreichen. Prüfen Sie Netzwerk und DNS.',
        'dpkg_broken' => 'Die Paketdatenbank dieses Servers muss repariert werden, bevor etwas anderes installiert werden kann.',
        'port_in_use_by_mysql' => 'MySQL ist bereits installiert und belegt diesen Port. Entfernen Sie es zuerst, oder nutzen Sie es weiter.',
        'port_in_use_by_mariadb' => 'MariaDB ist bereits installiert und belegt diesen Port. Entfernen Sie es zuerst, oder nutzen Sie es weiter.',
        'root_unreachable' => 'Die Engine ist installiert, aber das Panel konnte sich nicht anmelden. Ihr Administrator-Zugang wurde gegenüber dem Standard geändert; das Panel benötigt diese Daten, um fortzufahren.',
        'grant_failed' => 'Die Engine ist installiert, aber das Panel konnte kein eigenes Konto darin anlegen.',
        'unknown' => 'Die Installation ist fehlgeschlagen. Nennen Sie dem Support die Referenz.',
    ],
];
