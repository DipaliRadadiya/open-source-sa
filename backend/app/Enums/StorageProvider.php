<?php

namespace App\Enums;

/**
 * Which service a storage destination talks to.
 *
 * Stored in a column, because inferring it from the endpoint hostname is wrong for self-hosted S3
 * and meaningless for an FTP host. See `storage-providers-design.md`.
 */
enum StorageProvider: string
{
    case S3 = 's3';
    case Ftp = 'ftp';
    case Sftp = 'sftp';
    case GoogleDrive = 'google_drive';

    /**
     * Google Drive as the *user*, not a service account.
     *
     * `google_drive` is a service account with no Drive quota, so it can only write into a
     * Workspace Shared Drive. This one uses the user's own storage, which is the only way a free
     * Gmail account can use Drive. Credentials, scope and client differ, so it is its own provider.
     */
    case GoogleDriveOauth = 'google_drive_oauth';

    /** A folder on the panel's own server. Not off-site: lost with the server or its disk. */
    case Local = 'local';

    /** The translated display name. Lives here so a Resource needs no driver to label a row. */
    public function title(): string
    {
        return __('storage.drivers.'.$this->value);
    }

    /**
     * Whether this provider addresses a remote *host* rather than a service endpoint URL.
     *
     * Decides which SSRF rule applies: `SafeProviderHost` demands an https URL, which a bare
     * `backup.example.com:22` can never satisfy. See `SafeRemoteHost`.
     */
    public function addressesBareHost(): bool
    {
        return $this === self::Ftp || $this === self::Sftp;
    }

    /**
     * Whether the destination is a real directory tree rather than a flat key-value store.
     *
     * S3 keys containing slashes are still one key; FTP/SFTP need the parent directory to exist
     * before writing. `UploadArtifact` asks this before it writes.
     */
    public function hasDirectories(): bool
    {
        return $this !== self::S3;
    }
}
