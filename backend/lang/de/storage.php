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
        'google_drive' => 'Google Drive',
        'google_drive_oauth' => 'Google Drive (eigenes Konto)',
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
        'service_account_json' => 'Dienstkonto-Schlüssel (JSON)',
        'folder_id' => 'Ordner-ID der geteilten Ablage',
        'drive_name' => 'Geteilte Ablage',
        'client_email' => 'Adresse des Dienstkontos',
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
        'endpoint' => 'Für AWS auf dem Standardwert lassen. Für Cloudflare R2, Backblaze B2, Wasabi, DigitalOcean Spaces oder jeden S3-kompatiblen Dienst setzen.',
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
        'service_account_json' => 'Die vollständige JSON-Schlüsseldatei des Dienstkontos einfügen.',
        'folder_id' => 'Der Teil der Ordner-URL nach /folders/ — nicht der ganze Link.',
        'drive_shared_only' => 'Nur eine geteilte Ablage in Google Workspace funktioniert. Ein Dienstkonto hat keinen eigenen Speicher, daher werden Uploads in ein persönliches Drive auch bei leerem Konto abgelehnt.',
        'drive_share_with' => 'Geben Sie den Ordner der geteilten Ablage vor dem Test für die Adresse des Dienstkontos frei.',
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
        'bucket_not_found' => 'Es gibt keinen Bucket mit diesem Namen. Prüfen Sie den Bucket-Namen – Groß- und Kleinschreibung wird beachtet.',
        'wrong_region' => 'Der Bucket liegt in einer anderen Region. Geben Sie die Region an, in der der Bucket erstellt wurde.',
        'tls_failed' => 'Es konnte keine sichere (TLS-)Verbindung zum Endpunkt aufgebaut werden. Das Zertifikat des Servers fehlt, ist nicht vertrauenswürdig oder defekt – prüfen Sie die Endpunkt-URL und das Zertifikat des Servers.',
        'mismatch' => 'Das Ziel hat andere Bytes zurückgelesen als geschrieben.',
        'forbidden_host' => 'Diese Endpunkt-Adresse ist nicht zulässig.',
        'invalid_endpoint' => 'Geben Sie eine gültige https://-Endpunkt-URL für den Bucket ein.',
        'invalid_host' => 'Geben Sie einen gültigen Hostnamen oder eine IP-Adresse ein.',
        'host_key_mismatch' => 'Der Server hat einen anderen Hostschlüssel vorgelegt als den aufgezeichneten. Die Verbindung wurde abgebrochen.',
        'invalid_private_key' => 'Der private Schlüssel konnte nicht gelesen werden. Prüfen Sie, ob er vollständig eingefügt wurde.',
        'root_missing' => 'Der Zielordner existiert auf dem Server nicht. Legen Sie ihn an oder korrigieren Sie den Ordnerpfad.',
        'drive_personal' => 'Dieser Ordner liegt in einem persönlichen Drive. Ein Dienstkonto hat dort keinen Speicher, Sicherungen würden abgelehnt — verwenden Sie einen Ordner in einer geteilten Ablage.',
        'drive_not_shared' => 'Der Ordner existiert, aber dieses Dienstkonto hat keinen Zugriff darauf.',
        'drive_folder_missing' => 'Es wurde kein Ordner mit dieser ID gefunden.',
        'drive_not_a_folder' => 'Diese ID verweist auf eine Datei, nicht auf einen Ordner.',
        'drive_bad_key' => 'Der Dienstkonto-Schlüssel konnte nicht gelesen werden. Fügen Sie die vollständige JSON-Datei ein.',
        'drive_quota' => 'Google hat den Upload mangels Speicherkontingent abgelehnt — genau das passiert bei einem persönlichen Drive.',
        'drive_incomplete' => 'Fügen Sie vor dem Test den Dienstkonto-Schlüssel und die Ordner-ID hinzu.',
    ],

    'delete' => [
        'in_use' => ':name kann nicht gelöscht werden — es wird noch von :applications verwendet. Entfernen oder ändern Sie diese Backup-Ziele zuerst.',
        'holds_backups' => ':name kann nicht gelöscht werden — es enthält noch :count Backup(s). Löschen Sie zuerst diese Backups, damit ihre Archive auch aus dem Speicher entfernt werden.',
        'and_more' => ':count weitere',
    ],

    'validation' => [
        'sftp_auth_required' => 'Geben Sie entweder ein Passwort oder einen privaten Schlüssel an.',
        'sftp_key_is_public' => 'Das ist ein öffentlicher Schlüssel. Fügen Sie den privaten Schlüssel ein – die Datei ohne .pub.',
        'sftp_key_needs_passphrase' => 'Dieser Schlüssel ist mit einer Passphrase geschützt. Geben Sie die Passphrase ebenfalls ein.',
        'sftp_key_wrong_passphrase' => 'Die Passphrase entsperrt diesen Schlüssel nicht.',
        'sftp_key_invalid' => 'Das ist kein privater Schlüssel, den das Panel lesen kann. Fügen Sie den ganzen Schlüssel ein, einschließlich der BEGIN- und END-Zeilen.',
    ],

    'upload' => [
        'stalled' => 'Der Upload hat keine Daten mehr gesendet und wurde abgebrochen. Die Verbindung blieb offen, aber wurde nichts übertragen — meist bedeutet das, dass der Speicheranbieter sie getrennt hat. Die Sicherung versucht es beim nächsten Lauf erneut.',
    ],

    'oauth' => [
        'not_connected' => 'Noch nicht verbunden. Klicken Sie auf Verbinden, um den Zugriff auf Ihr Google-Konto zu erlauben.',
        'revoked' => 'Google hat diesen Zugriff widerrufen. Meist wurde die OAuth-App auf „Testing“ belassen — solche Tokens laufen nach etwa einer Woche ab — oder der Zugriff wurde unter myaccount.google.com entfernt. Verbinden Sie erneut.',
        'user_quota' => 'Ihr Google Drive ist voll. Geben Sie Speicher frei oder sichern Sie woanders.',
        'folder_missing' => 'Das Panel erreicht den Backup-Ordner nicht. Er wurde möglicherweise gelöscht, oder er gehört zu einem anderen Google-Konto oder OAuth-Client als dem jetzt verbundenen — das Panel sieht nur Ordner, die es selbst angelegt hat. Verbinden Sie erneut, dann wird ein neuer angelegt.',
        'denied' => 'Der Zugriff wurde im Google-Fenster abgelehnt. Es wurde nichts geändert.',
        'code_expired' => 'Diese Bestätigung wurde bereits verwendet oder ist abgelaufen. Klicken Sie auf Verbinden, um neu zu beginnen.',
        'bad_client' => 'Google kennt diese Client-ID nicht. Prüfen Sie, ob sie vollständig kopiert wurde, samt der Endung .apps.googleusercontent.com.',
        'wrong_client_type' => 'Dieser Client hat den falschen Typ. Erstellen Sie in der Google Cloud Console einen OAuth-Client vom Typ „Web application“ und fügen Sie dessen ID und Secret ein.',
        'redirect_mismatch' => 'Google hat die Weiterleitungsadresse abgelehnt. Kopieren Sie die unter der Schaltfläche Verbinden angezeigte Adresse genau so in Ihren OAuth-Client unter „Authorized redirect URIs“.',
        'panel_url_missing' => 'Dieses Panel kennt seine eigene Webadresse nicht und kann Google daher nicht mitteilen, wohin Sie zurückgeschickt werden sollen. Setzen Sie FRONTEND_URL in der Panel-Konfiguration.',
        'state_invalid' => 'Diese Anmeldung stammt nicht von diesem Panel. Klicken Sie auf Verbinden und bestätigen Sie erneut.',
        'state_expired' => 'Dieser Anmeldelink wurde bereits verwendet oder lag zu lange. Klicken Sie auf Verbinden, um neu zu beginnen.',
        'destination_missing' => 'Dieses Ziel wurde während der Bestätigung entfernt. Es wurde nichts gespeichert.',
        'start_failed' => 'Die Google-Anmeldung konnte nicht gestartet werden. Versuchen Sie es gleich erneut.',
        'poll_failed' => 'Die Google-Anmeldung konnte nicht abgeschlossen werden. Versuchen Sie es gleich erneut.',
        'token_failed' => 'Die Google-Anmeldung konnte nicht abgeschlossen werden. Versuchen Sie es gleich erneut.',
        'no_refresh_token' => 'Google hat den Zugriff erlaubt, aber kein dauerhaftes Token gesendet — das passiert, wenn dieses Konto der App bereits Zugriff gewährt hat. Entfernen Sie ihn unter myaccount.google.com bei „Drittanbieter-Zugriff“ und verbinden Sie erneut.',
        'api_disabled' => 'Die Google-Drive-API ist in Ihrem Google-Cloud-Projekt nicht aktiviert. Öffnen Sie APIs & Dienste → Bibliothek, suchen Sie „Google Drive API“ und klicken Sie auf Aktivieren. Die Anmeldung funktioniert auch ohne sie — deshalb fällt es erst jetzt auf.',
        'insufficient_scope' => 'Die Verbindung wurde bestätigt, erlaubt aber kein Anlegen von Dateien. Prüfen Sie, ob der OAuth-Zustimmungsbildschirm den Google-Drive-Bereich drive.file enthält, und verbinden Sie erneut.',
        'folder_failed' => 'Verbunden, aber der Backup-Ordner konnte in Ihrem Drive nicht angelegt werden. Der Grund steht in den Panel-Logs unter „storage“. Verbinden Sie erneut, sobald er behoben ist.',
        'wrong_provider' => 'Dieses Ziel verwendet keine Google-Anmeldung.',
    ],
];
