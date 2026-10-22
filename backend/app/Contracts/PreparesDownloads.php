<?php

namespace App\Contracts;

/**
 * A storage driver whose archives reach the browser through the panel.
 *
 * A capability, not a provider check — the same reason {@see StorageDriver}
 * exists. FTP and SFTP have no URL a browser could fetch an archive from, so
 * Download copies the archive to the panel on the queue first and serves the
 * local copy. Drivers that can hand over a link (S3's signed URL, Drive's own
 * link) do not implement this and are untouched by it.
 */
interface PreparesDownloads {}
