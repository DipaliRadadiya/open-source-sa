<?php

namespace App\Services\Server\Applications;

use App\Enums\FileArchiveStatus;
use App\Exceptions\Server\Application\FileOperationException;
use App\Exceptions\Server\Application\FileRefusedException;
use App\Jobs\MeasureApplicationSize;
use App\Jobs\RunFileArchive;
use App\Models\Application;
use App\Models\FileArchiveJob;
use App\Rules\SafeRelativePath;
use App\Services\Server\Compression\ArchiveCompressor;
use App\Services\Server\Disk\DiskSpaceCheck;
use App\Services\Server\Docker\VolumeSizes;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use App\Support\Bytes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * List, view, edit and download a site's own files.
 *
 * Every command runs as the site's own Linux user (`runuser -u`), never as root. Path validation
 * (`SafeRelativePath`) cannot see a symlink pointing outside the site; running as the site user means
 * such a link gains nothing, because that user cannot read outside what it owns.
 */
class FileBrowser
{
    /** Larger files belong to SFTP; buffering a big file through PHP risks memory exhaustion. */
    public const MAX_BYTES = 5 * 1024 * 1024;

    /** Larger than MAX_BYTES (plugin zips) but still bounded: uploads are buffered through PHP. */
    public const UPLOAD_MAX_BYTES = 50 * 1024 * 1024;

    /** Max total *uncompressed* size an archive may expand to (zip-bomb guard, checked before extraction). */
    public const MAX_UNCOMPRESSED_BYTES = 250 * 1024 * 1024;

    /** Second bomb guard: many tiny files pass a byte check but not an entry count. */
    public const MAX_ARCHIVE_ENTRIES = 10000;

    /** Backups kept per file; mirrors `ApplicationEnvironment::KEEP_BACKUPS`. */
    public const KEEP_BACKUPS = 5;

    /** Cap on recursive search results; `truncated` tells the caller there are more. */
    public const MAX_SEARCH_RESULTS = 200;

    /** Files the type breakdown counts before it stops and reports `truncated`. */
    public const MAX_BREAKDOWN_FILES = 250_000;

    /** A directory that cannot be walked in this time reports itself unmeasurable (runs on page load). */
    private const BREAKDOWN_TIMEOUT_SECONDS = 20;

    /**
     * Max paths per bulk request: an argument vector has a kernel limit (ARG_MAX), and crossing it
     * fails mid-operation with some paths already handled.
     */
    public const MAX_BULK_PATHS = 250;

    private const BINARY_SNIFF_BYTES = 8192;

    /** The default for `server.applications.preview_max_bytes` — see there. */
    public const PREVIEW_MAX_BYTES = 10 * 1024 * 1024;

    /** Bytes read to identify a file type; wide enough for SVG, whose root can follow a doctype or comment. */
    private const PREVIEW_SNIFF_BYTES = 512;

    public function __construct(
        private ServerOps $serverOps,
        private PanelDirectory $panelDirectory,
        private VolumeSizes $volumeSizes,
        private ArchiveCompressor $compressor = new ArchiveCompressor,
    ) {}

    /**
     * One directory, one level deep.
     *
     * Dotfiles are filtered here, not in the browser, so `hidden_count` comes from the same read as the list.
     *
     * @return array{entries: array<int, array<string, mixed>>, hidden_count: int}
     */
    public function list(Application $application, string $path, bool $includeHidden = true): array
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

        // Hidden = a leading dot (not a name list: `.git`, `.well-known`, `.user.ini`, ...).
        $hidden = array_filter($entries, fn (array $entry): bool => str_starts_with($entry['name'], '.'));

        if (! $includeHidden) {
            $entries = array_values(array_diff_key($entries, $hidden));
        }

        // Directories first, then alphabetical.
        usort($entries, fn (array $a, array $b): int => match (true) {
            $a['type'] === 'dir' && $b['type'] !== 'dir' => -1,
            $a['type'] !== 'dir' && $b['type'] === 'dir' => 1,
            default => strcasecmp($a['name'], $b['name']),
        });

