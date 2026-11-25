<?php

namespace App\Services\Server\Applications;

use App\Exceptions\Server\Application\FileOperationException;
use App\Models\Application;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;

/**
 * Resumable, arbitrarily large uploads for the file manager.
 *
 * `FileBrowser::upload()` buffers a whole file through PHP memory and is capped at
 * UPLOAD_MAX_BYTES; this is the "anything bigger", without SFTP.
 *
 * The panel shares a server with the sites it hosts, so the design minimises disturbance to them:
 *
 * **Write amplification.** A naive upload writes every byte three times (nginx
 * `client_body_temp`, PHP's `upload_tmp_dir`, the target), and streaming that through the page
 * cache evicts the hosted sites' hot files. Here each chunk arrives as a raw body (no multipart
 * parser, no PHP temp file), nginx buffers it in RAM (`client_body_buffer_size` >= chunk size),
 * and it is appended straight to the part file: one write, not three.
 *
 * **Memory.** Chunks are piped to `tee` as a *stream*, never a string, so memory use does not
 * grow with the upload (hence ServerOps::run() accepts a resource).
 *
 * Assembly is free: the part file lives in the site's own tree, so finalising is an atomic
 * same-filesystem `mv`, and a half-finished upload is never visible at the target path.
 *
 * State is the part file itself: its size *is* the resume offset, so there is no session table
 * and no database write per chunk.
 *
 * Everything runs as the site's own Linux user (see FileBrowser), so the part file is owned by
 * the site from the first byte.
 */
class ChunkedUpload
{
    /**
     * Where part files live, under the application's `.panel` directory, which sits ABOVE the
     * document root.
     *
     * `mv` is a rename only on the *same filesystem*, and `.panel/uploads` and `public_html` are
     * both under `{home}/{slug}`. Never put it in the webroot: a dotted name is the only thing
     * keeping a part file off the public internet (one deny rule per web server, which
     * OpenLiteSpeed did not apply to `.panel`).
     */
    public const TEMP_DIR = 'uploads';

    /**
     * Free space that must remain after a chunk is written, whichever is larger: a fixed floor
     * or a share of the disk.
     *
     * This guard makes "any size" safe: an upload that fills the shared disk takes down every
     * site, the databases and the panel. Refusing a chunk is recoverable; a full disk is not.
     */
    public const MIN_FREE_BYTES = 5 * 1024 * 1024 * 1024;

    public const MIN_FREE_FRACTION = 0.10;

    /**
     * Upload ids are generated here, never accepted from the client: the id becomes a filename,
     * so a client-chosen one is a path-traversal primitive. 32 hex chars, validated on the way in.
     */
    public const ID_PATTERN = '/^[a-f0-9]{32}$/';

    public function __construct(
        private ServerOps $serverOps,
        private ApplicationProvisioner $provisioner,
        private PanelDirectory $panelDirectory,
    ) {}

    /**
     * The largest chunk this server can actually accept.
     *
     * Told to the client rather than assumed: Laravel's ValidatePostSize compares Content-Length
     * with `post_max_size` on every request (PUT included) and answers 413 when it does not fit.
     * The margin covers the request line and headers, which count toward that limit.
     */
    public static function maxChunkBytes(): int
    {
        $post = self::iniBytes((string) ini_get('post_max_size'));

        // 0 means unlimited in PHP: report the ladder's own ceiling.
        if ($post <= 0) {
            return 64 * 1024 * 1024;
        }

        return max(1024 * 1024, $post - (512 * 1024));
    }

