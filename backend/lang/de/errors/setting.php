<?php

return [
    'operation_failed' => 'Die Änderung der Einstellungen ist auf dem Server fehlgeschlagen.',
    'group_unavailable' => 'Diese Einstellungsgruppe ist auf diesem Server nicht verfügbar.',
    'no_ssh_key' => 'Fügen Sie einen SSH-Schlüssel hinzu, bevor Sie die Passwort-Authentifizierung deaktivieren, sonst sperren Sie sich möglicherweise aus.',
    'redis_credential_unusable' => 'Das Panel erreicht Redis mit dem gespeicherten Passwort nicht und kann es daher nicht ändern. Redis läuft, weist die Anmeldedaten des Panels aber ab — korrigieren Sie REDIS_PASSWORD in der .env des Panels auf das tatsächlich von Redis geforderte Passwort und versuchen Sie es erneut.',
    'env_not_writable' => 'Das Panel kann seine eigene .env-Datei nicht schreiben, daher konnte kein neues Redis-Passwort gespeichert werden. Korrigieren Sie zuerst die Dateiberechtigungen — sonst verliert das Panel den Zugriff auf Redis.',
    'swap_in_use' => 'Der Auslagerungsspeicher wird verwendet und konnte nicht deaktiviert werden. Der Server hat nicht genügend freien Arbeitsspeicher, um die ausgelagerten Daten zurückzuholen — geben Sie Speicher frei und versuchen Sie es erneut.',
];
