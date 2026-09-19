<?php

namespace App\Enums;

/**
 * Which service a storage destination talks to.
 *
 * This is the fact the table did not record. Until it did, the driver was
 * always S3 and the panel *inferred* the provider by matching the endpoint
 * hostname — a guess that is wrong for any self-hosted S3 service and
 * meaningless for an FTP host. The column replaces the guess.
 *
 * Phase 1 ships `s3`, `ftp` and `sftp`. Google Drive lands on
 * the same seam later; see `storage-providers-design.md`.
 */
enum StorageProvider: string
{
    case S3 = 's3';
    case Ftp = 'ftp';
    case Sftp = 'sftp';
    case GoogleDrive = 'google_drive';

    /**
     * The same service, a different principal — and that is why it is its own
     * provider rather than a flag on the one above.
     *
     * `google_drive` authenticates as a *service account*, which has no Drive
     * quota of its own and can therefore only write into a Workspace Shared
     * Drive. This one authenticates as the *user*, so the files are theirs and
     * their own storage pays for them. That is the only way a free Gmail
     * account can use Drive at all.
     *
     * Nothing is shared between the two but the word "Drive": different
     * credentials, different scope, different client construction, and this one
     * creates its own folder instead of being given an id. A boolean on the
     * existing provider would have meant every field below it changing meaning
     * depending on the flag.
     */
    case GoogleDriveOauth = 'google_drive_oauth';

    /**
     * The translated display name. Kept here rather than on the driver so a
     * Resource can label a destination without building a driver for it — a
     * row in a list has no business constructing a filesystem client.
     */
    public function title(): string
    {
        return __('storage.drivers.'.$this->value);
    }

    /**
     * Whether this provider addresses a remote *host* rather than a service
     * endpoint URL.
     *
     * The distinction decides which SSRF rule applies: `SafeProviderHost`
     * parses a URL and demands https, which a bare `backup.example.com:22`
     * can never satisfy. See `SafeRemoteHost`.
     */
    public function addressesBareHost(): bool
    {
        return $this === self::Ftp || $this === self::Sftp;
    }

    /**
     * Whether the destination is a real directory tree rather than a flat
     * key-value store.
     *
     * S3 has no directories — a key containing slashes is still one key. FTP
     * and SFTP do, and writing `2026/09/site.tar.gz` there fails unless the
     * parent exists. `UploadArtifact` asks this before it writes.
     */
    public function hasDirectories(): bool
    {
        return $this !== self::S3;
    }
}
