<?php

/*
 * Storage destinations integration — Spanish stub. Keys mirror
 * lang/en/storage.php; values fall back to English until translated.
 * Parent agent: please translate these strings for the Spanish locale.
 */

return [
    'drivers' => [
        's3' => 'S3-compatible',
    ],

    'fields' => [
        'name' => 'Display name',
        'endpoint' => 'Endpoint URL',
        'region' => 'Region',
        'bucket' => 'Bucket',
        'prefix' => 'Key prefix (optional)',
        'access_key' => 'Access key',
        'secret_key' => 'Secret key',
    ],

    'placeholders' => [
        'endpoint' => 'https://s3.amazonaws.com',
        'region' => 'us-east-1',
        'prefix' => 'backups/production/',
    ],

    'help' => [
        'name' => 'A short label so you can tell destinations apart in the integration list.',
        'endpoint' => 'Leave default for AWS. Set for MinIO, R2, Backblaze B2, Wasabi, etc.',
        'region' => 'Region the bucket lives in (only required for AWS).',
        'prefix' => 'Optional path prefix inside the bucket (no leading slash).',
        'access_key' => 'Write-only — never returned by the API.',
    ],

    'status' => [
        'connected' => 'Connected',
        'never_tested' => 'Not yet tested',
    ],

    'test' => [
        'success' => 'Connection succeeded.',
        'failure' => 'Could not connect to the destination.',
        'invalid_credentials' => 'The destination rejected the credentials.',
        'unreachable' => 'The destination endpoint could not be reached.',
        'mismatch' => 'The destination wrote and read back different bytes.',
        'forbidden_host' => 'That endpoint address is not allowed.',
    ],

    'delete' => [
        'in_use' => 'Cannot delete :name — one or more backup targets still point at this destination. Remove or repoint those targets first.',
    ],
];
