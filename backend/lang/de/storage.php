<?php

/*
 * Copy for the Storage destinations integration — S3-compatible remote
 * targets that backups are uploaded to. The keys in this file render the
 * connect form, the row labels and the outcome of the test-connection probe.
 */

return [
    'drivers' => [
        's3' => 'S3-kompatibel',
    ],

    'fields' => [
        'name' => 'Anzeigename',
        'endpoint' => 'Endpunkt-URL',
        'region' => 'Region',
        'bucket' => 'Bucket',
        'prefix' => 'Schlüsselpräfix (optional)',
        'access_key' => 'Zugriffsschlüssel',
        'secret_key' => 'Geheimer Schlüssel',
    ],

    'placeholders' => [
        'endpoint' => 'https://s3.amazonaws.com',
        'region' => 'us-east-1',
        'prefix' => 'backups/production/',
    ],

    'help' => [
        'name' => 'Eine kurze Bezeichnung, um Ziele in der Integrationsliste zu unterscheiden.',
        'endpoint' => 'Für AWS auf dem Standardwert lassen. Für MinIO, R2, Backblaze B2, Wasabi usw. setzen.',
        'region' => 'Region, in der der Bucket liegt (nur für AWS erforderlich).',
        'prefix' => 'Optionales Pfadpräfix innerhalb des Buckets (ohne führenden Schrägstrich).',
        'access_key' => 'Nur schreibend — wird von der API nie zurückgegeben.',
    ],

    'status' => [
        'connected' => 'Verbunden',
        'never_tested' => 'Noch nicht getestet',
    ],

    'test' => [
        'success' => 'Verbindung erfolgreich hergestellt.',
        'failure' => 'Verbindung zum Ziel nicht möglich.',
        'invalid_credentials' => 'Das Ziel hat die Zugangsdaten abgelehnt.',
        'unreachable' => 'Der Endpunkt des Ziels war nicht erreichbar.',
        'mismatch' => 'Das Ziel hat andere Bytes zurückgelesen als geschrieben.',
        'forbidden_host' => 'Diese Endpunkt-Adresse ist nicht zulässig.',
        'invalid_endpoint' => 'Geben Sie eine gültige https://-Endpunkt-URL für den Bucket ein.',
    ],

    'delete' => [
        'in_use' => ':name kann nicht gelöscht werden — ein oder mehrere Backup-Ziele verweisen noch auf dieses Ziel. Entfernen oder ändern Sie diese zuerst.',
    ],
];
