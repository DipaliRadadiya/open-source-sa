<?php

namespace App\Enums;

/**
 * Which service a storage destination talks to.
 *
 * This is the fact the table did not record. Until it did, the driver was
 * always S3 and the panel *inferred* the provider by matching the endpoint
 * hostname — a guess that is wrong for a self-hosted MinIO and meaningless for
 * an FTP host. The column replaces the guess.
 *
 * Phase 1 ships `s3`, `ftp` and `sftp`. Google Drive and WebDAV/pCloud land on
 * the same seam later; see `storage-providers-design.md`.
 */
enum StorageProvider: string
{
    case S3 = 's3';
    case Ftp = 'ftp';
    case Sftp = 'sftp';
    case GoogleDrive = 'google_drive';

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
