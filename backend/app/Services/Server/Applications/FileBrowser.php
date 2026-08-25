<?php

namespace App\Services\Server\Applications;

use App\Exceptions\Server\Application\FileOperationException;
use App\Jobs\MeasureApplicationSize;
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
            'find', $target, '-mindepth', '1', '-maxdepth', '1', '-printf', self::PRINTF_FORMAT,
        ], 'list');

        $entries = [];

        foreach (explode("\n", trim($result->output())) as $line) {
            if ($line === '') {
                continue;
            }

            [$name, $type, $size, $mtime, $mode, $owner, $group, $targetType, $linkTarget] = self::splitEntry($line);

            $entries[] = $this->buildEntry($name, $type, $size, $mtime, $mode, $owner, $group, $targetType, $linkTarget);
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
            'find', $target, '-mindepth', '1', '-iname', $pattern, '-printf', self::searchPrintfFormat(),
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
            [$relativeToScope, $type, $size, $mtime, $mode, $owner, $group, $targetType, $linkTarget] = self::splitEntry($line);
            $relativePath = $scopePath === '' ? $relativeToScope : "{$scopePath}/{$relativeToScope}";

            $entries[] = ['path' => $relativePath] + $this->buildEntry(
                basename($relativePath), $type, $size, $mtime, $mode, $owner, $group, $targetType, $linkTarget,
            );
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
    public function folderSize(Application $application, string $path, bool $refresh = false): array
    {
        $this->assertRootExists($application);
        $target = $this->resolve($application, $path);
        $this->assertType($application, $target, 'd');

        // Trailing slashes on either side, as everywhere else in this class
        // that compares against the root.
        $isRoot = rtrim($target, '/') === rtrim($this->provisioner->documentRoot($application), '/');

        // Only the site's own root is cached, and only that is served from the
        // cache. `directory_size_bytes` is the *application's* size: asking for
        // one subfolder used to write that subfolder's total into it, so a
        // glance at `wp-content/uploads` permanently redefined how big the site
        // was — smaller, and never corrected.
        if ($isRoot && ! $refresh && $application->directory_size_bytes !== null) {
            $bytes = (int) $application->directory_size_bytes;

            return [
                'size' => $bytes,
                'size_human' => Bytes::human($bytes),
                'measured_at' => $application->directory_size_updated_at?->format('d-m-Y H:i:s'),
            ];
        }

        $bytes = $this->measure($application, $target);

        if ($isRoot) {
            // There is no expiry. Nothing walks the disk on a timer — the size
            // is what it was when somebody last asked, which is why the time it
            // was taken is stored and returned beside it. The comment here used
            // to promise a one-hour freshness window that no code implemented,
            // so a size read once was returned unchanged for as long as the row
            // lived.
            $application->updateQuietly([
                'directory_size_bytes' => $bytes,
                'directory_size_updated_at' => now(),
            ]);
        }

        return [
            'size' => $bytes,
            'size_human' => Bytes::human($bytes),
            'measured_at' => $isRoot ? now()->format('d-m-Y H:i:s') : null,
        ];
    }

    /**
     * Measure the whole application directory now, and remember when.
     *
     * @return array{size: int, size_human: string, measured_at: string}
     */
    public function applicationSize(Application $application, bool $refresh = false): array
    {
        return $this->folderSize($application, '/', $refresh);
    }

    /**
     * Note that this site's contents changed, so the stored size is stale.
     *
     * Called by the operations that add or remove bytes — not by a rename or a
     * chmod, which move and relabel what is already counted.
     *
     * Queued and debounced rather than measured here: `du` walks every inode,
     * and paying for that inside the request would make deleting one file as
     * slow as counting the whole site. The job is unique per application and
     * delayed, so a burst of fifty deletions walks the site once, shortly after
     * the person stops, instead of fifty times while they work.
     */
    private function sizeChanged(Application $application): void
    {
        MeasureApplicationSize::dispatch($application->id)
            ->delay(now()->addSeconds(MeasureApplicationSize::DEBOUNCE_SECONDS));
    }

    /**
     * `du -sb` walks every inode, so this costs file *count*, not bytes — a
     * site with node_modules is a hundred thousand of them. It is why no
     * listing calls it and why nothing runs it on a schedule.
     */
    private function measure(Application $application, string $target): int
    {
        // Not `run()`, which throws on a non-zero exit. `du` exits 1 when it
        // could not read *some* entries and still prints a correct total for
        // everything it could — and it walks as the site's own user, so any
        // file that user cannot read produces exactly that. A real site hit
        // this: an uptime-kuma checkout with a handful of unreadable files
        // under test/ made the whole site permanently unmeasurable, because a
        // usable total was thrown away over the files missing from it.
        //
        // A slightly low figure beats none. A total of zero is a different
        // matter — that is not a partial answer, it is no answer — so it still
        // fails, and the caller keeps whatever it had before.
        $result = $this->serverOps->run(
            $this->asUser($application, ['du', '-sb', $target]),
            ['feature' => 'application', 'op' => 'file_folder_size', 'application' => $application->id],
            timeout: 60,
        );

        [$bytes] = explode("\t", trim($result->output()), 2);
        $bytes = (int) $bytes;

        if ($result->failed() && $bytes <= 0) {
            throw new FileOperationException($result->reference, busy: $result->busy, staleLock: $result->staleLock);
        }

        return $bytes;
    }

    /**
    /**
     * Fields every listing asks `find` for, in order.
     *
     * `%l` is last on purpose: it is the only field whose value is an
     * arbitrary path the site's owner chose, so it is the only one that can
     * contain a tab. Last means a stray tab lands inside the target itself
     * rather than shifting every field after it.
     *
     * `%Y` is the type *after* following the link, which is what makes a
     * broken symlink identifiable — find reports `N` when the target does not
     * exist and `L` when the links loop. Identical to `%y` for everything
     * that is not a link.
     */
    private const PRINTF_FORMAT = "%f\t%y\t%s\t%T@\t%m\t%u\t%g\t%Y\t%l\n";

    private const ENTRY_FIELDS = 9;

    /** The same fields, but named relative to the search scope (`%P`). */
    private static function searchPrintfFormat(): string
    {
        return str_replace('%f', '%P', self::PRINTF_FORMAT);
    }

    /**
     * @return array<int, string> exactly ENTRY_FIELDS values, padded when find
     *                            emitted fewer (`%l` is empty for non-links,
     *                            and a trailing empty field is not preserved
     *                            by explode alone).
     */
    private static function splitEntry(string $line): array
    {
        return array_pad(explode("\t", $line, self::ENTRY_FIELDS), self::ENTRY_FIELDS, '');
    }

    /**
     * @return array{name: string, type: string, size: int, size_human: string, modified_at: string, modified_at_human: string, mode: ?string, owner: ?string, group: ?string, link_target: ?string, link_broken: ?bool}
     */
    private function buildEntry(
        string $name,
        string $type,
        string $size,
        string $mtime,
        string $mode,
        string $owner,
        string $group,
        string $targetType = '',
        string $linkTarget = '',
    ): array {
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
            // Where it points, verbatim — relative targets are left relative,
            // because that is what the link actually says and resolving it
            // here would invent a path the user never wrote.
            'link_target' => $isSymlink && $linkTarget !== '' ? $linkTarget : null,
            // `N` is a target that does not exist, `L` a loop, `?` an error
            // reading it. A link that resolves to nothing looks identical to
            // a working one in a listing, which is exactly when knowing
            // matters.
            'link_broken' => $isSymlink ? in_array($targetType, ['N', 'L', '?'], true) : null,
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

        $this->sizeChanged($application);
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
        return array_map(fn (array $entry): array => [
            'name' => $entry['name'],
            'created_at' => $entry['created_at'],
        ], $this->backupEntries($application, $target));
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

        // Located rather than assumed: a copy taken before the relocation is
        // still in the old directory, and restoring it has to keep working.
        $entry = collect($this->backupEntries($application, $target))->firstWhere('name', $name);
        abort_if($entry === null, 404);

        $source = "{$entry['dir']}/{$name}";

        $content = $this->run($application, ['cat', $source], 'read_backup')->output();

        $this->write($application, $path, $content);

        $this->sizeChanged($application);
    }

    /**
     * Where a file's saved copies live: under the application's own `.panel`
     * directory, which sits ABOVE the document root.
     *
     * This used to be `dirname($target).'/.panel/backups'` — a `.panel` folder
     * beside the edited file, inside the served tree. That was safe only for as
     * long as every web server blocked dot-directories at every depth, and
     * OpenLiteSpeed's rule is anchored at the web root, so a nested one may not
     * have been covered. The payload made it worth moving rather than arguing
     * about: back up `wp-config.php` once and that copy holds live database
     * credentials.
     *
     * Above the document root there is nothing to block. It also matches the
     * rule the rest of the panel already follows — nothing the panel writes
     * lives under the document root.
     *
     * The file's directory (relative to the web root) is kept in the path, so
     * `wp-config.php` and `wp-content/plugins/config.php` cannot collide.
     */
    private function backupsDirectory(Application $application, string $target): string
    {
        $root = rtrim($this->provisioner->documentRoot($application), '/');
        $relative = trim(str_starts_with(dirname($target), $root)
            ? substr(dirname($target), strlen($root))
            : '', '/');

        return rtrim($application->panelPath().'/file-backups/'.$relative, '/');
    }

    /**
     * The pre-relocation location. Still read, so nobody loses the history
     * they had, and still pruned, so those copies drain out of the served
     * tree over time rather than sitting there indefinitely.
     */
    private function legacyBackupsDirectory(string $target): string
    {
        return dirname($target).'/.panel/backups';
    }

    /**
     * Every saved copy of one file, newest first, across both locations.
     *
     * @return array<int, array{name: string, dir: string, created_at: string}>
     */
    private function backupEntries(Application $application, string $target): array
    {
        $entries = [];

        foreach ([$this->backupsDirectory($application, $target), $this->legacyBackupsDirectory($target)] as $directory) {
            $result = $this->serverOps->run(
                $this->asUser($application, [
                    'find', $directory, '-maxdepth', '1',
                    '-name', basename($target).'.bak-*', '-printf', '%f\n',
                ]),
                ['feature' => 'application', 'op' => 'file_backups', 'application' => $application->id],
                timeout: 15,
            );

            if ($result->failed()) {
                continue;
            }

            foreach (array_filter(array_map('trim', explode("\n", $result->output()))) as $name) {
                // Keyed by name so a copy present in both locations is listed
                // once — the new location wins, since it is read first.
                $entries[$name] ??= [
                    'name' => $name,
                    'dir' => $directory,
                    'created_at' => $this->timestampFromBackupName($name),
                ];
            }
        }

        $entries = array_values($entries);
        usort($entries, fn (array $a, array $b): int => strcmp($b['name'], $a['name']));

        return $entries;
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

        $directory = $this->backupsDirectory($application, $target);
        $this->run($application, ['mkdir', '-p', $directory], 'backup_dir');

        $name = basename($target).'.bak-'.now()->format('Ymd-His');

        $this->run($application, ['cp', '-p', $target, "{$directory}/{$name}"], 'backup');

        $this->pruneBackups($application, $target);
    }

    /**
     * Keeps the newest N across both locations. Deleting from whichever
     * directory a copy actually sits in also drains the pre-relocation ones
     * out of the served tree as a file is edited over time.
     */
    private function pruneBackups(Application $application, string $target): void
    {
        $surplus = array_slice($this->backupEntries($application, $target), self::KEEP_BACKUPS);

        foreach ($surplus as $backup) {
            $this->run($application, ['rm', '-f', "{$backup['dir']}/{$backup['name']}"], 'backup_prune');
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
     * Writes a new file at `path`, refusing to stand on one already there.
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

        // Refused rather than overwritten. An upload that lands on an existing
        // file destroys it with no undo and no trace — the panel keeps a copy
        // when *it* replaces a file (see the backup this class writes before an
        // edit), and an upload had none. Dropping a file into a folder is also
        // the one operation here a user can do by accident, with a drag they
        // did not mean onto a folder they did not check.
        //
        // Same shape as createDirectory below: refuse, and name what is in the
        // way. Deleting the file first is the deliberate act that makes the
        // replacement intentional.
        abort_if(
            $this->stat($application, $target) !== null,
            422,
            __('errors/application.upload_exists', ['name' => basename($target)]),
        );

        $this->run($application, ['tee', $target], 'upload', input: $contents);

        $this->sizeChanged($application);
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

        $this->sizeChanged($application);
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

        // Decided by the same function `extract()` reads, so the formats the
        // panel can write and the formats it can open cannot drift apart.
        $format = $this->archiveFormat($targetPath);
        abort_if($format === null, 422, __('errors/application.target_not_archive'));

        $target = $this->resolve($application, $targetPath);
        abort_if($this->stat($application, $target) !== null, 422, __('errors/application.path_exists'));
        $this->assertType($application, dirname($target), 'd');

        $this->run(
            $application,
            $this->compressCommand($format, $target, [basename($source)]),
            'compress',
            cwd: dirname($source),
        );

        $this->sizeChanged($application);
    }

    /**
     * Deletes a file or a directory (recursively). The one destructive
     * operation in this feature — no trash, no undo, same stated limitation
     * as `extract()`'s lack of rollback. The site root itself can never be
     * the target; that would be deleting the whole site through a "delete
     * one thing" button.
     */
    /**
     * Deletes a file or directory — to the trash by default, permanently when
     * asked.
     *
     * Trashing is a `mv`, not a copy. On the same filesystem that is a rename:
     * instant, and it costs no extra disk. A copy of a 40 GB directory would
     * take minutes and could fill the disk while "deleting" it, which is a
     * strange way to run out of space.
     *
     * Permanent stays available and is not a second-class path: someone
     * deleting 40 GB to free space and seeing nothing freed would rightly call
     * that a bug, so "delete" still means delete when they say so.
     */
    public function delete(Application $application, string $path, bool $permanent = false): void
    {
        abort_if($path === '', 422, __('errors/application.cannot_delete_root'));

        $target = $this->resolve($application, $path);
        $stat = $this->stat($application, $target);
        abort_if($stat === null, 404);

        if (! $permanent) {
            $this->moveToTrash($application, $path, $target);

            return;
        }

        $command = $stat['type'] === 'd' ? ['rm', '-rf', $target] : ['rm', '-f', $target];

        $this->run($application, $command, 'delete');

        $this->sizeChanged($application);
    }

    /**
     * Moves one path into the trash, keeping where it came from.
     *
     * The original relative path is preserved inside the timestamped folder so
     * a restore knows where to put it back, and so two files called
     * `config.php` from different directories cannot collide.
     */
    private function moveToTrash(Application $application, string $relative, string $target): void
    {
        $destination = $this->trashDirectory($application).'/'.now()->format('Ymd-His').'/'.ltrim($relative, '/');

        $this->run($application, ['mkdir', '-p', dirname($destination)], 'trash_dir');
        $this->run($application, ['mv', $target, $destination], 'trash');
    }

    /**
     * Above the document root, for the same reason the backups are: a deleted
     * `wp-config.php` still holds live database credentials, and the trash
     * would otherwise be a directory of other people's secrets inside the
     * served tree.
     */
    private function trashDirectory(Application $application): string
    {
        return $application->panelPath().'/trash';
    }

    /**
     * What is in the trash: one entry per deleted path, newest first.
     *
     * Reported with the batch it was deleted in, so a selection deleted
     * together can be restored together — and with its original location,
     * because "config.php" alone does not tell anyone which one it was.
     *
     * @return array<int, array{batch: string, path: string, deleted_at: string}>
     */
    public function trash(Application $application): array
    {
        $root = $this->trashDirectory($application);

        $result = $this->serverOps->run(
            $this->asUser($application, ['find', $root, '-mindepth', '2', '-printf', '%P\n']),
            ['feature' => 'application', 'op' => 'trash_list', 'application' => $application->id],
            timeout: 30,
        );

        if ($result->failed()) {
            return [];
        }

        $entries = [];

        foreach (array_filter(array_map('trim', explode("\n", $result->output()))) as $line) {
            // `%P` gives `<batch>/<original/relative/path>`.
            [$batch, $path] = array_pad(explode('/', $line, 2), 2, '');

            if ($path === '' || ! preg_match('/^\d{8}-\d{6}$/', $batch)) {
                continue;
            }

            // Only the top of each deleted tree is listed. A deleted directory
            // is one thing the user deleted, not four hundred, and listing its
            // contents would bury the entry they are looking for.
            if (collect($entries)->contains(fn (array $e): bool => $e['batch'] === $batch && str_starts_with($path.'/', $e['path'].'/'))) {
                continue;
            }

            $entries[] = [
                'batch' => $batch,
                'path' => $path,
                'deleted_at' => $this->timestampFromBackupName($batch),
            ];
        }

        usort($entries, fn (array $a, array $b): int => strcmp($b['batch'], $a['batch']));

        return $entries;
    }

    /**
     * The recoverable entries together with their actual disk footprint.
     *
     * One `du` invocation measures every top-level entry. That makes a
     * directory's size useful (unlike its inode size from `find`) without
     * turning one trash listing into one process per deleted file.
     *
     * @return array{trash: array<int, array{batch: string, path: string, deleted_at: string, size: ?int, size_human: ?string}>, total_size: ?int, total_size_human: ?string}
     */
    public function trashDetails(Application $application): array
    {
        $entries = $this->trash($application);
        $sizes = $this->trashSizes($application, $entries);
        $complete = true;
        $total = 0;

        foreach ($entries as &$entry) {
            $key = $entry['batch'].'/'.$entry['path'];
            $size = $sizes[$key] ?? null;
            $entry['size'] = $size;
            $entry['size_human'] = $size === null ? null : Bytes::human($size);

            if ($size === null) {
                $complete = false;
            } else {
                $total += $size;
            }
        }
        unset($entry);

        return [
            'trash' => $entries,
            'total_size' => $complete ? $total : null,
            'total_size_human' => $complete ? Bytes::human($total) : null,
        ];
    }

    /**
     * @param  array<int, array{batch: string, path: string, deleted_at: string}>  $entries
     * @return array<string, int> keyed by `<batch>/<path>`
     */
    private function trashSizes(Application $application, array $entries): array
    {
        if ($entries === []) {
            return [];
        }

        $sources = [];
        $bySource = [];

        foreach ($entries as $entry) {
            $key = $entry['batch'].'/'.$entry['path'];
            $source = $this->trashDirectory($application).'/'.$key;
            $sources[] = $source;
            $bySource[$source] = $key;
        }

        // `du` may fail for one unreadable entry while still printing sizes
        // for the rest. Its output, not its aggregate exit code, is therefore
        // the per-entry source of truth.
        $result = $this->serverOps->run(
            $this->asUser($application, array_merge(['du', '-sb'], $sources)),
            ['feature' => 'application', 'op' => 'trash_sizes', 'application' => $application->id],
            timeout: 60,
        );

        $sizes = [];

        foreach (array_filter(explode("\n", $result->output())) as $line) {
            [$size, $source] = array_pad(explode("\t", $line, 2), 2, '');

            if (ctype_digit($size) && array_key_exists($source, $bySource)) {
                $sizes[$bySource[$source]] = (int) $size;
            }
        }

        return $sizes;
    }

    /**
     * Puts one trashed path back where it came from.
     *
     * Refuses rather than overwrites if something is there again — the same
     * rule rename() and copy() keep, and the one case where silently winning
     * would destroy the thing the user kept.
     */
    public function restoreFromTrash(Application $application, string $batch, string $path): void
    {
        abort_unless(preg_match('/^\d{8}-\d{6}$/', $batch) === 1, 422, __('errors/application.unknown_backup'));

        $source = $this->trashDirectory($application).'/'.$batch.'/'.ltrim($path, '/');
        abort_if($this->stat($application, $source) === null, 404);

        $target = $this->resolve($application, $path);
        abort_if($this->stat($application, $target) !== null, 422, __('errors/application.path_exists'));

        $this->run($application, ['mkdir', '-p', dirname($target)], 'trash_restore_dir');
        $this->run($application, ['mv', $source, $target], 'trash_restore');

        $this->sizeChanged($application);
    }

    /**
     * Restores every top-level deletion in a batch independently. One path
     * being occupied must not make the remaining recoverable files stay in
     * trash, and the response must identify precisely what happened.
     *
     * @return array{succeeded: list<string>, failed: list<array{path: string, reason: string}>}
     */
    public function restoreBatchFromTrash(Application $application, string $batch): array
    {
        abort_unless(preg_match('/^\d{8}-\d{6}$/', $batch) === 1, 422, __('errors/application.unknown_backup'));

        $entries = array_values(array_filter(
            $this->trash($application),
            fn (array $entry): bool => $entry['batch'] === $batch,
        ));
        abort_if($entries === [], 404);

        $succeeded = [];
        $failed = [];

        foreach ($entries as $entry) {
            $path = $entry['path'];
            $source = $this->trashDirectory($application).'/'.$batch.'/'.ltrim($path, '/');
            $target = $this->resolve($application, $path);

            if ($this->stat($application, $source) === null) {
                $failed[] = ['path' => $path, 'reason' => 'not_found'];

                continue;
            }

            if ($this->stat($application, $target) !== null) {
                $failed[] = ['path' => $path, 'reason' => 'exists'];

                continue;
            }

            $this->tolerant($application, ['mkdir', '-p', dirname($target)], 'trash_restore_dir');
            $this->tolerant($application, ['mv', $source, $target], 'trash_restore');

            if ($this->stat($application, $source) === null && $this->stat($application, $target) !== null) {
                $succeeded[] = $path;
            } else {
                $failed[] = ['path' => $path, 'reason' => 'failed'];
            }
        }

        $this->sizeChanged($application);

        return ['succeeded' => $succeeded, 'failed' => $failed];
    }

    /**
     * Empties the trash — the whole thing, or one batch.
     *
     * This is the only unrecoverable operation the file manager offers, and it
     * is meant to be: it exists so the disk space actually comes back.
     */
    public function emptyTrash(Application $application, ?string $batch = null): void
    {
        if ($batch !== null) {
            abort_unless(preg_match('/^\d{8}-\d{6}$/', $batch) === 1, 422, __('errors/application.unknown_backup'));

            $this->run($application, ['rm', '-rf', $this->trashDirectory($application).'/'.$batch], 'trash_empty');

            return;
        }

        $this->run($application, ['rm', '-rf', $this->trashDirectory($application)], 'trash_empty');

        $this->sizeChanged($application);
    }

    /**
     * Drops batches older than the retention window.
     *
     * Without this the trash is a slow disk-space leak: every delete keeps a
     * full copy forever, on a server whose whole job is running out of disk
     * quietly.
     */
    public function pruneTrash(Application $application): void
    {
        $days = (int) config('server.applications.trash_retention_days', 7);

        $this->serverOps->run(
            $this->asUser($application, [
                'find', $this->trashDirectory($application), '-mindepth', '1', '-maxdepth', '1',
                '-type', 'd', '-mtime', '+'.$days, '-exec', 'rm', '-rf', '{}', '+',
            ]),
            ['feature' => 'application', 'op' => 'trash_prune', 'application' => $application->id],
            timeout: 60,
        );

        $this->sizeChanged($application);
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

        $this->sizeChanged($application);
    }

    /**
     * The command that writes one archive, by format.
     *
     * Both run from the sources' own directory (`cwd`) and are given bare
     * names, so the archive contains `plugin/…` rather than
     * `home/siteowner/shop/public_html/wp-content/plugins/plugin/…` — an
     * archive carrying the server's directory layout is both useless to
     * extract elsewhere and a small disclosure of where things live.
     *
     * tar.gz is worth having as more than symmetry with extract(): ZIP does
     * not preserve Unix permissions, so a site zipped and unzipped comes back
     * with whatever modes the extractor chose — a 0600 wp-config.php does not
     * stay 0600. tar keeps mode, ownership and symlinks, which is what "keep a
     * copy of this folder before I touch it" actually needs.
     *
     * @param  array<int, string>  $names
     * @return array<int, string>
     */
    private function compressCommand(string $format, string $target, array $names): array
    {
        return $format === 'zip'
            ? array_merge(['zip', '-r', $target], $names)
            // `--` so a name beginning with a dash is a path, not an option.
            : array_merge(['tar', '-czf', $target, '--'], $names);
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

        $result = $this->serverOps->probe(
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
    public function deleteMany(Application $application, array $paths, bool $permanent = false): array
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

        // One timestamp for the whole selection, so "the twelve things I
        // deleted together" restore together rather than scattering across
        // twelve folders a second apart.
        if (! $permanent) {
            $stamp = now()->format('Ymd-His');

            foreach (array_keys($found) as $relative) {
                $destination = $this->trashDirectory($application).'/'.$stamp.'/'.ltrim($relative, '/');

                $this->tolerant($application, ['mkdir', '-p', dirname($destination)], 'trash_dir');
                $this->tolerant($application, ['mv', $this->resolve($application, $relative), $destination], 'trash');
            }

            $directories = [];
            $files = [];
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

        $this->sizeChanged($application);

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

        $this->sizeChanged($application);

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

        $format = $this->archiveFormat($targetPath);
        abort_if($format === null, 422, __('errors/application.target_not_archive'));

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
            $this->compressCommand($format, $target, array_map('basename', $paths)),
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