    /** PHP's shorthand ini notation — "8M", "512K", "1G" — in bytes. */
    private static function iniBytes(string $value): int
    {
        $value = trim($value);

        if ($value === '') {
            return 0;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /**
     * Opens an upload and returns its id.
     *
     * Validates up front what would otherwise only surface after a long upload: that the
     * destination directory exists, and that the disk has room.
     */
    public function begin(Application $application, string $path, int $expectedBytes = 0): string
    {
        $target = $this->resolve($application, $path);

        $this->assertDirectoryExists($application, dirname($target));

        // Checked here as well as at finalize: refusing now costs nothing.
        $this->assertAbsent($application, $target);

        // Against the *whole* declared size, to fail now rather than an hour in. The client is not
        // trusted (the per-chunk check still runs), only used to save the wasted hour.
        $this->assertDiskHasRoom($application, $expectedBytes);

        $id = bin2hex(random_bytes(16));

        $this->ensureTempDir($application);

        // Created empty, so `status` on a fresh upload reports 0 rather than "not found".
        $this->run($application, ['touch', $this->partPath($application, $id)], 'upload_begin');

        return $id;
    }

    /**
     * Appends one chunk, read from a stream, and returns the new total.
     *
     * @param  resource  $stream
     */
    public function append(Application $application, string $id, mixed $stream): int
    {
        $part = $this->partPath($application, $this->assertValidId($id));

        // Per chunk, not once: the total is untrusted and other sites write to the same disk.
        $this->assertDiskHasRoom($application, 0);

        // `tee -a`, not a shell append: no shell, so the path is an argument. Piped as a stream,
        // so the chunk is never held in PHP memory.
        $this->run($application, ['tee', '-a', $part], 'upload_chunk', input: $stream, timeout: 300);

        return $this->size($application, $part);
    }

    /**
     * Bytes received so far — the client's resume offset after an interruption.
     */
    public function status(Application $application, string $id): int
    {
        return $this->size($application, $this->partPath($application, $this->assertValidId($id)));
    }

    /**
     * Moves the completed upload into place.
     *
     * A rename, not a copy: same filesystem, so the destination goes from absent to complete in
     * one step and nothing observes a half-written file.
     */
    public function finalize(Application $application, string $id, string $path): void
    {
        $part = $this->partPath($application, $this->assertValidId($id));
        $target = $this->resolve($application, $path);

        abort_if($this->size($application, $part) === null, 404);
        $this->assertDirectoryExists($application, dirname($target));

        // Again: the file can appear between `begin` and here, and `mv -f` would replace it silently.
        $this->assertAbsent($application, $target);

        $this->run($application, ['mv', '-f', $part, $target], 'upload_finalize');
    }

    /**
     * Discards an upload. Safe for an id that is already gone, so a retried abort succeeds.
     */
    public function abort(Application $application, string $id): void
    {
        $this->run(
            $application,
            ['rm', '-f', $this->partPath($application, $this->assertValidId($id))],
            'upload_abort',
        );
    }

    /**
     * Deletes part files idle longer than $hours.
     *
     * An abandoned upload leaves a part file behind, possibly enormous: a slow disk leak toward
     * the outage MIN_FREE_BYTES exists to prevent.
     */
    public function reap(Application $application, int $hours = 24): void
    {
        $dir = $this->tempDir($application);

        if ($this->size($application, $dir) === null) {
            return;
        }

        $this->run($application, [
            'find', $dir, '-maxdepth', '1', '-type', 'f', '-name', '*.part',
            '-mmin', '+'.($hours * 60), '-delete',
        ], 'upload_reap');
    }

    private function tempDir(Application $application): string
    {
        return $application->panelPath().'/'.self::TEMP_DIR;
    }

    /**
     * Create the upload directory and hand it to the site user.
     *
     * Elevated, then chowned: `runuser ... mkdir` is denied inside root-owned `.panel` (see
     * PanelDirectory).
     */
    private function ensureTempDir(Application $application): void
    {
        $this->panelDirectory->ensure($application, self::TEMP_DIR);
    }

    private function partPath(Application $application, string $id): string
    {
        return $this->tempDir($application)."/{$id}.part";
    }

    private function resolve(Application $application, string $path): string
    {
        return rtrim($this->provisioner->documentRoot($application), '/').'/'.ltrim($path, '/');
    }

    private function assertValidId(string $id): string
    {
        abort_unless(preg_match(self::ID_PATTERN, $id) === 1, 404);

        return $id;
    }

    /**
     * Size in bytes, or null when the path does not exist.
     */
    private function size(Application $application, string $path): ?int
    {
        $result = $this->serverOps->run(
            $this->asUser($application, ['stat', '-c', '%s', $path]),
            ['feature' => 'application', 'op' => 'file_upload_size', 'application' => $application->id],
            timeout: 15,
            expectedExitCodes: [1], // "does not exist" is the null answer
        );

        return $result->failed() ? null : (int) trim($result->output());
    }

    /**
     * Refuse a target that already exists.
     *
     * `mv -f` at the end of an upload replaces silently, so without this an existing file is
     * destroyed without trace.
     */
    private function assertAbsent(Application $application, string $target): void
    {
        $result = $this->serverOps->probe(
            $this->asUser($application, ['test', '-e', $target]),
            ['feature' => 'application', 'op' => 'file_upload_exists_check', 'application' => $application->id],
            timeout: 15,
        );

        // Fail closed. `test -e` exits 1 for "nothing there" and sudo exits 1 for "you may not ask";
        // reading the second as the first makes the later `mv -f` a silent delete.
        if (! $result->answered) {
            throw new FileOperationException($result->reference);
        }

        abort_if($result->ok, 422, __('errors/application.upload_exists', ['name' => basename($target)]));
    }

    private function assertDirectoryExists(Application $application, string $directory): void
    {
        $result = $this->serverOps->probe(
            $this->asUser($application, ['test', '-d', $directory]),
            ['feature' => 'application', 'op' => 'file_upload_dir_check', 'application' => $application->id],
            timeout: 15,
        );

        // Fail closed: "could not look" must not be reported as "directory missing".
        if (! $result->answered) {
            throw new FileOperationException($result->reference);
        }

        abort_unless($result->ok, 422, __('errors/application.upload_directory_missing'));
    }

    /**
     * What the disk can currently accept, so a client can check before it starts. Numbers are
     * returned rather than a verdict so the UI can say *how much* room there is.
     *
     * @return array{available: int, usable: int, floor: int}
     */
    public function space(Application $application): array
    {
        $disk = $this->freeSpace($application);

        if ($disk === null) {
            // Unknown must not read as "no room". PHP_INT_MAX means "cannot say"; the per-chunk
            // guard and ENOSPC remain the backstops.
            return ['available' => PHP_INT_MAX, 'usable' => PHP_INT_MAX, 'floor' => 0];
        }

        return [
            'available' => $disk['available'],
            'usable' => max(0, $disk['available'] - $disk['floor']),
            'floor' => $disk['floor'],
        ];
    }

    /**
     * Refuses the write if it would leave the shared disk too close to full.
     */
    private function assertDiskHasRoom(Application $application, int $incoming): void
    {
        $disk = $this->freeSpace($application);

        // Unreadable free space does not block an upload; the write still fails safely on ENOSPC.
        if ($disk === null) {
            return;
        }

        abort_if(
            $disk['floor'] > $disk['available'] - $incoming,
            507,
            __('errors/application.upload_insufficient_space'),
        );
    }

    /**
     * @return array{total: int, available: int, floor: int}|null
     */
    private function freeSpace(Application $application): ?array
    {
        $result = $this->serverOps->run(
            $this->asUser($application, ['df', '-Pk', $this->provisioner->documentRoot($application)]),
            ['feature' => 'application', 'op' => 'file_upload_disk_check', 'application' => $application->id],
            timeout: 15,
        );

        if ($result->failed()) {
            return null;
        }

        $lines = preg_split('/\R/', trim($result->output())) ?: [];
        $fields = preg_split('/\s+/', trim((string) end($lines))) ?: [];

        // df -Pk: Filesystem, 1024-blocks, Used, Available, Capacity, Mounted
        if (count($fields) < 4 || ! is_numeric($fields[1]) || ! is_numeric($fields[3])) {
            return null;
        }

        $total = (int) $fields[1] * 1024;

        return [
            'total' => $total,
            'available' => (int) $fields[3] * 1024,
            'floor' => max(self::MIN_FREE_BYTES, (int) ($total * self::MIN_FREE_FRACTION)),
        ];
    }

    /**
     * @param  array<int, string>  $command
     */
    private function run(
        Application $application,
        array $command,
        string $op,
        mixed $input = null,
        int $timeout = 60,
    ): ServerOpsResult {
        $result = $this->serverOps->run(
            $this->asUser($application, $command),
            ['feature' => 'application', 'op' => "file_{$op}", 'application' => $application->id],
            timeout: $timeout,
            input: $input,
        );

        if ($result->failed()) {
            throw new FileOperationException($result->reference, busy: $result->busy, staleLock: $result->staleLock, denied: $result->denied, timedOut: $result->timedOut);
        }

        return $result;
    }

    /**
     * @param  array<int, string>  $command
     * @return array<int, string>
     */
    private function asUser(Application $application, array $command): array
    {
        return array_merge(['runuser', '-u', $application->systemUser->username, '--'], $command);
    }
}
