<?php

namespace App\Services\Server\Databases\Imports;

use App\Models\DatabaseImport;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

/**
 * Where an import's uploaded file lives while it arrives and waits, and what kind of file it is.
 *
 * The panel's own directory, not a site's: no site user may read or swap the file. Plain PHP
 * file I/O as the panel account; no ServerOps, no elevation.
 */
class ImportFiles
{
    /** What a file turned out to be, judged by its first bytes, never its name. */
    public const SQL = 'sql';

    public const PG_CUSTOM = 'pg_custom';

    public const MONGO_ARCHIVE = 'mongo_archive';

    /** Left free after an upload; a full disk takes every site on the box with it. */
    public const MIN_FREE_BYTES = 1024 * 1024 * 1024;

    public function directory(): string
    {
        $dir = rtrim((string) config('server.databases.import_dir'), '/');
        File::ensureDirectoryExists($dir, 0700);

        return $dir;
    }

    public function uploadPath(DatabaseImport $import): string
    {
        return $this->directory().'/'.$import->id.'.upload';
    }

    /** The cleaned SQL an import actually loads, written by ImportSanitizer. */
    public function workPath(DatabaseImport $import): string
    {
        return $this->directory().'/'.$import->id.'.sql';
    }

    public static function maxBytes(): int
    {
        return (int) config('server.databases.import_max_bytes');
    }

    /**
     * Refuse before the upload when the disk cannot take it.
     */
    public function assertRoom(int $bytes): void
    {
        $free = @disk_free_space($this->directory());

        if ($free !== false && $free - $bytes < self::MIN_FREE_BYTES) {
            throw ValidationException::withMessages([
                'size_bytes' => [__('errors/database.import_no_space')],
            ]);
        }
    }

    public function begin(DatabaseImport $import): void
    {
        $this->assertRoom($import->size_bytes);

        $path = $this->uploadPath($import);
        touch($path);
        @chmod($path, 0600);
    }

    /**
     * Append one chunk and return the new size.
     *
     * Bounded by the size declared at the start (what the disk check and limit ran against): an
     * overshooting body is refused and the excess cut off.
     *
     * @param  resource  $stream
     */
    public function append(DatabaseImport $import, $stream): int
    {
        $path = $this->uploadPath($import);
        $before = (int) @filesize($path);
        $room = max(0, $import->size_bytes - $before);

        $handle = fopen($path, 'ab');
        // One byte past the room left, so an overshoot is seen rather than cut silently.
        $copied = (int) stream_copy_to_stream($stream, $handle, $room + 1);
        fclose($handle);

        if ($copied > $room) {
            $handle = fopen($path, 'r+b');
            ftruncate($handle, $before + $room);
            fclose($handle);

            throw ValidationException::withMessages([
                'file' => [__('errors/database.import_larger_than_declared')],
            ]);
        }

        clearstatcache(true, $path);

        return (int) filesize($path);
    }

    public function received(DatabaseImport $import): int
    {
        $path = $this->uploadPath($import);
        clearstatcache(true, $path);

        return is_file($path) ? (int) filesize($path) : 0;
    }

    /**
     * What the file is, from its first bytes; a gzip is decompressed first.
     *
     * @return array{format: string, gzip: bool}|null null for anything this does not import
     */
    public function inspect(string $path): ?array
    {
        $raw = $this->head($path, false);
        $gzip = str_starts_with($raw, "\x1f\x8b");
        $head = $gzip ? $this->head($path, true) : $raw;

        if ($head === '') {
            return null;
        }

        // mongodump --archive: a fixed 4-byte magic, 0x8199e26d little-endian.
        if (str_starts_with($head, "\x6d\xe2\x99\x81")) {
            return ['format' => self::MONGO_ARCHIVE, 'gzip' => $gzip];
        }

        // pg_dump -Fc. Only ever uncompressed on the outside; it compresses itself.
        if (! $gzip && str_starts_with($head, 'PGDMP')) {
            return ['format' => self::PG_CUSTOM, 'gzip' => false];
        }

        // Text, or it is not a dump: a NUL in the first block means zip, tar, image, etc. Not a
        // UTF-8 check, since a latin1 database dumps latin1 bytes.
        if (! str_contains($head, "\0")) {
            return ['format' => self::SQL, 'gzip' => $gzip];
        }

        return null;
    }

    /**
     * Whether a gzip file reaches its end marker. gzread() stays silent about a truncated file,
     * which would load "fine" while missing every table after the cut; check before anything is
     * dropped. Further members (`cat a.gz b.gz`) are followed; trailing non-member bytes are
     * ignored, as gzip does.
     */
    public function gzipComplete(string $path): bool
    {
        $in = @fopen($path, 'rb');

        if ($in === false) {
            return false;
        }

        try {
            $inflate = inflate_init(ZLIB_ENCODING_GZIP);
            $ended = false;

            while (! feof($in)) {
                $chunk = (string) fread($in, 1 << 20);

                while ($chunk !== '') {
                    if ($ended) {
                        if (! str_starts_with($chunk, "\x1f\x8b")) {
                            return true;
                        }

                        $inflate = inflate_init(ZLIB_ENCODING_GZIP);
                        $ended = false;
                    }

                    $before = inflate_get_read_len($inflate);

                    if (@inflate_add($inflate, $chunk, ZLIB_SYNC_FLUSH) === false) {
                        return false;
                    }

                    if (inflate_get_status($inflate) !== ZLIB_STREAM_END) {
                        break;
                    }

                    $chunk = substr($chunk, inflate_get_read_len($inflate) - $before);
                    $ended = true;
                }
            }

            return $ended;
        } finally {
            fclose($in);
        }
    }

    public function delete(DatabaseImport $import): void
    {
        @unlink($this->uploadPath($import));
        @unlink($this->workPath($import));
    }

    private function head(string $path, bool $gzip): string
    {
        $handle = $gzip ? @gzopen($path, 'rb') : @fopen($path, 'rb');

        if ($handle === false) {
            return '';
        }

        $head = (string) ($gzip ? gzread($handle, 4096) : fread($handle, 4096));
        $gzip ? gzclose($handle) : fclose($handle);

        return $head;
    }
}
