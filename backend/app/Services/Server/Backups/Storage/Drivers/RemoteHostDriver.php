<?php

namespace App\Services\Server\Backups\Storage\Drivers;

use App\Contracts\PreparesDownloads;
use App\Contracts\StorageDriver;
use App\Http\Controllers\API\Server\BackupController;
use App\Models\StorageDestination;
use App\Rules\SafeRemoteHost;
use App\Rules\SingleLine;
use App\Support\RemoteHost;

/**
 * Shared behaviour for the providers that address a host and land in a real
 * directory tree (FTP and SFTP).
 *
 * Unlike S3 these have two notions of "where": the connection lands somewhere (`root`) and the
 * panel adds a subdirectory per destination (`prefix`). Stacking them wrong writes to a valid but
 * wrong location, so the composition happens here once, not in each driver.
 */
abstract class RemoteHostDriver implements PreparesDownloads, StorageDriver
{
    /**
     * The effective directory for this destination: the connection root with
     * the destination's prefix appended.
     *
     * Returns `''`, not `/`, when both are empty: empty means "wherever the login lands", while a
     * literal `/` is the filesystem root and fails for unprivileged accounts.
     *
     * A leading slash is kept. Trimming it turned an absolute `/backups` into a relative
     * `backups` under the login directory: SFTP silently created `~/backups`, FTP failed as an
     * unreachable host.
     */
    protected function remoteRoot(StorageDestination $destination): string
    {
        $configured = (string) $destination->configValue('root', '');
        $isAbsolute = str_starts_with($configured, '/');

        $root = trim($configured, '/');
        $prefix = trim((string) $destination->prefix, '/');

        $segments = array_values(array_filter([$root, $prefix], fn (string $s): bool => $s !== ''));
        $path = implode('/', $segments);

        // A bare `/` still resolves to '' (see above): it reads as "not set" in a form.
        return $isAbsolute && $path !== '' ? '/'.$path : $path;
    }

    /**
     * The round trip is the whole test; only the host is re-checked here.
     */
    public function preflight(StorageDestination $destination): ?string
    {
        // Check what the host resolves to now, not only when it was saved. Not pinned for the
        // connection: connecting by address would change the name a saved SFTP host key is held under.
        $host = (string) $destination->configValue('host', '');

        return $host !== '' && RemoteHost::resolvesToBlocked($host) ? 'storage.test.forbidden_host' : null;
    }

    /**
     * Validation shared by every host-addressed provider.
     *
     * `host` is required and goes through `SafeRemoteHost`: here it is the whole attack surface.
     *
     * @return array<string, mixed>
     */
    protected function hostRules(int $defaultPort): array
    {
        return [
            'config.host' => ['required', 'string', 'max:255', new SafeRemoteHost],
            'config.port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'config.username' => ['required', 'string', 'max:255', new SingleLine],
            // A remote path. `..` segments are refused: a mistake or an attempt to climb out.
            'config.root' => ['nullable', 'string', 'max:255', 'regex:#^[A-Za-z0-9._/-]*$#', 'not_regex:#(^|/)\.\.(/|$)#'],
        ];
    }

    /**
     * Nothing to repair: the panel did not create this location.
     */
    public function heal(StorageDestination $destination): void {}

    /**
     * No direct path by default; the caller falls back to `readStream()`.
     *
     * FTP and SFTP override this: their `readStream()` buffers the whole archive in `php://temp`.
     */
    public function downloadTo(StorageDestination $destination, string $key, string $path): bool
    {
        return false;
    }

    /**
     * Null: FTP and SFTP have no concept of a URL at all.
     *
     * Proxying a large archive through a PHP worker is rejected by
     * {@see BackupController::download()}. These drivers are {@see PreparesDownloads} instead: the
     * archive is copied to the panel on the queue and the local copy is served.
     */
    public function downloadUrl(StorageDestination $destination, string $key): ?string
    {
        return null;
    }

    /**
     * Null: FTP and SFTP have no resumable upload; the caller's bounded retry restarts the transfer.
     */
    public function uploadFrom(StorageDestination $destination, string $key, string $path, ?callable $onProgress = null): bool
    {
        return false;
    }
}
