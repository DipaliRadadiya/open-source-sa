<?php

/*
 * Copy for the Storage destinations integration — S3-compatible remote
 * targets that backups are uploaded to. The keys in this file render the
 * connect form, the row labels and the outcome of the test-connection probe.
 */

return [
    'drivers' => [
        // One driver for now: any S3-compatible endpoint (AWS, MinIO,
        // Cloudflare R2, Backblaze B2, Wasabi, …). The endpoint URL alone
        // routes around AWS — everything else is bog-standard S3.
        's3' => 'S3-compatible',
        'ftp' => 'FTP',
        'sftp' => 'SFTP',
    ],

    'fields' => [
        'name' => 'Display name',
        'endpoint' => 'Endpoint URL',
        'region' => 'Region',
        'bucket' => 'Bucket',
        'prefix' => 'Key prefix (optional)',
        'access_key' => 'Access key',
        'secret_key' => 'Secret key',
        'host' => 'Host',
        'port' => 'Port',
        'username' => 'Username',
        'password' => 'Password',
        'root' => 'Remote directory',
        'ssl' => 'Use TLS (FTPS)',
        'passive' => 'Passive mode',
        'private_key' => 'Private key',
        'passphrase' => 'Key passphrase',
        'host_fingerprint' => 'Host key fingerprint',
    ],

    'placeholders' => [
        'endpoint' => 'https://s3.amazonaws.com',
        'region' => 'us-east-1',
        'prefix' => 'backups/production/',
        'host' => 'backup.example.com',
        'root' => 'backups/',
    ],

    'help' => [
        'name' => 'A short label so you can tell destinations apart in the integration list.',
        'endpoint' => 'Leave default for AWS. Set for MinIO, R2, Backblaze B2, Wasabi, etc.',
        'region' => 'Region the bucket lives in (only required for AWS).',
        'prefix' => 'Optional path prefix inside the bucket (no leading slash).',
        'access_key' => 'Write-only — never returned by the API.',
        'host' => 'Hostname or IP address of the server that will hold the backups.',
        'port' => 'Leave empty to use the default.',
        'root' => 'Directory on the server to write into. Leave empty to use wherever the login lands.',
        'ssl' => 'Strongly recommended. Without it the password and the entire backup travel in the clear.',
        'passive' => 'Keep this on unless the server requires otherwise.',
        'private_key' => 'Paste the whole private key. Used instead of a password.',
        'passphrase' => 'Only needed if the private key itself is encrypted.',
        'host_fingerprint' => 'Recorded the first time the panel connects, then enforced. Compare it with the key on the server to be certain.',
        'plain_ftp_warning' => 'TLS is off. The password and every backup will be sent unencrypted.',
    ],

    'status' => [
        'connected' => 'Connected',
        'never_tested' => 'Not yet tested',
        'failed' => 'Last test failed',
    ],

    'test' => [
        'success' => 'Connection succeeded.',
        'failure' => 'Could not connect to the destination.',
        'invalid_credentials' => 'The destination rejected the credentials.',
        'unreachable' => 'The destination endpoint could not be reached.',
        'mismatch' => 'The destination wrote and read back different bytes.',
        'forbidden_host' => 'That endpoint address is not allowed.',
        'invalid_endpoint' => 'Enter a valid https:// endpoint URL for the bucket.',
        'invalid_host' => 'Enter a valid hostname or IP address.',
        'host_key_mismatch' => 'The server presented a different host key than the one recorded. The connection was stopped.',
        'invalid_private_key' => 'The private key could not be read. Check that it was pasted whole.',
        'root_missing' => 'The destination folder does not exist on the server. Create it, or correct the folder path.',
    ],

    'delete' => [
        // :applications names the sites, because "one or more backup targets"
        // leaves the operator hunting through every application to find which
        // ones. Capped by the guard — see `and_more`.
        'in_use' => 'Cannot delete :name — it is still used by :applications. Remove or repoint those backup targets first.',
        'and_more' => ':count more',
    ],

    'validation' => [
        'sftp_auth_required' => 'Provide either a password or a private key.',
    ],
];
