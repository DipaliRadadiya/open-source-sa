<?php

/*
 * Copy for the Storage destinations integration — S3-compatible remote
 * targets that backups are uploaded to. The keys in this file render the
 * connect form, the row labels and the outcome of the test-connection probe.
 */

return [
    'drivers' => [
        // One driver for the whole family: any S3-compatible endpoint (AWS,
        // Cloudflare R2, Backblaze B2, Wasabi, DigitalOcean Spaces, or a
        // self-hosted service). The endpoint URL alone routes around AWS —
        // everything else is bog-standard S3.
        's3' => 'S3-compatible',
        'ftp' => 'FTP',
        'sftp' => 'SFTP',
        'google_drive' => 'Google Drive',
        'google_drive_oauth' => 'Google Drive (your own account)',
        'webdav' => 'WebDAV',
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
        'service_account_json' => 'Service account key (JSON)',
        'folder_id' => 'Shared Drive folder ID',
        'drive_name' => 'Shared Drive',
        'client_email' => 'Service account address',
        'base_uri' => 'Server URL',
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
        'endpoint' => 'Leave default for AWS. Set for Cloudflare R2, Backblaze B2, Wasabi, DigitalOcean Spaces, or any S3-compatible service.',
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
        'service_account_json' => 'Paste the whole JSON key file for the service account.',
        'folder_id' => 'The part of the folder URL after /folders/ — not the whole link.',
        'drive_shared_only' => 'Only a Google Workspace Shared Drive works. A service account has no storage of its own, so uploads to a personal Drive are refused even when the account is empty.',
        'drive_share_with' => 'Share the Shared Drive folder with the service account address before testing.',
        'base_uri' => 'The full WebDAV URL, including any folder — for example https://cloud.example.com/remote.php/dav/files/you/',
        'pcloud_warning' => 'pCloud states that its WebDAV is intended for small files and may be interrupted, and it stops working entirely when two-factor authentication is enabled on the account. Both matter for backups: test the destination, and keep a second one elsewhere.',
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
        'drive_personal' => 'That folder is on a personal Drive. A service account has no storage there, so backups would be refused — use a folder in a Shared Drive.',
        'drive_not_shared' => 'The folder exists, but this service account has not been given access to it.',
        'drive_folder_missing' => 'No folder with that ID was found.',
        'drive_not_a_folder' => 'That ID points at a file, not a folder.',
        'drive_bad_key' => 'The service account key could not be read. Paste the whole JSON file.',
        'drive_quota' => 'Google refused the upload for lack of storage quota, which is what happens on a personal Drive.',
        'drive_incomplete' => 'Add the service account key and the folder ID before testing.',
        'dav_full' => 'The server refused the upload because it is out of space.',
        'dav_reset' => 'The server closed the connection without answering. If this is pCloud, two-factor authentication on the account will do this.',
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

    'oauth' => [
        'not_connected' => 'Not connected yet. Use Connect to approve access to your Google account.',
        'revoked' => 'Google has revoked this access. That usually means the OAuth app was left in "Testing" — Google expires those after about a week — or access was removed at myaccount.google.com. Connect again to restore it.',
        'user_quota' => 'Your Google Drive is full. Free up space, or back up somewhere else.',
        'folder_missing' => 'The backup folder is gone from your Drive. Connect again and the panel will make a new one.',
        'denied' => 'Access was refused on the Google screen. Nothing was changed.',
        'code_expired' => 'The code expired before it was approved. Press Connect for a new one.',
        'bad_client' => 'Google does not recognise that client ID. Check it was copied whole, including the .apps.googleusercontent.com ending.',
        'wrong_client_type' => 'That client is the wrong type. In Google Cloud Console create an OAuth client of type "TVs and Limited Input devices" — a Web application client cannot be used here — then paste its ID and secret.',
        'start_failed' => 'Could not start the Google sign-in. Try again in a moment.',
        'poll_failed' => 'Could not complete the Google sign-in. Try again in a moment.',
        'no_refresh_token' => 'Google approved access but sent no lasting token, which happens when this account already granted this app. Remove it at myaccount.google.com under Third-party access, then connect again.',
        'folder_failed' => 'Connected, but the backup folder could not be created in your Drive. Check you have space, then connect again.',
        'wrong_provider' => 'This destination does not use Google sign-in.',
    ],
];
