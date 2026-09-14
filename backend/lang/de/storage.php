<?php

/*
 * Copy for the Storage destinations integration — S3-compatible remote
 * targets that backups are uploaded to. The keys in this file render the
 * connect form, the row labels and the outcome of the test-connection probe.
 */

return [
    'drivers' => [
        's3' => 'S3-kompatibel',
        'ftp' => 'FTP',
        'sftp' => 'SFTP',
    ],

    'fields' => [
        'name' => 'Anzeigename',
        'endpoint' => 'Endpunkt-URL',
        'region' => 'Region',
        'bucket' => 'Bucket',
        'prefix' => 'Schlüsselpräfix (optional)',
        'access_key' => 'Zugriffsschlüssel',
        'secret_key' => 'Geheimer Schlüssel',
        'host' => 'Host',
        'port' => 'Port',
        'username' => 'Benutzername',
        'password' => 'Passwort',
        'root' => 'Entferntes Verzeichnis',
        'ssl' => 'TLS verwenden (FTPS)',
        'passive' => 'Passiver Modus',
        'private_key' => 'Privater Schlüssel',
        'passphrase' => 'Schlüssel-Passphrase',
        'host_fingerprint' => 'Fingerabdruck des Hostschlüssels',
    ],

    'placeholders' => [
        'endpoint' => 'https://s3.amazonaws.com',
        'region' => 'us-east-1',
        'prefix' => 'backups/production/',
        'host' => 'backup.beispiel.de',
        'root' => 'backups/',
    ],

    'help' => [
        'name' => 'Eine kurze Bezeichnung, um Ziele in der Integrationsliste zu unterscheiden.',
        'endpoint' => 'Für AWS auf dem Standardwert lassen. Für MinIO, R2, Backblaze B2, Wasabi usw. setzen.',
        'region' => 'Region, in der der Bucket liegt (nur für AWS erforderlich).',
        'prefix' => 'Optionales Pfadpräfix innerhalb des Buckets (ohne führenden Schrägstrich).',
        'access_key' => 'Nur schreibend — wird von der API nie zurückgegeben.',
        'host' => 'Hostname oder IP-Adresse des Servers, der die Sicherungen aufnimmt.',
        'port' => 'Leer lassen für den Standardwert.',
        'root' => 'Verzeichnis auf dem Server, in das geschrieben wird. Leer lassen, um dort zu bleiben, wo die Anmeldung landet.',
        'ssl' => 'Dringend empfohlen. Ohne TLS werden Passwort und gesamte Sicherung unverschlüsselt übertragen.',
        'passive' => 'Aktiviert lassen, sofern der Server nichts anderes verlangt.',
        'private_key' => 'Den vollständigen privaten Schlüssel einfügen. Wird anstelle eines Passworts verwendet.',
        'passphrase' => 'Nur nötig, wenn der private Schlüssel selbst verschlüsselt ist.',
        'host_fingerprint' => 'Wird bei der ersten Verbindung aufgezeichnet und danach erzwungen. Zum Abgleich mit dem Schlüssel auf dem Server.',
        'plain_ftp_warning' => 'TLS ist aus. Passwort und jede Sicherung werden unverschlüsselt gesendet.',
    ],

    'status' => [
        'connected' => 'Verbunden',
        'never_tested' => 'Noch nicht getestet',
        'failed' => 'Letzter Test fehlgeschlagen',
    ],

    'test' => [
        'success' => 'Verbindung erfolgreich hergestellt.',
        'failure' => 'Verbindung zum Ziel nicht möglich.',
        'invalid_credentials' => 'Das Ziel hat die Zugangsdaten abgelehnt.',
        'unreachable' => 'Der Endpunkt des Ziels war nicht erreichbar.',
        'mismatch' => 'Das Ziel hat andere Bytes zurückgelesen als geschrieben.',
        'forbidden_host' => 'Diese Endpunkt-Adresse ist nicht zulässig.',
        'invalid_endpoint' => 'Geben Sie eine gültige https://-Endpunkt-URL für den Bucket ein.',
        'invalid_host' => 'Geben Sie einen gültigen Hostnamen oder eine IP-Adresse ein.',
        'host_key_mismatch' => 'Der Server hat einen anderen Hostschlüssel vorgelegt als den aufgezeichneten. Die Verbindung wurde abgebrochen.',
        'invalid_private_key' => 'Der private Schlüssel konnte nicht gelesen werden. Prüfen Sie, ob er vollständig eingefügt wurde.',
        'root_missing' => 'Der Zielordner existiert auf dem Server nicht. Legen Sie ihn an oder korrigieren Sie den Ordnerpfad.',
    ],

    'delete' => [
        'in_use' => ':name kann nicht gelöscht werden — es wird noch von :applications verwendet. Entfernen oder ändern Sie diese Backup-Ziele zuerst.',
        'and_more' => ':count weitere',
    ],

    'validation' => [
        'sftp_auth_required' => 'Geben Sie entweder ein Passwort oder einen privaten Schlüssel an.',
    ],
];