        // Always the real count, so a filtered listing never looks like lost files.
        return ['entries' => $entries, 'hidden_count' => count($hidden)];
    }

    /**
     * How this directory's bytes divide by file type.
     *
     * Recursive and expensive (walks every file), so it is scoped to the directory being viewed.
     * `find` has a short timeout and parsing stops at MAX_BREAKDOWN_FILES; past either, `truncated`
     * or `available: false` says the answer is partial or unmeasurable.
     *
     * @return array{available: bool, truncated: bool, total_bytes: int, file_count: int, categories: array<int, array<string, mixed>>}
     */
    public function breakdown(Application $application, string $path): array
    {
        $this->assertRootExists($application);
        $target = $this->resolve($application, $path);
        $this->assertType($application, $target, 'd');

        // Not run(), which throws: a directory too large to walk in time is a normal answer here.
        $result = $this->serverOps->run(
            $this->asUser($application, ['find', $target, '-type', 'f', '-printf', "%s\t%f\n"]),
            ['feature' => 'application', 'op' => 'file_breakdown', 'application' => $application->id],
            timeout: self::BREAKDOWN_TIMEOUT_SECONDS,
        );

        if (! $result->ok) {
            return [
                'available' => false, 'truncated' => false,
                'total_bytes' => 0, 'file_count' => 0, 'categories' => [],
            ];
        }

        $files = [];
        $truncated = false;

        foreach (explode("\n", $result->output()) as $line) {
            if ($line === '') {
                continue;
            }

            if (count($files) >= self::MAX_BREAKDOWN_FILES) {
                $truncated = true;
                break;
            }

            // Split from the left: a filename may contain tabs, a size never does.
            [$size, $name] = array_pad(explode("\t", $line, 2), 2, '');
            $files[] = ['name' => $name, 'size' => (int) $size];
        }

        $categories = app(FileTypeBreakdown::class)->summarise($files);

        return [
            'available' => true,
            'truncated' => $truncated,
            'total_bytes' => $total = array_sum(array_column($categories, 'bytes')),
            'total_bytes_human' => Bytes::human($total),
            'file_count' => count($files),
            'categories' => array_map(fn (array $c): array => $c + [
                'bytes_human' => Bytes::human($c['bytes']),
            ], $categories),
        ];
    }

    /**
     * Recursive, case-insensitive filename search of a subtree (default: the whole site).
     *
     * Results are flat; each `path` is relative to the site root, not `$scopePath`. Capped at
     * MAX_SEARCH_RESULTS; `truncated` says there were more.
     *
     * @return array{entries: array<int, array<string, mixed>>, truncated: bool}
     */
    public function search(Application $application, string $scopePath, string $query): array
    {
        $this->assertRootExists($application);
        $target = $this->resolve($application, $scopePath);
        $this->assertType($application, $target, 'd');

        // `-iname` takes find's glob syntax: escape `*`, `?` and `[` so the query matches literally.
        $pattern = '*'.$this->escapeFindPattern($query).'*';

        $result = $this->run($application, [
            'find', $target, '-mindepth', '1', '-iname', $pattern, '-printf', self::searchPrintfFormat(),
        ], 'search');

        $entries = [];

        foreach (explode("\n", trim($result->output())) as $line) {
            if ($line === '') {
                continue;
            }

            // `%P` is relative to $target; rejoin with $scopePath so `path` works against every other endpoint.
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
     * Total size of a directory's contents. Its own on-demand call, not part of `list()`: a recursive
     * walk would make every listing as slow as its heaviest subfolder.
     *
     * @return array{size: int, size_human: string}
     */
    public function folderSize(Application $application, string $path, bool $refresh = false): array
    {
        $this->assertRootExists($application);
        $target = $this->resolve($application, $path);
        $this->assertType($application, $target, 'd');

        // Answers about a directory, never the site: the site's size includes its Docker volumes and is
        // owned by `applicationSize()`. Writing `directory_size_bytes` here would drop them again.
        $bytes = $this->measure($application, $target);

        return [
            'size' => $bytes,
            'size_human' => Bytes::human($bytes),
            'measured_at' => now()->format('d-m-Y H:i:s'),
        ];
    }

    /**
     * The size of every folder directly inside `$path`, from one `du`.
     *
     * Runs at the lowest CPU/disk priority, stays on the site's filesystem (`-x`) and is cached for five
     * minutes. Panel changes forget it ({@see forgetFolderSizes()}); outside changes show after five
     * minutes or on `refresh`. Past the 60-second ceiling it is a timed-out FileOperationException.
     * `complete` is false when `du` printed folders but not the total; such an answer is not cached.
     *
     * @return array{sizes: array<string, array{size: int, size_human: string}>, total: ?array{size: int, size_human: string}, complete: bool, measured_at: string}
     */
    public function folderSizes(Application $application, string $path, bool $refresh = false): array
    {
        $this->assertRootExists($application);
        $target = rtrim($this->resolve($application, $path), '/');
        $this->assertType($application, $target, 'd');

        $key = 'file-sizes:'.$application->id.':'.$this->sizesGeneration($application).':'.sha1($target);

        if (! $refresh && ($cached = Cache::get($key)) !== null) {
            return $cached;
        }

        $result = $this->serverOps->run(
            $this->asUser($application, ['nice', '-n', '19', 'ionice', '-c', '3', 'du', '-bx0', '--max-depth=1', $target]),
            ['feature' => 'application', 'op' => 'file_folder_sizes', 'application' => $application->id],
            timeout: 60,
        );

        $sizes = [];
        $total = null;

        // `-0`: NUL-separated, so a folder name holding a newline is still one entry.
        foreach (array_filter(explode("\0", $result->output())) as $line) {
            [$bytes, $entry] = array_pad(explode("\t", $line, 2), 2, '');
            $entry = rtrim($entry, '/');
            $size = ['size' => (int) $bytes, 'size_human' => Bytes::human((int) $bytes)];

            if ($entry === $target) {
                $total = $size;
            } elseif (dirname($entry) === $target) {
                $sizes[basename($entry)] = $size;
            }
        }

        // `du` exits 1 over a few unreadable files and still prints the rest (see measure()).
        if ($result->failed() && $sizes === [] && $total === null) {
            throw new FileOperationException($result->reference, busy: $result->busy, staleLock: $result->staleLock, denied: $result->denied, timedOut: $result->timedOut);
        }

        $answer = [
            'sizes' => $sizes,
            'total' => $total,
            'complete' => ! $result->timedOut && $total !== null,
            'measured_at' => now()->format('d-m-Y H:i:s'),
        ];

        if ($answer['complete']) {
            Cache::put($key, $answer, now()->addSeconds(self::FOLDER_SIZES_TTL));
        }

        return $answer;
    }

    /** How long `folderSizes()` answers are kept. */
    public const FOLDER_SIZES_TTL = 300;

    /** Drop every remembered folder size for this site at once, via one counter in the cache key. */
    public function forgetFolderSizes(Application $application): void
    {
        Cache::forever('file-sizes-gen:'.$application->id, $this->sizesGeneration($application) + 1);
    }

    private function sizesGeneration(Application $application): int
    {
        return (int) Cache::get('file-sizes-gen:'.$application->id, 0);
    }

    /**
     * The whole site: its document root plus the Docker volumes it keeps data in.
     *
     * A container site's document root holds only a compose file; its data lives in named volumes the
     * site user cannot read, so `folderSize('/')` would badly understate it. The two halves are stored
     * separately as well as summed, so the total can be explained.
     *
     * `volume_size_bytes` is null when the site has no volumes to measure (PHP, Node, static), which
     * is not the same as 0 (a container site with empty volumes).
     */
    public function applicationSize(Application $application, bool $refresh = false): array
    {
        if (! $refresh && $application->directory_size_bytes !== null) {
            $bytes = (int) $application->directory_size_bytes;

            return [
                'size' => $bytes,
                'size_human' => Bytes::human($bytes),
                'volume_size' => $application->volume_size_bytes === null
                    ? null
                    : (int) $application->volume_size_bytes,
                'measured_at' => $application->directory_size_updated_at?->format('d-m-Y H:i:s'),
            ];
        }

        $this->assertRootExists($application);

        $directory = $this->measure($application, $this->root($application));

        // Only for sites with volumes: asking Docker about anything else is a pointless (or failing) shell-out.
        $volumes = $application->serving_profile === 'docker'
            ? $this->volumeSizes->forApplication($application)
            : null;

        $total = $directory + (int) $volumes;

        $application->updateQuietly([
            'directory_size_bytes' => $total,
            'volume_size_bytes' => $volumes,
            'directory_size_updated_at' => now(),
        ]);

        return [
            'size' => $total,
            'size_human' => Bytes::human($total),
            'volume_size' => $volumes,
            'measured_at' => now()->format('d-m-Y H:i:s'),
        ];
    }

    /**
     * Note that this site's contents changed, so the stored size is stale.
     *
     * Called by operations that add or remove bytes, not by rename/chmod. The measurement is queued
     * and debounced (unique per application, delayed): `du` walks every inode, and a burst of
     * deletions should walk the site once.
     */
    private function sizeChanged(Application $application): void
    {
        $this->forgetFolderSizes($application);

        MeasureApplicationSize::dispatch($application->id)
            ->delay(now()->addSeconds(MeasureApplicationSize::DEBOUNCE_SECONDS));
    }

    /**
     * `du -sb` walks every inode, so it costs file *count*, not bytes. Hence no listing calls it and
     * nothing runs it on a schedule.
     */
    private function measure(Application $application, string $target): int
    {
        // Not run(), which throws on a non-zero exit: du exits 1 when it could not read *some* entries
        // (files the site user cannot read) and still prints a correct total for the rest. A slightly
        // low figure beats none; a total of zero is no answer, so that still fails.
        $result = $this->serverOps->run(
            $this->asUser($application, ['du', '-sb', $target]),
            ['feature' => 'application', 'op' => 'file_folder_size', 'application' => $application->id],
            timeout: 60,
        );

        [$bytes] = explode("\t", trim($result->output()), 2);
        $bytes = (int) $bytes;

        if ($result->failed() && $bytes <= 0) {
            throw new FileOperationException($result->reference, busy: $result->busy, staleLock: $result->staleLock, denied: $result->denied, timedOut: $result->timedOut);
        }

        return $bytes;
    }

    /**
     * Fields every listing asks `find` for, in order.
     *
     * `%l` is last on purpose: it is the only field holding an arbitrary owner-chosen path, so a stray
     * tab lands inside it instead of shifting later fields. `%Y` is the type after following the link,
     * which identifies a broken symlink (`N` missing target, `L` loop); same as `%y` for non-links.
     */
    private const PRINTF_FORMAT = "%f\t%y\t%s\t%T@\t%m\t%u\t%g\t%Y\t%l\n";

    private const ENTRY_FIELDS = 9;

    /** The same fields, but named relative to the search scope (`%P`). */
    private static function searchPrintfFormat(): string
    {
        return str_replace('%f', '%P', self::PRINTF_FORMAT);
    }

    /**
     * @return array<int, string> exactly ENTRY_FIELDS values, padded when find emitted fewer (a
     *                            trailing empty `%l` is not preserved by explode alone).
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

        // A symlink's own mode/owner (`lrwxrwxrwx`, always) is not a meaningful permission: omitted.
        $isSymlink = $entryType === 'symlink';

        return [
            'name' => $name,
            'type' => $entryType,
            // Null for a folder: find reports the directory entry's own block (4096); see `folder-size`.
            'size' => $entryType === 'dir' ? null : (int) $size,
            'size_human' => $entryType === 'dir' ? null : Bytes::human((int) $size),
            'modified_at' => $modifiedAt->format('d-m-Y H:i:s'),
            'modified_at_human' => $modifiedAt->diffForHumans(),
            'mode' => $isSymlink ? null : $mode,
            'owner' => $isSymlink ? null : $owner,
            'group' => $isSymlink ? null : $group,
            // Verbatim: resolving a relative target would invent a path the user never wrote.
            'link_target' => $isSymlink && $linkTarget !== '' ? $linkTarget : null,
            // `N` = missing target, `L` = loop, `?` = unreadable; a broken link looks like a working one in a listing.
            'link_broken' => $isSymlink ? in_array($targetType, ['N', 'L', '?'], true) : null,
        ];
    }

    /** Escapes find's glob metacharacters so a query is matched literally. */
    private function escapeFindPattern(string $value): string
    {
        return str_replace(['\\', '*', '?', '['], ['\\\\', '\\*', '\\?', '\\['], $value);
    }

    /**
     * `version` is a hash of the content, sent back on save to catch two people editing one file.
     * A hash, not the mtime: two saves in one second share an mtime, and `touch` moves it without
     * changing a byte.
     *
     * @return array{content: string, size: int, binary: bool, version: string, backups: array<int, array{name: string, created_at: string}>}
     */
    public function read(Application $application, string $path): array
    {
        $this->assertRootExists($application);
        $target = $this->resolve($application, $path);
        $size = $this->assertType($application, $target, 'f');

        if ($size > self::MAX_BYTES) {
            throw FileRefusedException::because('path', 'too_large', 'errors/application.file_too_large');
        }

        $content = $this->run($application, ['cat', $target], 'read')->output();

        return [
            'content' => $content,
            'size' => $size,
            'binary' => str_contains(substr($content, 0, self::BINARY_SNIFF_BYTES), "\0"),
            'version' => sha1($content),
            'backups' => $this->backups($application, $target),
        ];
    }

    /**
     * A file's size and a generator over its bytes.
     *
     * Not capped at MAX_BYTES like `read()`: that limit exists because an editor needs the file in
     * memory, a download does not. Nothing is buffered; the caller streams each chunk. The size is
     * returned so the response can carry a real Content-Length.
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
     * An image's own bytes, for rendering in the browser.
     *
     * The one endpoint that returns a real content type (`download()` serves everything as
     * `application/octet-stream`), so it is kept narrow:
     *
     *  - The type comes from the file's first bytes, never its name: a `logo.png` holding HTML would
     *    otherwise run on the API's own origin.
     *  - SVG is refused (with its own reason): it is a script container and this response is inline
     *    and same-origin. Download still returns it as octet-stream.
     *  - Size is capped, checked from the stat before a byte is read.
     *
     * @return array{size: int, mime: string, chunks: \Generator<int, string>}
     */
    public function preview(Application $application, string $path): array
    {
        $this->assertRootExists($application);
        $target = $this->resolve($application, $path);
        $size = $this->assertType($application, $target, 'f');

        $max = (int) config('server.applications.preview_max_bytes', self::PREVIEW_MAX_BYTES);

        if ($size > $max) {
            throw FileRefusedException::because('path', 'too_large', 'errors/application.file_too_large_to_preview');
        }

        $head = $this->run($application, ['head', '-c', (string) self::PREVIEW_SNIFF_BYTES, $target], 'preview_sniff')->output();

        $mime = $this->imageType($head);

        if ($mime === null) {
            // Image formats are tried first: a real PNG whose pixels spell `<svg` is still a PNG. The SVG
            // check only explains a file that is not one of those formats.
            if ($this->looksLikeSvg($head)) {
                throw FileRefusedException::because('path', 'svg_not_previewable', 'errors/application.file_svg_not_previewable');
            }

            throw FileRefusedException::because('path', 'not_image', 'errors/application.file_not_previewable');
        }

        return [
            'size' => $size,
            'mime' => $mime,
            'chunks' => $this->serverOps->stream(
                $this->asUser($application, ['cat', $target]),
                ['feature' => 'application', 'op' => 'file_preview', 'application' => $application->id],
            ),
        ];
    }

    /**
     * The image format these bytes actually are, or null for anything else.
     *
     * Signatures rather than `finfo`: the panel's own process cannot read a site's files.
     */
    private function imageType(string $head): ?string
    {
        return match (true) {
            str_starts_with($head, "\x89PNG\r\n\x1a\n") => 'image/png',
            str_starts_with($head, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($head, 'GIF87a'), str_starts_with($head, 'GIF89a') => 'image/gif',
            // RIFF is also WAV and AVI; the WEBP tag at byte 8 identifies the format.
            str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP' => 'image/webp',
            str_starts_with($head, 'BM') => 'image/bmp',
            str_starts_with($head, "\x00\x00\x01\x00") => 'image/x-icon',
            // ISO-BMFF: box length, `ftyp`, then the brand; AVIF and HEIC differ only in that brand.
            substr($head, 4, 4) === 'ftyp' && in_array(substr($head, 8, 4), ['avif', 'avis'], true) => 'image/avif',
            default => null,
        };
    }

    /**
     * Whether these bytes are an SVG.
     *
     * Looks for the root element, not `<?xml` (any XML file, e.g. a sitemap, starts with that). The root
     * can follow an XML declaration, doctype and comment, which the sniff window is sized for.
     */
    private function looksLikeSvg(string $head): bool
    {
        return str_contains(strtolower($head), '<svg');
    }

    /**
     * Overwrites an existing file, keeping a copy of what was there (like `ApplicationEnvironment::write()`).
     *
     * The backup comes first and its failure is fatal: it is the safety story for "edit". Skipped only
     * when the user turned the copy off (`$keepCopy`).
     */
    public function write(Application $application, string $path, string $content, ?string $expectedVersion = null, bool $keepCopy = true): string
    {
        $target = $this->resolve($application, $path);
        // Editing, not creating: the path must already be a regular file.
        $this->assertType($application, $target, 'f');

        // Someone else saved since this editor opened the file; overwriting would silently drop their change.
        if ($expectedVersion !== null
            && ! hash_equals($expectedVersion, sha1($this->run($application, ['cat', $target], 'read')->output()))) {
            throw FileRefusedException::because('version', 'changed_on_disk', 'errors/application.file_changed_on_disk', status: 409);
        }

        if ($keepCopy) {
            $this->backup($application, $target);
        }

        $this->run($application, ['tee', $target], 'write', input: $content);

        $this->sizeChanged($application);

        return sha1($content);
    }

    /**
     * Previous saves for one file, newest first.
     *
     * Stored above the document root (see backupsDirectory()), not beside the file: a
     * `plugin.php.bak-...` next to `plugin.php` would be reachable over HTTP unless every vhost blocks it.
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
     * Puts a previous save back, taking a backup of the current content first so restoring the wrong one is undoable.
     *
     * @return array{content: string, version: string} what the file now holds
     */
    public function restoreBackup(Application $application, string $path, string $name, bool $keepCopy = true): array
    {
        $target = $this->resolve($application, $path);
        $this->assertType($application, $target, 'f');

        $prefix = basename($target).'.bak-';

        abort_unless(
            str_starts_with($name, $prefix) && preg_match('/^\d{8}-\d{6}$/', substr($name, strlen($prefix))) === 1,
            422,
            __('errors/application.unknown_backup'),
        );

        // Located, not assumed: a copy taken before the relocation is still in the old directory.
        $entry = collect($this->backupEntries($application, $target))->firstWhere('name', $name);
        abort_if($entry === null, 404);

        $source = "{$entry['dir']}/{$name}";

        $content = $this->run($application, ['cat', $source], 'read_backup')->output();

        $version = $this->write($application, $path, $content, keepCopy: $keepCopy);

        $this->sizeChanged($application);

        return ['content' => $content, 'version' => $version];
    }

    /**
     * Where a file's saved copies live: the application's own `.panel` directory, ABOVE the document root.
     *
     * Never beside the file inside the served tree: a backup of `wp-config.php` holds live database
     * credentials, and not every web server blocks nested dot-directories. The file's directory
     * (relative to the web root) is kept in the path so same-named files cannot collide.
     */
    private function backupsDirectory(Application $application, string $target): string
    {
        $root = $this->root($application);
        $relative = trim(str_starts_with(dirname($target), $root)
            ? substr(dirname($target), strlen($root))
            : '', '/');

        return rtrim($application->panelPath().'/file-backups/'.$relative, '/');
    }

    /** The pre-relocation location (inside the served tree): still read and pruned, so old copies drain out. */
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
                expectedExitCodes: [1], // no backups folder yet
            );

            if ($result->failed()) {
                continue;
            }

            foreach (array_filter(array_map('trim', explode("\n", $result->output()))) as $name) {
                // Keyed by name so a copy present in both locations is listed once (the new location wins).
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
            // Nothing to back up yet: write() is creating the file (not reachable via the HTTP endpoint
            // today, but stays correct if that changes).
            return;
        }

        // The top of the tree is root's to give away (see PanelDirectory); the per-file subdirectory
        // below it is the site user's to create.
        $this->panelDirectory->ensure($application, 'file-backups');

        $directory = $this->backupsDirectory($application, $target);
        $this->run($application, ['mkdir', '-p', $directory], 'backup_dir');

        $name = basename($target).'.bak-'.now()->format('Ymd-His');

        $this->run($application, ['cp', '-p', $target, "{$directory}/{$name}"], 'backup');

        $this->pruneBackups($application, $target);
    }

    /**
     * Keeps the newest N across both locations (also drains pre-relocation copies out of the served tree).
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
     * The client-supplied filename is never used to build the target: `$path` is the whole answer,
     * validated like everything else here. Joining a validated directory to an unvalidated name would
     * reopen the traversal surface `SafeRelativePath` closes.
     */
    public function upload(Application $application, string $path, string $contents): void
    {
        $target = $this->resolve($application, $path);
        $directory = dirname($target);

        // The directory must already exist (no implicit mkdir); upload may create the file, not folders.
        $this->assertDestination($application, $directory, 'path', dirname($path));

        // Refused, not overwritten: replacing a file here would destroy it with no backup. Same shape as
        // createDirectory(): refuse and name what is in the way; deleting it first makes a replace
        // deliberate.
        if ($this->stat($application, $target) !== null) {
            throw FileRefusedException::because('path', 'path_exists', 'errors/application.upload_exists', ['name' => basename($target)]);
        }

        $this->run($application, ['tee', $target], 'upload', input: $contents);

        $this->sizeChanged($application);
    }

    /**
     * Creates an empty directory, one level only: the containing directory must already exist.
     */
    public function createDirectory(Application $application, string $path): void
    {
        $target = $this->resolve($application, $path);
        $this->assertDestination($application, dirname($target), 'path', dirname($path));

        // Refused, not a silent OK: a folder that already exists means the wrong name or place.
        if ($this->stat($application, $target) !== null) {
            throw FileRefusedException::because('path', 'path_exists', 'errors/application.path_exists');
        }

        $this->run($application, ['mkdir', $target], 'mkdir');
        $this->forgetFolderSizes($application);
    }

    /**
     * Renames or moves a file/directory (`mv` does not distinguish them).
     *
     * Unlike `upload()`, the destination must not already exist: a mistyped rename silently
     * destroying an unrelated file is too easy.
     */
    public function rename(Application $application, string $path, string $targetPath): void
    {
        $source = $this->resolve($application, $path);
        $sourceStat = $this->stat($application, $source);
        abort_if($sourceStat === null || ! in_array($sourceStat['type'], ['f', 'd'], true), 404);

        $target = $this->resolve($application, $targetPath);
        $this->refuseIntoItself($sourceStat['type'], $source, $target, 'target');
        $this->assertDestination($application, dirname($target), 'target', dirname($targetPath));
        $this->refuseExisting($application, $target, 'target');

        $this->run($application, ['mv', $source, $target], 'rename');
        // Nothing added or removed, but a move changes which folder holds it.
        $this->forgetFolderSizes($application);
    }

    /**
     * Copies a file or directory (recursively) to a new path; the source is untouched.
     *
     * Same non-overwrite default as `rename()`.
     */
    public function copy(Application $application, string $path, string $targetPath): void
    {
        $source = $this->resolve($application, $path);
        $sourceStat = $this->stat($application, $source);
        abort_if($sourceStat === null || ! in_array($sourceStat['type'], ['f', 'd'], true), 404);

        $target = $this->resolve($application, $targetPath);
        $this->refuseIntoItself($sourceStat['type'], $source, $target, 'target');
        $this->assertDestination($application, dirname($target), 'target', dirname($targetPath));
        $this->refuseExisting($application, $target, 'target');

        $this->run($application, ['cp', '-r', $source, $target], 'copy');

        $this->sizeChanged($application);
    }

    /**
     * Packages a file or directory into a new archive elsewhere in the site (the reverse of `extract()`).
     *
     * No zip-slip surface here: the panel archives files from the site's own filesystem. `zip -r` runs
     * with the source's parent as `cwd` and its basename as the argument, so the archive holds
     * relative paths.
     */
    public function compress(Application $application, string $path, string $targetPath): FileArchiveJob
    {
        $source = $this->resolve($application, $path);
        $sourceStat = $this->stat($application, $source);
        abort_if($sourceStat === null || ! in_array($sourceStat['type'], ['f', 'd'], true), 404);

        // Same function `extract()` reads, so the formats the panel can write and open cannot drift apart.
        $format = $this->archiveFormat($targetPath);
        if ($format === null) {
            throw FileRefusedException::because('target', 'not_archive', 'errors/application.target_not_archive');
        }

        $target = $this->resolve($application, $targetPath);
        $this->refuseExisting($application, $target, 'target');
        $this->assertType($application, dirname($target), 'd');
        $this->requireRoomToCompress($application, [$source], $target);

        return $this->queue($application, 'compress', [$path], $targetPath);
    }

    /**
     * Deletes a file or directory: to the trash by default, permanently when asked.
     *
     * Trashing is a `mv`: a rename on the same filesystem, instant and with no extra disk (a copy of a
     * large directory could fill the disk while "deleting" it). The site root can never be the target.
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
     * The original relative path is kept inside the timestamped folder so a restore knows where to
     * put it back and same-named files cannot collide.
     */
    private function moveToTrash(Application $application, string $relative, string $target): void
    {
        $batch = now()->format('Ymd-His');
        $destination = $this->trashDirectory($application).'/'.$batch.'/'.ltrim($relative, '/');

        $this->panelDirectory->ensure($application, 'trash');
        $this->run($application, ['mkdir', '-p', dirname($destination)], 'trash_dir');
        $this->run($application, ['mv', $target, $destination], 'trash');
        $this->recordTrashed($application, $batch, [$relative]);
    }

    /**
     * Note what a batch actually holds, beside it as `<batch>.paths` (one line per path, appended, as
     * the site user).
     *
     * The trash cannot answer that itself: deleting `qa/note.txt` creates `<batch>/qa/` as scaffolding,
     * which reads back as a deleted folder `qa`, and "restore all" would try to put back a folder that
     * was never deleted. Kept beside the batch so it never collides with a deleted file's name.
     *
     * @param  array<int, string>  $paths
     */
    private function recordTrashed(Application $application, string $batch, array $paths): void
    {
        $lines = implode('', array_map(fn (string $path): string => ltrim($path, '/')."\n", $paths));

        // Not fatal: the item is already in the trash, and a batch with no record is still listed.
        $this->serverOps->run(
            $this->asUser($application, ['tee', '-a', $this->trashDirectory($application)."/{$batch}.paths"]),
            ['feature' => 'application', 'op' => 'file_trash_record', 'application' => $application->id],
            timeout: 30,
            input: $lines,
        );
    }

    /** Above the document root, like the backups: a deleted `wp-config.php` still holds live credentials. */
    private function trashDirectory(Application $application): string
    {
        return $application->panelPath().'/trash';
    }

    /**
     * What is in the trash: one entry per deleted path, newest first.
     *
     * Reported with its batch (so a selection deleted together restores together) and its original location.
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
        $present = [];
        $recorded = $this->trashRecords($application);

        foreach (array_filter(array_map('trim', explode("\n", $result->output()))) as $line) {
            // `%P` gives `<batch>/<original/relative/path>`.
            [$batch, $path] = array_pad(explode('/', $line, 2), 2, '');

            if ($path === '' || ! preg_match('/^\d{8}-\d{6}$/', $batch)) {
                continue;
            }

            $present[$batch][$path] = true;

            // A batch that says what it holds is listed from that, below.
            if (isset($recorded[$batch])) {
                continue;
            }

            // Only the top of each deleted tree is listed: a deleted directory is one deletion, not hundreds.
            if (collect($entries)->contains(fn (array $e): bool => $e['batch'] === $batch && str_starts_with($path.'/', $e['path'].'/'))) {
                continue;
            }

            $entries[] = [
                'batch' => $batch,
                'path' => $path,
                'deleted_at' => $this->timestampFromBackupName($batch),
            ];
        }

        // Exactly what was deleted and is still there: a path restored on its own drops out, and a
        // failed move is never offered back.
        foreach ($recorded as $batch => $paths) {
            foreach (array_unique($paths) as $path) {
                if (isset($present[$batch][$path])) {
                    $entries[] = [
                        'batch' => $batch,
                        'path' => $path,
                        'deleted_at' => $this->timestampFromBackupName($batch),
                    ];
                }
            }
        }

        usort($entries, fn (array $a, array $b): int => strcmp($b['batch'], $a['batch']) ?: strcmp($a['path'], $b['path']));

        return $entries;
    }

    /**
     * Each batch's `<batch>.paths` record, batch => paths. Batches from before the record existed have
     * none and are listed the old way until they age out.
     *
     * @return array<string, array<int, string>>
     */
    private function trashRecords(Application $application): array
    {
        $root = $this->trashDirectory($application);

        $result = $this->serverOps->run(
            $this->asUser($application, ['find', $root, '-mindepth', '1', '-maxdepth', '1', '-type', 'f', '-name', '*.paths', '-exec', 'grep', '-H', '', '{}', '+']),
            ['feature' => 'application', 'op' => 'trash_records', 'application' => $application->id],
            timeout: 30,
        );

        if ($result->failed()) {
            return [];
        }

        $records = [];

        foreach (array_filter(explode("\n", $result->output())) as $line) {
            // `<root>/<batch>.paths:<path>`: the path is everything after the first colon past the record's name.
            if (! preg_match('#^'.preg_quote($root, '#').'/(\d{8}-\d{6})\.paths:(.+)$#', $line, $m)) {
                continue;
            }

            $records[$m[1]][] = trim($m[2], "/ \t\r");
        }

        return $records;
    }

    /**
     * The recoverable entries together with their actual disk footprint.
     *
     * One `du` measures every top-level entry, so a directory's size is useful (unlike its inode size
     * from `find`) without one process per deleted file.
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

        // `du` may fail for one unreadable entry yet print sizes for the rest: its output, not its exit
        // code, is the per-entry truth.
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
     * Refuses rather than overwrites if something is there again (same rule as rename() and copy()).
     */
    public function restoreFromTrash(Application $application, string $batch, string $path): void
    {
        abort_unless(preg_match('/^\d{8}-\d{6}$/', $batch) === 1, 422, __('errors/application.unknown_backup'));

        $source = $this->trashDirectory($application).'/'.$batch.'/'.ltrim($path, '/');
        abort_if($this->stat($application, $source) === null, 404);

        $target = $this->resolve($application, $path);
        $this->refuseExisting($application, $target, 'path');

        $this->run($application, ['mkdir', '-p', dirname($target)], 'trash_restore_dir');
        $this->run($application, ['mv', $source, $target], 'trash_restore');

        $this->sizeChanged($application);
    }

    /**
     * Restores every top-level deletion in a batch independently, so one occupied path does not strand
     * the rest, and reports exactly what happened.
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
     * Empties the trash: all of it, or one batch. The only unrecoverable operation here, by design: it
     * exists so the disk space comes back.
     */
    public function emptyTrash(Application $application, ?string $batch = null): void
    {
        if ($batch !== null) {
            abort_unless(preg_match('/^\d{8}-\d{6}$/', $batch) === 1, 422, __('errors/application.unknown_backup'));

            $this->run($application, ['rm', '-rf', $this->trashDirectory($application).'/'.$batch, $this->trashDirectory($application).'/'.$batch.'.paths'], 'trash_empty');

            return;
        }

        // Empty its contents, not the directory: `.panel` is root's, so the site user cannot remove `trash`
        // itself, and `rm -rf` on it would delete the contents, then fail and turn a successful empty into a 500.
        $this->run($application, ['find', $this->trashDirectory($application), '-mindepth', '1', '-maxdepth', '1', '-exec', 'rm', '-rf', '{}', '+'], 'trash_empty');

        $this->sizeChanged($application);
    }

    /**
     * Drops batches older than the retention window, so the trash does not leak disk space.
     */
    public function pruneTrash(Application $application): void
    {
        $days = (int) config('server.applications.trash_retention_days', 7);

        $this->serverOps->run(
            $this->asUser($application, [
                // Batches and their `.paths` records alike.
                'find', $this->trashDirectory($application), '-mindepth', '1', '-maxdepth', '1',
                '(', '-type', 'd', '-o', '-name', '*.paths', ')',
                '-mtime', '+'.$days, '-exec', 'rm', '-rf', '{}', '+',
            ]),
            ['feature' => 'application', 'op' => 'trash_prune', 'application' => $application->id],
            timeout: 60,
            // No trash yet on most sites: find exits 1.
            expectedExitCodes: [1],
        );

        $this->sizeChanged($application);
    }

    /**
     * Sets the mode on a single file or directory (the one-off counterpart to `PermissionFixer::fix()`).
     *
     * Three octal digits, or four with a sticky or setgid first digit (1777 and 2775 are ordinary
     * folder modes). Setuid is never accepted, and setgid only on a folder: on a file both let whoever
     * runs it act as the site.
     */
    public function chmod(Application $application, string $path, string $mode): void
    {
        $target = $this->resolve($application, $path);
        $stat = $this->stat($application, $target);
        abort_if($stat === null || ! in_array($stat['type'], ['f', 'd'], true), 404);

        if ($stat['type'] === 'f' && self::setsGroupId($mode)) {
            throw FileRefusedException::because('mode', 'setgid_file', 'errors/application.chmod_setgid_file');
        }

        $this->run($application, ['chmod', $mode, $target], 'chmod');
    }

    /**
     * Extracts a `.zip` or `.tar.gz`/`.tgz` already in the site into an existing directory, in place,
     * overwriting anything that collides (what installing or updating a plugin needs).
     *
     * The archive is untrusted. Every entry is listed and validated *before* anything is written: no
     * `.`/`..`/absolute entries (zip-slip), no symlink entries (a later static request might follow
     * one), and a cap on total uncompressed bytes and entry count (zip-bomb). Extraction then runs as
     * the site's own user: validation is the primary control, the user is the backstop.
     */
    public function extract(Application $application, string $path, string $targetPath): FileArchiveJob
    {
        $archive = $this->resolve($application, $path);
        $this->assertType($application, $archive, 'f');

        $format = $this->archiveFormat($path);

        if ($format === null) {
            throw FileRefusedException::because('path', 'not_archive', 'errors/application.file_not_archive');
        }

        $target = $this->resolve($application, $targetPath);
        $this->assertDestination($application, $target, 'target', $targetPath);

        $entries = $format === 'zip'
            ? $this->listZipEntries($application, $archive)
            : $this->listTarEntries($application, $archive);

        $unpacked = $this->validateArchiveEntries($application, $entries, $target);

        // Room for what it unpacks to, checked now rather than found out as a half-extracted folder and
        // a full disk.
        app(DiskSpaceCheck::class)->require($unpacked, $target, 'disk_space');

        // Queued like compress(), but validated first: the zip-slip and zip-bomb checks are the security
        // boundary, so they must answer before the request is accepted, not inside a worker.
        return $this->queue($application, 'extract', [$path], $targetPath);
    }

    /**
     * Records an accepted archive operation and hands it to a worker.
     *
     * Everything that can refuse (path guards, format, collision, zip-slip/zip-bomb validation) has
     * already refused by now: those are the security boundary and are cheap, so they answer inside the
     * request. Only the part whose cost scales with the user's data is queued.
     *
     * @param  list<string>  $sources  relative to the document root
     */
    private function queue(Application $application, string $operation, array $sources, string $target): FileArchiveJob
    {
        $job = FileArchiveJob::create([
            'application_id' => $application->id,
            'operation' => $operation,
            'sources' => $sources,
            'target' => $target,
            'status' => FileArchiveStatus::Queued,
            'user_id' => Auth::id(),
        ]);

        RunFileArchive::dispatch($job->id);

        return $job;
    }

    /**
     * Performs the work a queued row describes. Called only by {@see RunFileArchive}.
     *
     * The command is rebuilt from the stored paths, not serialized into the job payload, so a row cannot
     * carry an absolute path resolved against a web root the site no longer has.
     */
    public function runArchiveJob(FileArchiveJob $job): void
    {
        $application = $job->application;
        $target = $this->resolve($application, $job->target);
        $sources = (array) $job->sources;

        $timeout = (int) config('server.files.archive_job_timeout', 21600);

        if ($job->operation === 'extract') {
            $archive = $this->resolve($application, $sources[0] ?? '');
            $zip = $this->archiveFormat($sources[0] ?? '') === 'zip';

            $this->run(
                $application,
                $zip ? ['unzip', '-o', '-d', $target, $archive] : ['tar', '-xzf', $archive, '-C', $target],
                'extract',
                timeout: $timeout,
                tolerated: $zip ? self::onlyBackslashWarning(...) : null,
            );

            $this->sizeChanged($application);

            return;
        }

        $format = $this->archiveFormat($job->target) ?? 'tar';
        $parent = trim(dirname('/'.($sources[0] ?? '')), '/');

        try {
            $this->run(
                $application,
                $this->compressCommand($format, $target, array_map('basename', $sources)),
                count($sources) > 1 ? 'compress_many' : 'compress',
                cwd: $this->resolve($application, $parent),
                timeout: $timeout,
            );
        } catch (FileOperationException $e) {
            // A killed `tar` has already created its output file. Remove it, or the directory keeps a
            // truncated archive that looks real and the retry is refused by the collision guard.
            // Best-effort and unchecked: the panel was just cut off from this path, and losing the original
            // error to report a failed `rm` is worse than leaving the file.
            $this->serverOps->run(
                $this->asUser($application, ['rm', '-f', $target]),
                ['feature' => 'application', 'op' => 'file_archive_cleanup', 'application' => $application->id],
                timeout: 30,
            );

            throw $e;
        }

        $this->sizeChanged($application);
    }

    /** Bytes the finished archive occupies, or 0 when there is no single file to measure (every extract). */
    public function archiveSize(FileArchiveJob $job): int
    {
        if ($job->operation !== 'compress' || $job->application === null) {
            return 0;
        }

        $stat = $this->stat($job->application, $this->resolve($job->application, $job->target));

        return $stat['size'] ?? 0;
    }

    /**
     * The command that writes one archive, by format.
     *
     * Both run from the sources' own directory (`cwd`) with bare names, so the archive holds `plugin/…`
     * rather than the server's directory layout (useless elsewhere, and a small disclosure).
     *
     * tar.gz is more than symmetry with extract(): ZIP does not keep Unix permissions (a 0600
     * wp-config.php does not stay 0600); tar keeps mode, ownership and symlinks.
     *
     * @param  array<int, string>  $names
     * @return array<int, string>
     */
    private function compressCommand(string $format, string $target, array $names): array
    {
        return $format === 'zip'
            // zip has no parallel equivalent, so it stays on one core.
            ? array_merge(['zip', '-r', $target], $names)
            // `--use-compress-program` rather than `-z`, which hardcodes single-core gzip level 6. pigz emits
            // ordinary gzip, so `tar -xzf` and desktop tools still open it.
            //
            // `--` so a name beginning with a dash is a path, not an option.
            : array_merge(['tar', '--use-compress-program='.$this->compressor->program('server.files'), '-cf', $target, '--'], $names);
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
     * The zip-slip/zip-bomb checks, shared by both archive formats so the safety rules cannot drift.
     *
     * @param  array<int, array{name: string, type: string, size: int, host?: string}>  $entries
     */
    private function validateArchiveEntries(Application $application, array $entries, string $target): int
    {
        abort_if($entries === [], 422, __('errors/application.archive_empty'));
        abort_if(count($entries) > self::MAX_ARCHIVE_ENTRIES, 422, __('errors/application.archive_too_many_entries'));

        $appRoot = $this->root($application);
        $totalBytes = 0;

        foreach ($entries as $entry) {
            abort_if($entry['type'] === 'l', 422, __('errors/application.archive_has_symlink'));
            // `?`: no file type recorded (zips written by Python, Java, ...); unzip extracts it as a plain file.
            abort_unless(in_array($entry['type'], ['-', 'd', '?'], true), 422, __('errors/application.archive_unsafe_entry'));

            $name = rtrim($this->extractedName($entry), '/');

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

        return $totalBytes;
    }

    /**
     * An entry's name as it will be written, so the checks judge the path that actually lands on disk.
     *
     * A zip made on Windows can use `\` as its folder separator. unzip turns `\` into `/` only for an
     * entry made on FAT (MS-DOS/Windows) whose name has no `/`; any other name is written literally.
     * So this converts exactly when unzip will, and the later checks (`..`, leading `/`, staying inside
     * the site) see `..\..\etc\passwd` as `../../etc/passwd` and refuse it.
     *
     * @param  array{name: string, type: string, size: int, host?: string}  $entry
     */
    private function extractedName(array $entry): string
    {
        $name = $entry['name'];

        if (! str_contains($name, '\\')) {
            return $name;
        }

        abort_unless(
            ($entry['host'] ?? null) === 'fat' && ! str_contains($name, '/'),
            422,
            __('errors/application.archive_backslash_name'),
        );

        $name = str_replace('\\', '/', $name);

        // `C:\x.txt`: a Windows absolute path; unzip would make a folder named `C:`.
        abort_if(preg_match('/^[A-Za-z]:(\/|$)/', $name) === 1, 422, __('errors/application.archive_windows_drive'));

        return $name;
    }

    /**
     * One line per zip entry: permission string (first character is the type: `d`/`l`/`-`), size and
     * name, from a single `unzip -Z` pass so the listing and size total cannot disagree.
     *
     * The third column is the system the entry was made on (`unx`, `fat`, `ntf`…), which decides how
     * unzip treats a `\` in its name; see {@see extractedName()}.
     *
     * @return array<int, array{name: string, type: string, size: int, host: string}>
     */
    private function listZipEntries(Application $application, string $archive): array
    {
        $result = $this->serverOps->run(
            $this->asUser($application, ['unzip', '-Z', $archive]),
            ['feature' => 'application', 'op' => 'file_archive_list', 'application' => $application->id],
            timeout: 30,
        );

        // A failed listing means a corrupt archive or not really a zip: the caller's input is wrong, not a
        // server failure.
        abort_if($result->failed(), 422, __('errors/application.archive_unreadable'));

        $entries = [];

        // Any attribute string, not only Unix's `-rw-r--r--` (a Windows zip lists `-rw-a--`, which would
        // read as empty). The type is the first character; validateArchiveEntries() admits files and
        // directories only.
        foreach (explode("\n", $result->output()) as $line) {
            if (preg_match('/^(\S)\S{5,9}\s+\d+\.\d+\s+(\S+)\s+(\d+)\s+\S+\s+\S+\s+\S+\s+\S+\s+(.*)$/', $line, $m) !== 1) {
                continue;
            }

            $entries[] = ['type' => $m[1], 'host' => $m[2], 'size' => (int) $m[3], 'name' => $m[4]];
        }

        // Every entry unzip will extract must have been read here: a skipped line would be extracted
        // unchecked. unzip ends the listing with "N files, …"; a disagreeing count refuses the archive.
        if (preg_match('/^(\d+) files?,/m', $result->output(), $total) === 1) {
            abort_if((int) $total[1] !== count($entries), 422, __('errors/application.archive_unreadable'));
        }

        return $entries;
    }

    /**
     * One line per tar entry via `tar -tvzf`: permission string, owner/group, size, date, time, name.
     * A symlink's `-> target` suffix is never read: symlink entries are rejected by type alone.
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

    /**
     * The directory this browser is confined to: the site's `public_html`.
     *
     * The code root, not the document root: for sites serving from `public/` the document root is a
     * subdirectory, and rooting there would hide the application's `.env`, `composer.json` and `vendor/`.
     * Still the site's directory, not its home: `.panel/` is a sibling, so the Basic Auth hash and
     * pre-push database dumps stay out of reach.
     */
    private function root(Application $application): string
    {
        return rtrim($application->publicHtmlPath(), '/');
    }

    private function resolve(Application $application, string $path): string
    {
        $root = $this->root($application);

        return $path === '' ? $root : "{$root}/{$path}";
    }

    /**
     * Confirms the web root directory actually exists on disk.
     *
     * Otherwise a wrong path would silently return an empty listing, which looks like "no files";
     * this returns a clear 422 instead.
     */
    private function assertRootExists(Application $application): void
    {
        $root = $this->root($application);

        $result = $this->serverOps->probe(
            $this->asUser($application, ['test', '-d', $root]),
            ['feature' => 'application', 'op' => 'file_root_check', 'application' => $application->id],
            timeout: 15,
        );

        // When the probe itself never ran, say so with a reference: advice to "check your web root"
        // would be about the wrong thing, and the directory may be fine.
        if (! $result->answered) {
            throw new FileOperationException($result->reference);
        }

        abort_unless($result->ok, 422, __('errors/application.web_root_not_found'));
    }

    /**
     * The folder something is going into must exist. Refused under the field that names the folder (a
     * generic 404 would read as if the file being copied had gone).
     */
    private function assertDestination(Application $application, string $directory, string $field, string $shown): void
    {
        $stat = $this->stat($application, $directory);

        if ($stat !== null && $stat['type'] === 'd') {
            return;
        }

        $shown = trim($shown, '/.');

        throw FileRefusedException::because(
            $field,
            $stat === null ? 'destination_missing' : 'destination_not_directory',
            $stat === null ? 'errors/application.destination_missing' : 'errors/application.destination_not_directory',
            ['path' => '/'.$shown],
        );
    }

    /** A four-digit mode whose first digit carries the setgid bit (2 or 3). */
    private static function setsGroupId(string $mode): bool
    {
        return strlen($mode) === 4 && in_array($mode[0], ['2', '3'], true);
    }

    /**
     * Refuses moving or copying a folder into itself (`a` to `a/b`), which `mv`/`cp` reject with a 500.
     * A sibling whose name only starts the same (`a` to `ab/x`) is a different folder and is allowed.
     */
    private function refuseIntoItself(string $type, string $source, string $target, string $field): void
    {
        // A file cannot hold anything: `f` to `f/x` keeps its existing "not a folder" answer.
        if ($type !== 'd') {
            return;
        }

        // The same path is left to refuseExisting(): "already exists" is the truer answer.
        if ($target !== rtrim($source, '/') && self::isInside($target, $source)) {
            throw FileRefusedException::because($field, 'into_itself', 'errors/application.into_itself');
        }
    }

    /** Whether $path is $folder itself or somewhere below it. */
    private static function isInside(string $path, string $folder): bool
    {
        $folder = rtrim($folder, '/');

        return $path === $folder || str_starts_with($path, $folder.'/');
    }

    private function refuseExisting(Application $application, string $target, string $field): void
    {
        if ($this->stat($application, $target) !== null) {
            throw FileRefusedException::because($field, 'path_exists', 'errors/application.path_exists');
        }
    }

    /**
     * Confirms the path exists and is the expected type. "Does not exist" is a 404 for the caller, not a
     * server-operation failure.
     */
    private function assertType(Application $application, string $target, string $expected): int
    {
        $stat = $this->stat($application, $target);

        abort_if($stat === null || $stat['type'] !== $expected, 404);

        return $stat['size'];
    }

    /**
     * Like `assertType()`, but returns `null` instead of aborting when the path does not exist (for
     * create-directory and rename's destination, where that is expected).
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
     * @param  (callable(ServerOpsResult): bool)|null  $tolerated  a failure the caller knows is not one
     */
    private function run(Application $application, array $command, string $op, ?string $input = null, ?string $cwd = null, ?int $timeout = null, ?callable $tolerated = null): ServerOpsResult
    {
        $result = $this->serverOps->run(
            $this->asUser($application, $command),
            ['feature' => 'application', 'op' => "file_{$op}", 'application' => $application->id],
            // 60 s for the fast single-syscall operations; archiving passes its own, as its runtime scales
            // with the data selected.
            timeout: $timeout ?? 60,
            input: $input,
            cwd: $cwd,
        );

        if ($result->failed() && ! ($tolerated !== null && $tolerated($result))) {
            throw new FileOperationException($result->reference, busy: $result->busy, staleLock: $result->staleLock, denied: $result->denied, timedOut: $result->timedOut);
        }

        return $result;
    }

    /**
     * unzip's exit 1 after it turned a Windows zip's `\` into folders.
     *
     * Exit 1 is unzip's "warnings, but completed": every file is written and the run still "fails".
     * Accepted only when that warning is the whole of stderr: exit 1 also covers skipped entries, which
     * must still fail the job.
     */
    private static function onlyBackslashWarning(ServerOpsResult $result): bool
    {
        if ($result->exitCode() !== 1 || $result->timedOut || $result->denied || $result->busy) {
            return false;
        }

        $lines = array_filter(array_map('trim', explode("\n", $result->errorOutput())), fn (string $line): bool => $line !== '');

        return $lines !== [] && array_filter(
            $lines,
            fn (string $line): bool => preg_match('/^warning:\s+.+ appears to use backslashes as path separators$/', $line) !== 1,
        ) === [];
    }

    /**
     * Stats many paths in one command (N separate stats is N processes before any work starts).
     *
     * `find` reports a missing path on stderr and exits non-zero while still processing the rest, so the
     * exit code is ignored: absence from the output is the answer.
     *
     * @param  list<string>  $paths  relative to the document root
     * @return array<string, array{type: string, mode: string}> keyed by relative path
     */
    private function statMany(Application $application, array $paths): array
    {
        if ($paths === []) {
            return [];
        }

        $root = $this->root($application);
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
     * Files and directories are sent separately (`-f` vs `-rf`) like `delete()`: a blanket `-r` would
     * widen what one mistaken path can destroy. The result comes from re-statting afterwards, not from
     * `rm`'s stderr, which stays silent about failures it cannot attribute.
     *
     * @param  list<string>  $paths
     * @return array{succeeded: list<string>, failed: list<array{path: string, reason: string}>}
     */
    public function deleteMany(Application $application, array $paths, bool $permanent = false): array
    {
        $this->assertRootExists($application);

        // The site root may never be deleted, in bulk or otherwise: it would take the document root with it.
        abort_if(in_array('', $paths, true), 422, __('errors/application.cannot_delete_root'));

        $found = $this->statMany($application, $paths);
        $failed = $this->missing($paths, $found);

        $directories = [];
        $files = [];

        foreach (array_keys($found) as $relative) {
            $target = $this->resolve($application, $relative);
            $found[$relative]['type'] === 'd' ? $directories[] = $target : $files[] = $target;
        }

        // One timestamp for the whole selection, so things deleted together restore together.
        if (! $permanent) {
            $stamp = now()->format('Ymd-His');

            $this->panelDirectory->ensure($application, 'trash');

            foreach (array_keys($found) as $relative) {
                $destination = $this->trashDirectory($application).'/'.$stamp.'/'.ltrim($relative, '/');

                $this->tolerant($application, ['mkdir', '-p', dirname($destination)], 'trash_dir');
                $this->tolerant($application, ['mv', $this->resolve($application, $relative), $destination], 'trash');
            }

            // What was meant to go in, not what made it: the listing only shows recorded paths that are
            // really there.
            $this->recordTrashed($application, $stamp, array_keys($found));

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
     * Copies or moves many paths into one directory (a selection names one destination, not one per source).
     *
     * @param  list<string>  $paths
     * @return array{succeeded: list<string>, failed: list<array{path: string, reason: string}>}
     */
    public function transferMany(Application $application, array $paths, string $targetDirectory, bool $move): array
    {
        $this->assertRootExists($application);

        $destination = $this->resolve($application, $targetDirectory);
        $this->assertDestination($application, $destination, 'target_directory', $targetDirectory);

        $found = $this->statMany($application, $paths);
        $failed = $this->missing($paths, $found);

        // An occupied destination is refused, not overwritten: same rule as `copy()`/`rename()`, so a bulk
        // move cannot destroy something a single move would have protected.
        $landing = [];

        foreach (array_keys($found) as $relative) {
            $landing[$relative] = ltrim($targetDirectory.'/'.basename($relative), '/');
        }

        $occupied = $this->statMany($application, array_values($landing));
        $sources = [];

        foreach ($landing as $relative => $target) {
            if (self::isInside($destination, $this->resolve($application, $relative))) {
                $failed[] = ['path' => $relative, 'reason' => 'into_itself'];

                continue;
            }

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

        // Setgid on a file runs it as the site's group for whoever starts it; only folders take it.
        if (self::setsGroupId($mode)) {
            foreach ($found as $relative => $entry) {
                if (($entry['type'] ?? '') !== 'd') {
                    $failed[] = ['path' => $relative, 'reason' => 'setgid_file'];
                    unset($found[$relative]);
                }
            }
        }

        $targets = array_map(
            fn (string $relative): string => $this->resolve($application, $relative),
            array_keys($found),
        );

        if ($targets !== []) {
            $this->tolerant($application, array_merge(['chmod', $mode], $targets), 'chmod_many');
        }

        // Verified against the mode actually on disk (hence `%m` in statMany).
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
     * Every source must sit in the same directory: `zip` runs from there with bare names (as in
     * `compress()`), so the archive holds `style.css` rather than the server's directory layout.
     *
     * @param  list<string>  $paths
     */
    public function compressMany(Application $application, array $paths, string $targetPath): FileArchiveJob
    {
        $this->assertRootExists($application);

        $format = $this->archiveFormat($targetPath);
        if ($format === null) {
            throw FileRefusedException::because('target', 'not_archive', 'errors/application.target_not_archive');
        }

        $parents = array_unique(array_map(
            fn (string $path): string => trim(dirname('/'.$path), '/'),
            $paths,
        ));

        abort_if(count($parents) > 1, 422, __('errors/application.sources_not_in_one_directory'));

        $found = $this->statMany($application, $paths);

        abort_if(count($found) !== count(array_unique($paths)), 404);

        $target = $this->resolve($application, $targetPath);
        $this->refuseExisting($application, $target, 'target');
        $this->assertType($application, dirname($target), 'd');
        $this->requireRoomToCompress($application, array_map(fn (string $path): string => $this->resolve($application, $path), array_values($paths)), $target);

        return $this->queue($application, 'compress', array_values($paths), $targetPath);
    }

    /**
     * Room for the archive first. Its size is unknown until written, so this asks for the size of what
     * goes in (the most it can be; compression only shrinks it). A source that cannot be measured counts
     * as nothing.
     *
     * @param  list<string>  $sources  absolute paths
     */
    private function requireRoomToCompress(Application $application, array $sources, string $target): void
    {
        $bytes = 0;

        foreach ($sources as $source) {
            try {
                $bytes += $this->measure($application, $source);
            } catch (Throwable) {
                // Unknown, not refused.
            }
        }

        app(DiskSpaceCheck::class)->require($bytes, dirname($target), 'disk_space');
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
     * `run()` throws on a non-zero exit, which is wrong here: `rm` on twelve paths exits non-zero if one
     * fails, and throwing would report eleven successful deletions as a server error.
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
