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

    /** Backups kept per file. Mirrors `ApplicationEnvironment::KEEP_BACKUPS`
     * — enough to undo a mistake, bounded. */
    public const KEEP_BACKUPS = 5;

    /** Recursive search is heavier than a flat listing and has no natural
     * page size — capped the same way `LogManager` bounds a tail read,
     * with a `truncated` flag so the caller knows there's more. */
    public const MAX_SEARCH_RESULTS = 200;

    /**
     * How many paths one bulk request may name.
     *
     * `rm`/`chmod`/`cp` take a list natively, but an argument vector has a
     * kernel limit (ARG_MAX) and crossing it fails obscurely, mid-operation,
     * with some paths already handled. A refusal the caller can read beats a
     * truncation it cannot see.
     */
    public const MAX_BULK_PATHS = 250;

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
        $this->assertRootExists($application);
        $target = $this->resolve($application, $path);
        $this->assertType($application, $target, 'd');

        $result = $this->run($application, [
            'find', $target, '-mindepth', '1', '-maxdepth', '1', '-printf', "%f\t%y\t%s\t%T@\t%m\t%u\t%g\n",
        ], 'list');

        $entries = [];

        foreach (explode("\n", trim($result->output())) as $line) {
            if ($line === '') {
                continue;
            }

            [$name, $type, $size, $mtime, $mode, $owner, $group] = explode("\t", $line, 7);

            $entries[] = $this->buildEntry($name, $type, $size, $mtime, $mode, $owner, $group);
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
     * Recursively searches a subtree (default: the whole site) by filename,
     * case-insensitively. Flat across every matched depth, unlike `list()` —
     * a result's `path` (relative to the site root, not to `$scopePath`) is
     * what tells the caller where it actually lives.
     *
     * Capped at `MAX_SEARCH_RESULTS`; `truncated` tells the caller there was
     * more than that so the UI can say so rather than silently showing a
     * partial list as if it were complete.
     *
     * @return array{entries: array<int, array<string, mixed>>, truncated: bool}
     */
    public function search(Application $application, string $scopePath, string $query): array
    {
        $this->assertRootExists($application);
        $target = $this->resolve($application, $scopePath);
        $this->assertType($application, $target, 'd');

        // `-iname`'s pattern is find's own glob syntax, not a literal
        // substring — a query containing `*`/`?`/`[` must be escaped or it
        // would be interpreted as a wildcard instead of matched literally.
        $pattern = '*'.$this->escapeFindPattern($query).'*';

        $result = $this->run($application, [
            'find', $target, '-mindepth', '1', '-iname', $pattern, '-printf', "%P\t%y\t%s\t%T@\t%m\t%u\t%g\n",
        ], 'search');

        $entries = [];

        foreach (explode("\n", trim($result->output())) as $line) {
            if ($line === '') {
                continue;
            }

            // `%P` is relative to $target (the search scope), not the site
            // root — rejoined with $scopePath so a result's `path` is always
            // usable directly against every other endpoint here, regardless
            // of which subtree was searched.
            [$relativeToScope, $type, $size, $mtime, $mode, $owner, $group] = explode("\t", $line, 7);
            $relativePath = $scopePath === '' ? $relativeToScope : "{$scopePath}/{$relativeToScope}";

            $entries[] = ['path' => $relativePath] + $this->buildEntry(basename($relativePath), $type, $size, $mtime, $mode, $owner, $group);
        }

        usort($entries, fn (array $a, array $b): int => strcasecmp($a['path'], $b['path']));

        $truncated = count($entries) > self::MAX_SEARCH_RESULTS;

        return [
            'entries' => $truncated ? array_slice($entries, 0, self::MAX_SEARCH_RESULTS) : $entries,
            'truncated' => $truncated,
        ];
    }

    /**
     * Total size of a directory's contents — deliberately not part of
     * `list()`. A recursive walk over an entry with thousands of files (a
     * WordPress `uploads` folder, say) would make every listing as slow as
     * its heaviest subfolder; this is its own on-demand call instead, made
     * only when a user actually asks for one folder's size.
     *
     * @return array{size: int, size_human: string}
     */
    public function folderSize(Application $application, string $path): array
    {
        $this->assertRootExists($application);
        $target = $this->resolve($application, $path);
        $this->assertType($application, $target, 'd');

        // Return cached value when fresh (under 1 hour).
        if ($application->directory_size_bytes !== null) {
            $bytes = (int) $application->directory_size_bytes;

            return ['size' => $bytes, 'size_human' => Bytes::human($bytes)];
        }

        $output = trim($this->run($application, ['du', '-sb', $target], 'folder_size')->output());
        [$bytes] = explode("\t", $output, 2);
        $bytes = (int) $bytes;

        // Persist so the next call avoids the disk hit.
        $application->updateQuietly(['directory_size_bytes' => $bytes]);

        return ['size' => $bytes, 'size_human' => Bytes::human($bytes)];
    }

    /**
     * @return array{name: string, type: string, size: int, size_human: string, modified_at: string, modified_at_human: string, mode: ?string, owner: ?string, group: ?string}
     */
    private function buildEntry(string $name, string $type, string $size, string $mtime, string $mode, string $owner, string $group): array
    {
        $entryType = match ($type) {
            'd' => 'dir',
            'l' => 'symlink',
            default => 'file',
        };

        $modifiedAt = Carbon::createFromTimestamp((int) (float) $mtime);

        // A symlink's own mode/ownership (`lrwxrwxrwx`, always) isn't a
        // meaningful permission to show or set — omitted the same way size
        // is uninteresting for it, rather than showing a constant that never
        // varies.
        $isSymlink = $entryType === 'symlink';

        return [
            'name' => $name,
            'type' => $entryType,
            'size' => (int) $size,
            'size_human' => Bytes::human((int) $size),
            'modified_at' => $modifiedAt->format('d-m-Y H:i:s'),
            'modified_at_human' => $modifiedAt->diffForHumans(),
            'mode' => $isSymlink ? null : $mode,
            'owner' => $isSymlink ? null : $owner,
            'group' => $isSymlink ? null : $group,
        ];
    }

    /** Escapes find's own glob metacharacters so a search query is matched literally, not as a wildcard pattern. */
    private function escapeFindPattern(string $value): string
    {
        return str_replace(['\\', '*', '?', '['], ['\\\\', '\\*', '\\?', '\\['], $value);
    }

    /**
     * @return array{content: string, size: int, binary: bool, backups: array<int, array{name: string, created_at: string}>}
     */
    public function read(Application $application, string $path): array
    {
        $this->assertRootExists($application);
        $target = $this->resolve($application, $path);
        $size = $this->assertType($application, $target, 'f');

        abort_if($size > self::MAX_BYTES, 422, __('errors/application.file_too_large'));

        $content = $this->run($application, ['cat', $target], 'read')->output();

        return [
            'content' => $content,
            'size' => $size,
            'binary' => str_contains(substr($content, 0, self::BINARY_SNIFF_BYTES), "\0"),
            'backups' => $this->backups($application, $target),
        ];
    }

    /** Raw bytes for a download response. Binary is fine here — it is the client's file, not rendered as text. */
    /**
     * A file's size and a generator over its bytes.
     *
     * Deliberately not capped at MAX_BYTES the way `read()` is. That limit
     * exists because a file has to fit in memory to be shown in an editor;
     * a download does not, and capping it meant the panel could accept a
     * multi-gigabyte upload and then refuse to give the same file back.
     * Nothing is buffered here — the caller streams each chunk out as it
     * arrives, so resident memory is one chunk regardless of the file.
     *
     * The size is returned alongside so the response can carry a real
     * Content-Length and the browser can show progress rather than an
     * indeterminate spinner.
     *
     * @return array{size: int, chunks: \Generator<int, string>}
     */
    public function download(Application $application, string $path): array
    {
        $this->assertRootExists($application);
        $target = $this->resolve($application, $path);
        $size = $this->assertType($application, $target, 'f');

        return [
            'size' => $size,
            'chunks' => $this->serverOps->stream(
                $this->asUser($application, ['cat', $target]),
                ['feature' => 'application', 'op' => 'file_download', 'application' => $application->id],
            ),
        ];
    }

    /**
     * Overwrites an existing file, keeping a copy of what was there —
     * mirrors `ApplicationEnvironment::write()`'s backup-before-write, just
     * generalised to any file rather than only `.env`.
     *
     * The backup comes first and its failure is fatal: this is the whole
     * safety story for "edit", and a save that silently skipped the copy
     * would be the one time that promise mattered.
     */
    public function write(Application $application, string $path, string $content): void
    {
        $target = $this->resolve($application, $path);
        // Editing, not creating: a path that does not already resolve to a
        // regular file is refused, the same distinction "edit" implies
        // everywhere else in the panel.
        $this->assertType($application, $target, 'f');

        $this->backup($application, $target);

        $this->run($application, ['tee', $target], 'write', input: $content);
    }

    /**
     * Previous saves for one file, newest first.
     *
     * Stored under `.panel/backups/`, not beside the file — a name like
     * `plugin.php.bak-...` sitting next to `plugin.php` would be reachable
     * over HTTP unless every vhost template happens to block that exact
     * pattern. `.panel/` is already outside every vhost's served paths (see
     * `PoolManager`'s session/log paths), so backups live there instead of
     * inventing a second thing for the web server to be told to ignore.
     *
     * @return array<int, array{name: string, created_at: string}>
     */
    public function backups(Application $application, string $target): array
    {
        $result = $this->serverOps->run(
            $this->asUser($application, [
                'find', $this->backupsDirectory($target), '-maxdepth', '1',
                '-name', basename($target).'.bak-*', '-printf', '%f\n',
            ]),
            ['feature' => 'application', 'op' => 'file_backups', 'application' => $application->id],
            timeout: 15,
        );

        if ($result->failed()) {
            return [];
        }

        $names = array_values(array_filter(array_map('trim', explode("\n", $result->output()))));
        rsort($names);

        return array_map(fn (string $name): array => [
            'name' => $name,
            'created_at' => $this->timestampFromBackupName($name),
        ], $names);
    }

    /**
     * Puts a previous save back — taking a backup of the current content
     * first, so restoring the wrong one is itself undoable.
     */
    public function restoreBackup(Application $application, string $path, string $name): void
    {
        $target = $this->resolve($application, $path);
        $this->assertType($application, $target, 'f');

        $prefix = basename($target).'.bak-';

        abort_unless(
            str_starts_with($name, $prefix) && preg_match('/^\d{8}-\d{6}$/', substr($name, strlen($prefix))) === 1,
            422,
            __('errors/application.unknown_backup'),
        );

        $source = "{$this->backupsDirectory($target)}/{$name}";
        $stat = $this->stat($application, $source);
        abort_if($stat === null, 404);

        $content = $this->run($application, ['cat', $source], 'read_backup')->output();

        $this->write($application, $path, $content);
    }

    private function backupsDirectory(string $target): string
    {
        return dirname($target).'/.panel/backups';
    }

    private function backup(Application $application, string $target): void
    {
        if ($this->stat($application, $target) === null) {
            // Nothing to back up yet — write() is about to create the file
            // for the first time (unreachable via the HTTP endpoint today,
            // since write() always requires an existing file first, but this
            // method stays correct if that ever changes).
            return;
        }

        $directory = $this->backupsDirectory($target);
        $this->run($application, ['mkdir', '-p', $directory], 'backup_dir');

        $name = basename($target).'.bak-'.now()->format('Ymd-His');

        $this->run($application, ['cp', '-p', $target, "{$directory}/{$name}"], 'backup');

        $this->pruneBackups($application, $target);
    }

    private function pruneBackups(Application $application, string $target): void
    {
        $surplus = array_slice($this->backups($application, $target), self::KEEP_BACKUPS);

        foreach ($surplus as $backup) {
            $this->run($application, ['rm', '-f', "{$this->backupsDirectory($target)}/{$backup['name']}"], 'backup_prune');
        }
    }

    /** `plugin.php.bak-20260804-132011` → `04-08-2026 13:20:11`, the panel's timestamp format. */
    private function timestampFromBackupName(string $name): string
    {
        if (preg_match('/(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})(\d{2})$/', $name, $m) !== 1) {
            return '';
        }

        return "{$m[3]}-{$m[2]}-{$m[1]} {$m[4]}:{$m[5]}:{$m[6]}";
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
     * Copies a file or directory (recursively) to a new path — same shape as
     * `rename()`, source untouched instead of moved.
     *
     * Same non-overwrite default as `rename()`, for the same reason: a
     * typo'd destination silently clobbering something is a worse failure
     * mode than refusing and asking again.
     */
    public function copy(Application $application, string $path, string $targetPath): void
    {
        $source = $this->resolve($application, $path);
        $sourceStat = $this->stat($application, $source);
        abort_if($sourceStat === null || ! in_array($sourceStat['type'], ['f', 'd'], true), 404);

        $target = $this->resolve($application, $targetPath);
        abort_if($this->stat($application, $target) !== null, 422, __('errors/application.path_exists'));
        $this->assertType($application, dirname($target), 'd');

        $this->run($application, ['cp', '-r', $source, $target], 'copy');
    }

    /**
     * Packages a file or directory into a new `.zip` elsewhere in the site —
     * the reverse of `extract()`.
     *
     * Unlike extract, there is no zip-slip surface here: the panel is
     * archiving files it already trusts from this site's own filesystem, not
     * accepting someone else's archive. `zip -r` runs with the source's
     * *parent* directory as `cwd` and the source's own basename as the
     * argument, so the archive contains relative paths (`my-folder/file.txt`)
     * rather than the full server path — the same reason every archiver
     * defaults to relative paths when you "compress this folder" in a
     * desktop file manager.
     */
    public function compress(Application $application, string $path, string $targetPath): void
    {
        $source = $this->resolve($application, $path);
        $sourceStat = $this->stat($application, $source);
        abort_if($sourceStat === null || ! in_array($sourceStat['type'], ['f', 'd'], true), 404);

        abort_unless(str_ends_with(strtolower($targetPath), '.zip'), 422, __('errors/application.target_not_zip'));

        $target = $this->resolve($application, $targetPath);
        abort_if($this->stat($application, $target) !== null, 422, __('errors/application.path_exists'));
        $this->assertType($application, dirname($target), 'd');

        $this->run(
            $application,
            ['zip', '-r', $target, basename($source)],
            'compress',
            cwd: dirname($source),
        );
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
     * Sets the mode on a single file or directory — the counterpart to
     * `PermissionFixer::fix()`'s whole-site reset, for the one-off case of
     * "this one file needs to be different".
     *
     * Exactly 3 octal digits (owner/group/other rwx), nothing else: no
     * setuid/setgid/sticky bit. Not because a site's own user setting those
     * on their own file is some grave danger, but because a plain three-digit
     * mode is the entire vocabulary every other permissions surface in this
     * panel uses (`0755`/`0644`/`0600`/`0700`) — a fourth digit would be a
     * new kind of input this feature alone accepts, for a case nobody asked
     * for.
     */
    public function chmod(Application $application, string $path, string $mode): void
    {
        $target = $this->resolve($application, $path);
        $stat = $this->stat($application, $target);
        abort_if($stat === null || ! in_array($stat['type'], ['f', 'd'], true), 404);

        $this->run($application, ['chmod', $mode, $target], 'chmod');
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
     * Confirms the web root directory actually exists on disk.
     *
     * When the path is wrong (e.g. app created with default web_root but directory
     * was named differently), FileBrowser would silently return an empty listing —
     * which looks like "no files" rather than "wrong path". This check returns
     * a clear 422 error instead.
     */
    private function assertRootExists(Application $application): void
    {
        $root = rtrim($this->provisioner->documentRoot($application), '/');

        $result = $this->serverOps->run(
            $this->asUser($application, ['test', '-d', $root]),
            ['feature' => 'application', 'op' => 'file_root_check', 'application' => $application->id],
            timeout: 15,
        );

        abort_if($result->failed(), 422, __('errors/application.web_root_not_found'));
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
    private function run(Application $application, array $command, string $op, ?string $input = null, ?string $cwd = null): ServerOpsResult
    {
        $result = $this->serverOps->run(
            $this->asUser($application, $command),
            ['feature' => 'application', 'op' => "file_{$op}", 'application' => $application->id],
            timeout: 60,
            input: $input,
            cwd: $cwd,
        );

        if ($result->failed()) {
            throw new FileOperationException($result->reference, busy: $result->busy, staleLock: $result->staleLock);
        }

        return $result;
    }

    /**
     * Stats many paths in one command.
     *
     * The reason bulk operations are worth having at all: statting N paths
     * one at a time is N processes before any work starts, which is most of
     * what made deleting a selection slow enough to be unusable.
     *
     * `find` reports a missing path on stderr and exits non-zero while still
     * processing the rest, so the exit code is deliberately ignored — absence
     * from the output *is* the answer for a path that is not there.
     *
     * @param  list<string>  $paths  relative to the document root
     * @return array<string, array{type: string, mode: string}> keyed by relative path
     */
    private function statMany(Application $application, array $paths): array
    {
        if ($paths === []) {
            return [];
        }

        $root = rtrim($this->provisioner->documentRoot($application), '/');
        $targets = array_map(fn (string $path): string => $this->resolve($application, $path), $paths);

        $result = $this->serverOps->run(
            $this->asUser($application, array_merge(
                ['find'], $targets, ['-maxdepth', '0', '-printf', "%p\t%y\t%m\n"],
            )),
            ['feature' => 'application', 'op' => 'file_stat_many', 'application' => $application->id],
            timeout: 60,
        );

        $found = [];

        foreach (explode("\n", trim($result->output())) as $line) {
            if ($line === '') {
                continue;
            }

            [$absolute, $type, $mode] = array_pad(explode("\t", $line, 3), 3, '');

            $relative = $absolute === $root ? '' : ltrim(substr($absolute, strlen($root)), '/');
            $found[$relative] = ['type' => $type, 'mode' => $mode];
        }

        return $found;
    }

    /**
     * Deletes many paths, reporting the outcome of each.
     *
     * Files and directories are separated rather than sent through one
     * `rm -rf`: `delete()` has always used `-f` for a file and `-r` only for a
     * directory, and doing a bulk delete with a blanket `-r` would quietly
     * widen what a single mistaken path can destroy.
     *
     * The result is derived by re-statting afterwards rather than by reading
     * `rm`'s stderr. `rm` reports failures it can attribute and stays silent
     * about ones it cannot; what is actually on disk afterwards is the only
     * answer that cannot be wrong.
     *
     * @param  list<string>  $paths
     * @return array{succeeded: list<string>, failed: list<array{path: string, reason: string}>}
     */
    public function deleteMany(Application $application, array $paths): array
    {
        $this->assertRootExists($application);

        // The site root is not a path the file manager may delete, in bulk or
        // otherwise -- it would take the document root with it.
        abort_if(in_array('', $paths, true), 422, __('errors/application.cannot_delete_root'));

        $found = $this->statMany($application, $paths);
        $failed = $this->missing($paths, $found);

        $directories = [];
        $files = [];

        foreach (array_keys($found) as $relative) {
            $target = $this->resolve($application, $relative);
            $found[$relative]['type'] === 'd' ? $directories[] = $target : $files[] = $target;
        }

        if ($directories !== []) {
            $this->tolerant($application, array_merge(['rm', '-rf'], $directories), 'delete_many');
        }

        if ($files !== []) {
            $this->tolerant($application, array_merge(['rm', '-f'], $files), 'delete_many');
        }

        $remaining = $this->statMany($application, array_keys($found));
        $succeeded = [];

        foreach (array_keys($found) as $relative) {
            if (array_key_exists($relative, $remaining)) {
                $failed[] = ['path' => $relative, 'reason' => 'failed'];

                continue;
            }

            $succeeded[] = $relative;
        }

        return ['succeeded' => $succeeded, 'failed' => $failed];
    }

    /**
     * Copies or moves many paths into one directory.
     *
     * A destination *directory*, not a target path per source: "put these
     * twelve in here" is the operation a selection expresses, and a per-source
     * target would need the caller to name twelve of them.
     *
     * @param  list<string>  $paths
     * @return array{succeeded: list<string>, failed: list<array{path: string, reason: string}>}
     */
    public function transferMany(Application $application, array $paths, string $targetDirectory, bool $move): array
    {
        $this->assertRootExists($application);

        $destination = $this->resolve($application, $targetDirectory);
        $this->assertType($application, $destination, 'd');

        $found = $this->statMany($application, $paths);
        $failed = $this->missing($paths, $found);

        // A destination that is already occupied is refused rather than
        // overwritten -- the same rule `copy()` and `rename()` already apply
        // to a single path, kept here so a bulk move cannot destroy something
        // a single move would have protected.
        $landing = [];

        foreach (array_keys($found) as $relative) {
            $landing[$relative] = ltrim($targetDirectory.'/'.basename($relative), '/');
        }

        $occupied = $this->statMany($application, array_values($landing));
        $sources = [];

        foreach ($landing as $relative => $target) {
            if (array_key_exists($target, $occupied)) {
                $failed[] = ['path' => $relative, 'reason' => 'exists'];

                continue;
            }

            $sources[$relative] = $this->resolve($application, $relative);
        }

        if ($sources !== []) {
            $this->tolerant(
                $application,
                array_merge($move ? ['mv'] : ['cp', '-r'], array_values($sources), [$destination]),
                $move ? 'move_many' : 'copy_many',
            );
        }

        $arrived = $this->statMany($application, array_map(fn (string $r): string => $landing[$r], array_keys($sources)));
        $succeeded = [];

        foreach (array_keys($sources) as $relative) {
            if (array_key_exists($landing[$relative], $arrived)) {
                $succeeded[] = $relative;

                continue;
            }

            $failed[] = ['path' => $relative, 'reason' => 'failed'];
        }

        return ['succeeded' => $succeeded, 'failed' => $failed];
    }

    /**
     * @param  list<string>  $paths
     * @return array{succeeded: list<string>, failed: list<array{path: string, reason: string}>}
     */
    public function chmodMany(Application $application, array $paths, string $mode): array
    {
        $this->assertRootExists($application);

        $found = $this->statMany($application, $paths);
        $failed = $this->missing($paths, $found);

        $targets = array_map(
            fn (string $relative): string => $this->resolve($application, $relative),
            array_keys($found),
        );

        if ($targets !== []) {
            $this->tolerant($application, array_merge(['chmod', $mode], $targets), 'chmod_many');
        }

        // Verified against the mode actually on disk, which is why statMany
        // reads `%m` at all.
        $after = $this->statMany($application, array_keys($found));
        $succeeded = [];

        foreach (array_keys($found) as $relative) {
            if (ltrim($after[$relative]['mode'] ?? '', '0') === ltrim($mode, '0')) {
                $succeeded[] = $relative;

                continue;
            }

            $failed[] = ['path' => $relative, 'reason' => 'failed'];
        }

        return ['succeeded' => $succeeded, 'failed' => $failed];
    }

    /**
     * Zips many paths into one archive.
     *
     * Every source must sit in the same directory. `zip` is run from that
     * directory with bare names so the archive contains `style.css` rather
     * than `home/siteowner/shop/public_html/style.css` — the same reason
     * `compress()` already passes a `cwd` — and there is no single directory
     * to run from once the sources are spread across the tree.
     *
     * @param  list<string>  $paths
     */
    public function compressMany(Application $application, array $paths, string $targetPath): void
    {
        $this->assertRootExists($application);

        abort_unless(str_ends_with(strtolower($targetPath), '.zip'), 422, __('errors/application.target_not_zip'));

        $parents = array_unique(array_map(
            fn (string $path): string => trim(dirname('/'.$path), '/'),
            $paths,
        ));

        abort_if(count($parents) > 1, 422, __('errors/application.sources_not_in_one_directory'));

        $found = $this->statMany($application, $paths);

        abort_if(count($found) !== count(array_unique($paths)), 404);

        $target = $this->resolve($application, $targetPath);
        abort_if($this->stat($application, $target) !== null, 422, __('errors/application.path_exists'));
        $this->assertType($application, dirname($target), 'd');

        $this->run(
            $application,
            array_merge(['zip', '-r', $target], array_map('basename', $paths)),
            'compress_many',
            cwd: $this->resolve($application, reset($parents) ?: ''),
        );
    }

    /**
     * The paths that were asked for but are not on disk.
     *
     * @param  list<string>  $paths
     * @param  array<string, array{type: string, mode: string}>  $found
     * @return list<array{path: string, reason: string}>
     */
    private function missing(array $paths, array $found): array
    {
        $missing = [];

        foreach (array_unique($paths) as $path) {
            if (! array_key_exists($path, $found)) {
                $missing[] = ['path' => $path, 'reason' => 'not_found'];
            }
        }

        return $missing;
    }

    /**
     * Runs a command whose failure is reported per path rather than thrown.
     *
     * `run()` turns a non-zero exit into a FileOperationException, which is
     * right when one path was asked for and wrong here: `rm` on twelve paths
     * exits non-zero if one of them fails, and throwing would report eleven
     * successful deletions as a server error.
     *
     * @param  array<int, string>  $command
     */
    private function tolerant(Application $application, array $command, string $op): ServerOpsResult
    {
        return $this->serverOps->run(
            $this->asUser($application, $command),
            ['feature' => 'application', 'op' => "file_{$op}", 'application' => $application->id],
            timeout: 300,
        );
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
