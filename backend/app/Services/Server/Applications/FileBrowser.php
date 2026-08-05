<?php

namespace App\Services\Server\Applications;

use App\Exceptions\Server\Application\FileOperationException;
use App\Models\Application;
use App\Rules\SafeRelativePath;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use App\Support\Bytes;
use Illuminate\Support\Carbon;

/**
 * List, view, edit and download a site's own files.
 *
 * Every command runs as **the site's own Linux user**, via `runuser -u`, never
 * as the panel's root. That is the one thing that makes this feature safe: a
 * client-supplied path is validated at the edges (`App\Rules\SafeRelativePath`
 * refuses `..`, absolute paths and anything outside a conservative charset),
 * but validation only catches the tricks someone thought to name. A symlink
 * inside the site pointing outside it is not a `..` in the string — it is
 * exactly the shape of attack path validation cannot see. Running as the
 * site's own user means that attack, if it slipped through, gains nothing:
 * the user already cannot read anything outside what they own.
 *
 * Intentionally still smaller than a full file manager in some ways (no
 * in-place text search, no bulk multi-select) — see this repo's file-manager
 * research for the reasoning behind what's here versus what isn't.
 */
class FileBrowser
{
    /** Larger belongs to SFTP, which every panel in the research pointed at
     * for exactly this reason — buffering a big file through a PHP process is
     * how a download becomes a memory exhaustion bug. */
    public const MAX_BYTES = 5 * 1024 * 1024;

    /** Bigger than MAX_BYTES on purpose — an uploaded plugin zip is larger
     * than anything reasonable to open in a text editor, but still bounded:
     * this is buffered through a PHP process the same as everything else
     * here, not streamed. */
    public const UPLOAD_MAX_BYTES = 50 * 1024 * 1024;

    /** Total *uncompressed* size an archive is allowed to expand to — the
     * zip-bomb guard, checked from the listing before a single byte is
     * extracted. */
    public const MAX_UNCOMPRESSED_BYTES = 250 * 1024 * 1024;

    /** A second, independent bomb guard: a bomb built from many tiny files
     * passes a byte-size check but not an entry-count one. */
    public const MAX_ARCHIVE_ENTRIES = 10000;

    private const BINARY_SNIFF_BYTES = 8192;

