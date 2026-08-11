<?php

namespace App\Services\Server\Applications;

use App\Exceptions\Server\Application\FileOperationException;
use App\Models\Application;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;

/**
 * Resumable, arbitrarily large uploads for the file manager.
 *
 * `FileBrowser::upload()` buffers a whole file through PHP memory, which is
 * why it is capped at UPLOAD_MAX_BYTES — its own docblock points at SFTP for
 * anything bigger. This is that "anything bigger", without the SFTP.
 *
 * The panel shares a server with the sites it hosts, so the cost that matters
 * is not the upload's duration, it is how much it disturbs everything else on
 * the box. Two things dominate that, and the design is shaped entirely around
 * them:
 *
 * **Write amplification.** A naive upload writes every byte three times —
 * nginx spools the body to `client_body_temp`, PHP's multipart parser spools
 * it again to `upload_tmp_dir`, then the target write makes a third copy. A
 * 1 GB upload becomes 3 GB of disk traffic, and the damage is not the writes
 * themselves: streaming 3 GB through the page cache evicts the *hosted sites'*
 * hot files, so their next requests hit disk. Here each chunk arrives as a raw
 * body (no multipart parser, no PHP temp file), nginx buffers it in RAM rather
 * than on disk (`client_body_buffer_size` >= chunk size), and it is appended
 * straight to the part file. One write, not three.
 *
 * **Memory.** Chunks are piped to `tee` as a *stream*, never as a string, so
 * a 50 GB upload costs the same resident memory as a 50 KB one. This is why
 * ServerOps::run() accepts a resource.
 *
 * Assembly is free: the part file lives inside the site's own tree, so
 * finalising is `mv` — a same-filesystem rename, which moves no data and is
 * atomic. A half-finished upload is therefore never visible at the target
 * path, and never partially served.
 *
 * State is the part file itself. Its size *is* the resume offset, so there is
 * no session table to keep in sync, nothing to reconcile if the panel restarts
 * mid-upload, and — deliberately — no database write per chunk.
 *
 * Everything runs as the site's own Linux user, for the reasons in
 * FileBrowser's docblock; the part file is owned by the site from the first
 * byte, so finalising never has to juggle ownership.
 */
class ChunkedUpload
{
    /**
     * Where part files live, relative to the document root.
     *
     * Inside the site's own tree so that finalising is a rename rather than a
     * copy across filesystems. Dotted, because every vhost template already
     * denies dotfiles — a partially uploaded file must never be reachable over
     * HTTP. Shares the `.panel/` prefix with BasicAuthManager's htpasswd.
     */
    public const TEMP_DIR = '.panel/uploads';

    /**
     * Free space that must remain after a chunk is written, whichever is
     * larger: a fixed floor or a share of the disk.
     *
     * This is the guard that makes "any size" safe to offer. The panel shares
     * a filesystem with every site on the box, so an upload that fills the
     * disk does not fail politely — it takes down every hosted site, plus the
     * databases, plus the panel's own ability to report why. Refusing a chunk
     * is recoverable; a full disk is an outage.
     */
    public const MIN_FREE_BYTES = 5 * 1024 * 1024 * 1024;

    public const MIN_FREE_FRACTION = 0.10;

    /**
     * Upload ids are generated here, not accepted from the client: the id
     * becomes a filename, and a client-chosen one is a path-traversal
     * primitive. 32 hex chars, validated on the way back in.
     */
    public const ID_PATTERN = '/^[a-f0-9]{32}$/';

    public function __construct(
        private ServerOps $serverOps,
        private ApplicationProvisioner $provisioner,
    ) {}

    /**
     * Opens an upload and returns its id.
     *
     * Validates up front what would otherwise only surface after the user has
     * spent an hour uploading: that the destination directory exists, and that
     * the disk has room.
     */
    public function begin(Application $application, string $path, int $expectedBytes = 0): string
    {
        $this->assertDirectoryExists($application, dirname($this->resolve($application, $path)));

        // Checked against the *whole* declared size, not just the floor: the
        // point is to fail now rather than an hour in, with the disk full and
        // a part file to clean up. The client is not trusted to be honest
        // about this -- the per-chunk check still runs regardless -- but it is
        // trusted enough to save everyone the wasted hour when it is right.
        $this->assertDiskHasRoom($application, $expectedBytes);

        $id = bin2hex(random_bytes(16));

        $this->run($application, ['mkdir', '-p', $this->tempDir($application)], 'upload_begin');
        // Create it empty, so `status` on a fresh upload reports 0 rather than
        // "not found" and the client has one less case to special-case.
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

        // Checked per chunk rather than once at the start: the total is not
        // known (the client is not trusted to declare it), other sites are
        // writing to the same disk throughout, and a long upload has plenty of
        // time for the free space to change under it.
        $this->assertDiskHasRoom($application, 0);

        // `tee -a` rather than a shell append: no shell, so the path is an
        // argument and not something to quote. The stream is piped, so the
        // chunk is never held in PHP memory.
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
     * A rename, not a copy: same filesystem, so no bytes move and the
     * destination goes from absent to complete in one step. Nothing ever
     * observes a half-written file at the target path.
     */
    public function finalize(Application $application, string $id, string $path): void
    {
        $part = $this->partPath($application, $this->assertValidId($id));
        $target = $this->resolve($application, $path);

        abort_if($this->size($application, $part) === null, 404);
        $this->assertDirectoryExists($application, dirname($target));

        $this->run($application, ['mv', '-f', $part, $target], 'upload_finalize');
    }

    /**
     * Discards an upload. Safe to call for an id that is already gone, so a
     * client retrying an abort does not get an error for succeeding.
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
     * A closed laptop mid-upload leaves the part file behind, and with no size
     * limit that file can be enormous. Without this, abandoned uploads are a
     * slow disk leak that ends as the outage MIN_FREE_BYTES exists to prevent.
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
        return rtrim($this->provisioner->documentRoot($application), '/').'/'.self::TEMP_DIR;
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
        );

        return $result->failed() ? null : (int) trim($result->output());
    }

    private function assertDirectoryExists(Application $application, string $directory): void
    {
        $result = $this->serverOps->run(
            $this->asUser($application, ['test', '-d', $directory]),
            ['feature' => 'application', 'op' => 'file_upload_dir_check', 'application' => $application->id],
            timeout: 15,
        );

        abort_if($result->failed(), 422, __('errors/application.upload_directory_missing'));
    }

    /**
     * What the disk can currently accept, for a client that wants to know
     * before it starts rather than after.
     *
     * Selecting a 40 GB file onto a disk with 20 GB free should say so at the
     * moment of selection — not an hour later, with the disk near full and a
     * part file to reap. The numbers are returned rather than just a verdict
     * so the UI can tell the user *how much* room there is.
     *
     * @return array{available: int, usable: int, floor: int}
     */
    public function space(Application $application): array
    {
        $disk = $this->freeSpace($application);

        if ($disk === null) {
            // Unknown free space must not read as "no room" — that would block
            // uploads the disk can take. PHP_INT_MAX means "cannot say"; the
            // per-chunk guard and ENOSPC remain as the real backstops.
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

        // Unreadable free space is not a reason to block an upload the disk
        // may well have room for; the write itself still fails safely on
        // ENOSPC. Logged by ServerOps either way.
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
            throw new FileOperationException($result->reference, busy: $result->busy, staleLock: $result->staleLock);
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
