<?php

namespace App\Services\Server\Backups\Storage\Drivers;

use App\Contracts\StorageDriver;
use App\Models\StorageDestination;
use App\Rules\SafeRemoteHost;
use App\Rules\SingleLine;

/**
 * Shared behaviour for the providers that address a host and land in a real
 * directory tree (FTP and SFTP).
 *
 * These differ from S3 in one way that is easy to get wrong: **they have two
 * notions of "where"**. The connection itself lands somewhere (`root`), and
 * the panel then wants a subdirectory per destination (`prefix`). On S3 there
 * is only one — `prefix` *is* the root, because a bucket has no directories
 * to descend into.
 *
 * Stack those two wrong and the backup writes to a real, valid, silently
 * incorrect location: one level up, or into the account's home rather than the
 * backups folder. So the composition happens here, once, rather than in each
 * driver.
 */
abstract class RemoteHostDriver implements StorageDriver
{
    /**
     * The effective directory for this destination: the connection root with
     * the destination's prefix appended.
     *
     * Returns `''` rather than `/` when both are empty — an empty root means
     * "wherever the login lands", which is the correct default for an account
     * created specifically to receive backups. A literal `/` would mean the
     * filesystem root and would fail for every non-privileged account.
     *
     * 🔴 **A leading slash is kept.** This used to `trim($root, '/')`, which
     * turned the absolute path a user typed — `/backups`, the form every FTP
     * and SFTP client accepts — into the *relative* `backups`, resolved
     * against whatever directory the login happens to land in. Measured
     * against live servers on 2026-09-14, and the two protocols hid it
     * differently: SFTP creates missing directories, so a destination set to
     * `/backups` silently succeeded into `~/backups` and every archive went
     * somewhere the operator never chose; FTP refuses to resolve a root that
     * does not exist, so the same destination failed and was reported as an
     * unreachable host. A green probe against the wrong directory is the worse
     * of the two.
     *
     * Which is precisely the "real, valid, silently incorrect location" this
     * class was written to prevent — produced by the function written to
     * prevent it.
     */
    protected function remoteRoot(StorageDestination $destination): string
    {
        $configured = (string) $destination->configValue('root', '');
        $isAbsolute = str_starts_with($configured, '/');

        $root = trim($configured, '/');
        $prefix = trim((string) $destination->prefix, '/');

        $segments = array_values(array_filter([$root, $prefix], fn (string $s): bool => $s !== ''));
        $path = implode('/', $segments);

        // A bare `/` still resolves to '' rather than the filesystem root, for
        // the reason above: it is indistinguishable from "not set" in a form,
        // and reading it literally breaks every unprivileged account.
        return $isAbsolute && $path !== '' ? '/'.$path : $path;
    }

    /**
     * Nothing to check up front: for this provider a successful write really
     * does mean the destination works, so the round trip is the whole test.
     */
    public function preflight(StorageDestination $destination): ?string
    {
        return null;
    }

    /**
     * Validation shared by every host-addressed provider.
     *
     * `host` is required and goes through `SafeRemoteHost` — for these
     * providers the host field is the entire attack surface, where on S3 it
     * was an optional endpoint override.
     *
     * @return array<string, mixed>
     */
    protected function hostRules(int $defaultPort): array
    {
        return [
            'config.host' => ['required', 'string', 'max:255', new SafeRemoteHost],
            'config.port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'config.username' => ['required', 'string', 'max:255', new SingleLine],
            // A remote path, not a local one. Traversal segments are refused
            // outright: `..` in a destination root is either a mistake or an
            // attempt to climb out of the account's backup directory, and
            // neither should be stored.
            'config.root' => ['nullable', 'string', 'max:255', 'regex:#^[A-Za-z0-9._/-]*$#', 'not_regex:#(^|/)\.\.(/|$)#'],
        ];
    }

    /**
     * Nothing to repair: this destination's location is not something the panel
     * created, so its absence is somebody else's decision to undo.
     */
    public function heal(StorageDestination $destination): void {}

    /**
     * No special path needed: this driver's `readStream()` genuinely streams,
     * so the caller's copy never holds the whole archive anywhere.
     */
    public function downloadTo(StorageDestination $destination, string $key, string $path): bool
    {
        return false;
    }
}