    public function __construct(
        private ServerOps $serverOps,
        private ApplicationProvisioner $provisioner,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(Application $application, string $path): array
    {
        $target = $this->resolve($application, $path);
        $this->assertType($application, $target, 'd');

        $result = $this->run($application, [
            'find', $target, '-mindepth', '1', '-maxdepth', '1', '-printf', "%f\t%y\t%s\t%T@\n",
        ], 'list');

        $entries = [];

        foreach (explode("\n", trim($result->output())) as $line) {
            if ($line === '') {
                continue;
            }

            [$name, $type, $size, $mtime] = explode("\t", $line, 4);
            $modifiedAt = Carbon::createFromTimestamp((int) (float) $mtime);

            $entries[] = [
                'name' => $name,
                'type' => match ($type) {
                    'd' => 'dir',
                    'l' => 'symlink',
                    default => 'file',
                },
                'size' => (int) $size,
                'size_human' => Bytes::human((int) $size),
                'modified_at' => $modifiedAt->format('d-m-Y H:i:s'),
                'modified_at_human' => $modifiedAt->diffForHumans(),
            ];
        }

        // Directories first, then alphabetical — how every file manager in
        // the research (and every OS file browser) orders a listing.
        usort($entries, fn (array $a, array $b): int => match (true) {
            $a['type'] === 'dir' && $b['type'] !== 'dir' => -1,
            $a['type'] !== 'dir' && $b['type'] === 'dir' => 1,
            default => strcasecmp($a['name'], $b['name']),
        });

        return $entries;
    }

    /**
     * @return array{content: string, size: int, binary: bool}
     */
    public function read(Application $application, string $path): array
    {
        $target = $this->resolve($application, $path);
        $size = $this->assertType($application, $target, 'f');

        abort_if($size > self::MAX_BYTES, 422, __('errors/application.file_too_large'));

        $content = $this->run($application, ['cat', $target], 'read')->output();

        return [
            'content' => $content,
            'size' => $size,
            'binary' => str_contains(substr($content, 0, self::BINARY_SNIFF_BYTES), "\0"),
        ];
    }

    /** Raw bytes for a download response. Binary is fine here — it is the client's file, not rendered as text. */
    public function download(Application $application, string $path): string
    {
        $target = $this->resolve($application, $path);
        $size = $this->assertType($application, $target, 'f');

        abort_if($size > self::MAX_BYTES, 422, __('errors/application.file_too_large'));

        return $this->run($application, ['cat', $target], 'download')->output();
    }

    public function write(Application $application, string $path, string $content): void
    {
        $target = $this->resolve($application, $path);
        // Editing, not creating: a path that does not already resolve to a
        // regular file is refused, the same distinction "edit" implies
        // everywhere else in the panel.
        $this->assertType($application, $target, 'f');

        $this->run($application, ['tee', $target], 'write', input: $content);
    }

    /**
     * Writes a new file at `path`, or overwrites one that is already there.
     *
     * The original client-supplied filename is never used to build the
     * target — `$path` is the whole answer to "where does this go", validated
     * the same way as everything else here. A filename is exactly as
     * attacker-controlled as any other client input, and stitching a
     * validated directory to an unvalidated name would reopen the traversal
     * surface `SafeRelativePath` exists to close.
     */
    public function upload(Application $application, string $path, string $contents): void
    {
        $target = $this->resolve($application, $path);
        $directory = dirname($target);

        // The directory has to already exist — no implicit `mkdir`, the same
        // restraint the rest of this feature keeps. The file itself is what
        // upload is allowed to create; the folder structure is not.
        $this->assertType($application, $directory, 'd');

        $this->run($application, ['tee', $target], 'upload', input: $contents);
    }

    /**
     * Creates an empty directory, or succeeds as a no-op if one is already
     * there — idempotent the same way `mkdir -p` is, but only one level: the
     * containing directory must already exist, the same restraint every
     * other write in this class keeps.
     */
    public function createDirectory(Application $application, string $path): void
    {
        $target = $this->resolve($application, $path);
        $this->assertType($application, dirname($target), 'd');

        $existing = $this->stat($application, $target);

        if ($existing !== null) {
            abort_if($existing['type'] !== 'd', 422, __('errors/application.path_exists'));

            return;
        }

        $this->run($application, ['mkdir', $target], 'mkdir');
    }

    /**
     * Renames or moves a file/directory — one endpoint for both, since `mv`
     * does not distinguish them.
     *
     * Deliberately the opposite default from `upload()`: the destination must
     * **not** already exist. A mistyped rename silently destroying an
     * unrelated file is a far easier accident than upload's "replace this
     * specific thing I picked" — overwrite-by-default is right there and
     * wrong here.
     */
    public function rename(Application $application, string $path, string $targetPath): void
    {
        $source = $this->resolve($application, $path);
        $sourceStat = $this->stat($application, $source);
        abort_if($sourceStat === null || ! in_array($sourceStat['type'], ['f', 'd'], true), 404);

        $target = $this->resolve($application, $targetPath);
        abort_if($this->stat($application, $target) !== null, 422, __('errors/application.path_exists'));
        $this->assertType($application, dirname($target), 'd');

        $this->run($application, ['mv', $source, $target], 'rename');
    }

    /**
     * Deletes a file or a directory (recursively). The one destructive
     * operation in this feature — no trash, no undo, same stated limitation
     * as `extract()`'s lack of rollback. The site root itself can never be
     * the target; that would be deleting the whole site through a "delete
     * one thing" button.
     */
    public function delete(Application $application, string $path): void
    {
        abort_if($path === '', 422, __('errors/application.cannot_delete_root'));

        $target = $this->resolve($application, $path);
        $stat = $this->stat($application, $target);
        abort_if($stat === null, 404);

        $command = $stat['type'] === 'd' ? ['rm', '-rf', $target] : ['rm', '-f', $target];

        $this->run($application, $command, 'delete');
    }

    /**
     * Extracts a `.zip` or `.tar.gz`/`.tgz` already sitting in the site into
     * an existing directory, in place, overwriting anything that collides —
     * the same "drop a file here" expectation `upload()` carries, and what
     * installing or updating a plugin actually needs.
     *
     * The archive is untrusted content, unlike `Restores\Steps\ExtractArchive`
     * (which unpacks the panel's own backups). Every entry is listed and
     * validated *before* anything is written: no `.`/`..`/absolute entries
     * (zip-slip), no symlink entries (an archive can plant one that a later
     * static request might follow — neither `unzip` nor `tar` resolves it
     * during extraction, but the web server serving the finished site would),
     * and a cap on total uncompressed bytes and entry count (zip-bomb).
     * Extraction then runs as the site's own user, same as everything else
     * here — the validation above is the primary control, running as the
     * site's user is the backstop if it is ever wrong.
     */
    public function extract(Application $application, string $path, string $targetPath): void
    {
        $archive = $this->resolve($application, $path);
        $this->assertType($application, $archive, 'f');

        $format = $this->archiveFormat($path);
        abort_if($format === null, 422, __('errors/application.file_not_archive'));

        $target = $this->resolve($application, $targetPath);
        $this->assertType($application, $target, 'd');

        $entries = $format === 'zip'
            ? $this->listZipEntries($application, $archive)
            : $this->listTarEntries($application, $archive);

        $this->validateArchiveEntries($application, $entries, $target);

        $command = $format === 'zip'
            ? ['unzip', '-o', '-d', $target, $archive]
            : ['tar', '-xzf', $archive, '-C', $target];

        $this->run($application, $command, 'extract');
    }

    private function archiveFormat(string $path): ?string
    {
        $lower = strtolower($path);

        return match (true) {
            str_ends_with($lower, '.zip') => 'zip',
            str_ends_with($lower, '.tar.gz'), str_ends_with($lower, '.tgz') => 'tar',
            default => null,
        };
    }

    /**
     * The zip-slip/zip-bomb checks, shared by both archive formats so the
     * safety rules can't quietly drift apart between them.
     *
     * @param  array<int, array{name: string, type: string, size: int}>  $entries
     */
    private function validateArchiveEntries(Application $application, array $entries, string $target): void
    {
        abort_if($entries === [], 422, __('errors/application.archive_empty'));
        abort_if(count($entries) > self::MAX_ARCHIVE_ENTRIES, 422, __('errors/application.archive_too_many_entries'));

        $appRoot = rtrim($this->provisioner->documentRoot($application), '/');
        $totalBytes = 0;

        foreach ($entries as $entry) {
            abort_if($entry['type'] === 'l', 422, __('errors/application.archive_has_symlink'));

            $name = rtrim($entry['name'], '/');

            if ($name === '') {
                continue;
            }

            abort_unless(SafeRelativePath::isSafe($name), 422, __('errors/application.archive_unsafe_entry'));

            $entryTarget = "{$target}/{$name}";
            abort_unless(
                $entryTarget === $appRoot || str_starts_with($entryTarget, "{$appRoot}/"),
                422,
                __('errors/application.archive_unsafe_entry'),
            );

            $totalBytes += $entry['size'];
        }

        abort_if($totalBytes > self::MAX_UNCOMPRESSED_BYTES, 422, __('errors/application.archive_too_large'));
    }

    /**
     * One line per zip entry: permission string (first character is the
     * type — `d`/`l`/`-`), size, and name. A single `unzip -Z` pass gives
     * name+type+size together, so listing and the size total come from the
     * same read rather than two commands that could disagree.
     *
     * @return array<int, array{name: string, type: string, size: int}>
     */
    private function listZipEntries(Application $application, string $archive): array
    {
        $result = $this->serverOps->run(
            $this->asUser($application, ['unzip', '-Z', $archive]),
            ['feature' => 'application', 'op' => 'file_archive_list', 'application' => $application->id],
            timeout: 30,
        );

        // A failed listing means the archive could not be read — corrupt or
        // not really a zip despite the extension. That is the caller's input
        // being wrong, not a server operation failing.
        abort_if($result->failed(), 422, __('errors/application.archive_unreadable'));

        $entries = [];

        foreach (explode("\n", $result->output()) as $line) {
            if (preg_match('/^([-dl])[-rwxsSt]{9}\s+\S+\s+\S+\s+(\d+)\s+\S+\s+\S+\s+\S+\s+\S+\s+(.*)$/', $line, $m) !== 1) {
                continue;
            }

            $entries[] = ['type' => $m[1], 'size' => (int) $m[2], 'name' => $m[3]];
        }

        return $entries;
    }

    /**
     * One line per tar entry via `tar -tvzf`: permission string, owner/group,
     * size, date, time, name — a symlink entry's name is suffixed
     * `-> target`, which is never read since symlink entries are rejected by
     * type alone in `validateArchiveEntries()`.
     *
     * @return array<int, array{name: string, type: string, size: int}>
     */
    private function listTarEntries(Application $application, string $archive): array
    {
        $result = $this->serverOps->run(
            $this->asUser($application, ['tar', '-tvzf', $archive]),
            ['feature' => 'application', 'op' => 'file_archive_list', 'application' => $application->id],
            timeout: 30,
        );

        abort_if($result->failed(), 422, __('errors/application.archive_unreadable'));

        $entries = [];

        foreach (explode("\n", $result->output()) as $line) {
            if (preg_match('/^([-dl])[-rwxsSt]{9}\s+\S+\s+(\d+)\s+\S+\s+\S+\s+(.*)$/', $line, $m) !== 1) {
                continue;
            }

            $entries[] = ['type' => $m[1], 'size' => (int) $m[2], 'name' => $m[3]];
        }

        return $entries;
    }

    private function resolve(Application $application, string $path): string
    {
        $root = rtrim($this->provisioner->documentRoot($application), '/');

        return $path === '' ? $root : "{$root}/{$path}";
    }

    /**
     * Confirms the path exists and is the expected type, without treating
     * "does not exist" as a server-operation failure — that is a 404 for the
     * caller, not something needing a support reference.
     */
    private function assertType(Application $application, string $target, string $expected): int
    {
        $stat = $this->stat($application, $target);

        abort_if($stat === null || $stat['type'] !== $expected, 404);

        return $stat['size'];
    }

    /**
     * Like `assertType()`, but returns `null` instead of aborting when the
     * path does not exist — for the operations where "does not exist yet" is
     * a valid, expected answer (create-directory, rename's destination),
     * not an error.
     *
     * @return array{type: string, size: int}|null
     */
    private function stat(Application $application, string $target): ?array
    {
        $result = $this->serverOps->run(
            $this->asUser($application, ['find', $target, '-maxdepth', '0', '-printf', "%y\t%s"]),
            ['feature' => 'application', 'op' => 'file_stat', 'application' => $application->id],
            timeout: 30,
        );

        $output = trim($result->output());

        if ($result->failed() || $output === '') {
            return null;
        }

        [$type, $size] = explode("\t", $output, 2);

        return ['type' => $type, 'size' => (int) $size];
    }

    /**
     * @param  array<int, string>  $command
     */
    private function run(Application $application, array $command, string $op, ?string $input = null): ServerOpsResult
    {
        $result = $this->serverOps->run(
            $this->asUser($application, $command),
            ['feature' => 'application', 'op' => "file_{$op}", 'application' => $application->id],
            timeout: 60,
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
