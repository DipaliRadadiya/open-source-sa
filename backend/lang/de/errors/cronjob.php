<?php

return [
    'sync_failed' => 'Der Cronjob konnte auf dem Server nicht angewendet werden.',
    // One sentence per privileged step. They all used to share
    // `sync_failed`, so a full disk and a missing group read the same.
    'step' => [
        'log_dir' => 'Das Protokollverzeichnis für Cronjobs konnte nicht erstellt werden. Prüfen Sie freien Speicherplatz und ob /var/log beschreibbar ist.',
        'log_touch' => 'Die Protokolldatei des Cronjobs konnte nicht erstellt werden. Meist ist die Festplatte voll.',
        'log_chown' => 'Die Protokolldatei konnte dem ausführenden Konto nicht übergeben werden. Prüfen Sie, ob dieses Konto noch existiert.',
        'log_chmod' => 'Die Berechtigungen der Protokolldatei konnten nicht gesetzt werden.',
        'rotation' => 'Die Rotationsrichtlinie konnte nicht installiert werden, daher wurde der Job nicht eingeplant — seine Ausgabe würde unbegrenzt wachsen.',
        'write' => 'Die Cron-Datei konnte nicht geschrieben werden. Prüfen Sie den freien Speicherplatz.',
        'chmod' => 'Die Berechtigungen der Cron-Datei konnten nicht gesetzt werden. Cron ignoriert eine Datei, der es nicht traut, daher wurde der Job nicht eingeplant.',
        'remove' => 'Die Cron-Datei konnte nicht entfernt werden, der Job ist auf dem Server weiterhin eingeplant.',
        'remove_stale' => 'Die alte Cron-Datei konnte nach der Umbenennung nicht entfernt werden. Es wurde nichts geändert, der Job ist also nicht doppelt eingeplant.',
        'detach_source' => 'Die ursprüngliche Cron-Datei, aus der dieser Job importiert wurde, konnte nicht entfernt werden. Es wurde nichts geändert, der Befehl läuft also nicht doppelt.',
    ],
    'invalid_expression' => 'Der Zeitplan ist kein gültiger Cron-Ausdruck.',
    'invalid_user' => 'Der ausgewählte Benutzer existiert nicht auf dem Server.',
    'unresolved_placeholder' => 'Der Befehl enthält noch den Platzhalter {path} — ersetzen Sie ihn durch das Anwendungsverzeichnis.',
    'no_newline' => 'Dieser Wert darf keine Zeilenumbrüche enthalten.',
    'reserved_name' => 'Dieser Name ist reserviert und kann nicht verwendet werden.',
];
