<?php

namespace App\Services\Server;

use App\Contracts\PhpStack;
use App\Exceptions\Server\Log\LogOperationException;
use App\Models\Cronjob;
use App\Support\ListSort;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Read-only access to server log files. The catalog is the configured source
 * registry, plus one log per PHP version the stack reports, plus one
 * source per cron job (the only part that reads the DB — a cron log's label
 * is its job's name, which only the DB knows). Everything is filtered at read
 * time to files that actually exist (detect-don't-trust).
 *
 * Callers reference a source by its `key`; this class resolves the real path
 * from the registry — a client-supplied path is never read (no traversal).
 * Reads are native filesystem operations (efficient tail / byte-range / grep),
 * not shelled commands, so there is no injection surface.
 */
class LogManager
{
    public const DEFAULT_LINES = 200;

    public const MAX_LINES = 5000;

    // An incremental poll can be arbitrarily far behind. Cap its byte window
    // before reading so a busy log cannot exhaust an FPM worker's memory.
    public const MAX_INCREMENTAL_BYTES = 1048576;

    /** Lines pulled through a privileged read before filtering in PHP. */
    private const PRIVILEGED_WINDOW = 5000;

    public function __construct(
        private PhpStack $stack,
        private ServerOps $serverOps,
    ) {}

    /**
     * All configured sources that exist on this box, with live metadata.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(): array
    {
        return array_values(array_filter(array_map(
            fn (array $source) => $this->describe($source),
            $this->catalog(),
        )));
    }

    /**
     * The registry entry for a key, or null if it isn't a managed source.
     *
     * @return array{key: string, label: string, group: string, path: string}|null
     */
    public function find(string $key): ?array
    {
        foreach ($this->catalog() as $source) {
            if ($source['key'] === $key) {
                return $source;
            }
        }

        return null;
    }

    /**
     * Display metadata for one source, or null when the file doesn't exist.
     *
     * @param  array{key: string, label: string, group: string, path: string}  $source
     * @return array<string, mixed>|null
     */
    public function describe(array $source): ?array
    {
        $kind = $source['kind'] ?? 'file';

        if (! $this->exists($source)) {
            return null;
        }

        $stat = $kind === 'file'
            ? ['size' => (int) (filesize($source['path']) ?: 0), 'modified' => filemtime($source['path']) ?: 0]
            : $this->remoteStat($source);

        return [
            'key' => $source['key'],
            'label' => $source['label'],
            'group' => $source['group'],
            'kind' => $kind,
            'size' => $stat['size'],
            'modified' => $stat['modified'] === null ? null : date('d-m-Y H:i:s', $stat['modified']),
            // A privileged source is read through sudo, so "can the panel
            // account open it" is the wrong question — it never can, and it
            // does not need to.
            'readable' => $kind === 'file' ? is_readable($source['path']) : true,
            // Following by byte offset needs bytes and a stable file. The
            // journal has neither, and re-reading a privileged file through
            // sudo on every poll costs more than it saves — those re-tail.
            'follow' => $kind === 'file',
            // Nothing to stream: there is no handle the panel can open, and
            // piping it through sudo would hold a worker for the whole
            // transfer.
            'downloadable' => $kind === 'file',
        ];
    }

    /**
     * Whether this source has anything to show, asked the way its kind allows.
     *
     * @param  array<string, mixed>  $source
     */
    private function exists(array $source): bool
    {
        return match ($source['kind'] ?? 'file') {
            // `probe`, not `run`: exit 1 here means "no such log", which is an
            // ordinary answer for a job that has not run yet — not a failed
            // server operation worth an error in the admin log.
            'privileged' => $this->serverOps->probe(
                ['test', '-f', $source['path']],
                ['feature' => 'log', 'op' => 'exists', 'source' => $source['key']],
                timeout: 15,
            )->ok,
            // Asking journalctl for one line is the cheapest way to learn both
            // that it is installed and that the panel may read it.
            'journal' => $this->serverOps->run(
                ['journalctl', '-n', '1', '--no-pager'],
                ['feature' => 'log', 'op' => 'exists', 'source' => $source['key']],
                timeout: 15,
            )->ok,
            default => is_file($source['path']),
        };
    }

    /**
     * Size and mtime for a source the panel cannot stat itself.
     *
     * @param  array<string, mixed>  $source
     * @return array{size: int|null, modified: int|null}
     */
    private function remoteStat(array $source): array
    {
        if (($source['kind'] ?? 'file') === 'journal') {
            // The journal is not a file and has no single size or mtime. Null
            // rather than a plausible-looking zero, which would read as an
            // empty log that was last written in 1970.
            return ['size' => null, 'modified' => null];
        }

        $result = $this->serverOps->run(
            ['stat', '-c', '%s %Y', $source['path']],
            ['feature' => 'log', 'op' => 'stat', 'source' => $source['key']],
            timeout: 15,
        );

        if ($result->failed()) {
            return ['size' => null, 'modified' => null];
        }

        $parts = preg_split('/\s+/', trim($result->output())) ?: [];

        return [
            'size' => isset($parts[0]) ? (int) $parts[0] : null,
            'modified' => isset($parts[1]) ? (int) $parts[1] : null,
        ];
    }

    /**
     * Read content for a source: filtered matches, incremental bytes since a
     * cursor, or the last N lines. Assumes existence/readability were already
     * checked by the caller. Returns null for an unknown/missing source.
     *
     * @return array{lines: array<int, string>, cursor: int, truncated: bool}|null
     */
    public function read(string $key, int $lines, ?string $filter = null, ?int $after = null): ?array
    {
        $source = $this->find($key);

        if (! $source) {
            return null;
        }

        if (($source['kind'] ?? 'file') !== 'file') {
            return $this->readPrivileged($source, $lines, $filter);
        }

        if (! is_file($source['path'])) {
            return null;
        }

        $path = $source['path'];
        $size = (int) (filesize($path) ?: 0);
        $lines = max(1, min($lines, self::MAX_LINES));

        // Literal (non-regex) filter: last N matching lines.
        if ($filter !== null && $filter !== '') {
            $match = $this->grep($path, $filter, $lines);

            return ['lines' => $match['lines'], 'cursor' => $size, 'truncated' => $match['truncated']];
        }

        // Incremental follow: bytes appended since `after`. If the file is now
        // smaller than the cursor it was rotated/truncated → fall back to tail.
        if ($after !== null && $after <= $size) {
            $result = $this->range($path, $after);

            return ['lines' => $result['lines'], 'cursor' => $size, 'truncated' => $result['truncated']];
        }

        // Initial load (or post-rotation): last N lines.
        $tail = $this->tail($path, $lines);

        return ['lines' => $tail['lines'], 'cursor' => $size, 'truncated' => $tail['truncated']];
    }

    /**
     * A source the panel cannot open itself: read a bounded window through
     * ServerOps and filter it here.
     *
     * The window is pulled first and searched second, rather than asking `grep`
     * to do it. ServerOps passes an argv array with no shell, so a needle
     * containing a metacharacter is data either way — but `grep -m` counts
     * matches from the *start* of the file and a log viewer wants the last
     * ones, and an unbounded grep over a large log returns everything it finds.
     * Reading a fixed number of lines and filtering them is the same thing
     * ApplicationLogManager does, and it is bounded by construction.
     *
     * `cursor` is null: there are no byte offsets to come back with.
     *
     * @param  array<string, mixed>  $source
     * @return array{lines: array<int, string>, cursor: int|null, truncated: bool}|null
     */
    private function readPrivileged(array $source, int $lines, ?string $filter): ?array
    {
        $lines = max(1, min($lines, self::MAX_LINES));
        $window = $filter !== null && $filter !== '' ? self::PRIVILEGED_WINDOW : $lines;

        $command = ($source['kind'] ?? 'file') === 'journal'
            ? ['journalctl', '-n', (string) $window, '--no-pager']
            : ['tail', '-n', (string) $window, $source['path']];

        $result = $this->serverOps->run(
            $command,
            ['feature' => 'log', 'op' => 'read', 'source' => $source['key']],
            timeout: 30,
        );

        if ($result->failed()) {
            // Same rule as everywhere else here: a read that did not happen is
            // not an empty log.
            throw new LogOperationException($result->reference);
        }

        $all = $this->split($result->output());

        if ($filter !== null && $filter !== '') {
            $all = array_values(array_filter(
                $all,
                fn (string $line): bool => stripos($line, $filter) !== false,
            ));
        }

        $truncated = count($all) > $lines;

        return [
            'lines' => $truncated ? array_slice($all, -$lines) : $all,
            'cursor' => null,
            'truncated' => $truncated,
        ];
    }

    /**
     * Configured sources + php-fpm logs detected per installed version.
     *
     * @return array<int, array{key: string, label: string, group: string, path: string}>
     */
    private function catalog(): array
    {
        return array_merge(config('server.logs', []), $this->phpFpmLogs(), $this->cronjobLogs());
    }

    /**
     * One source per cron job that has captured output.
     *
     * Sourced from the cronjobs table rather than by globbing the log
     * directory, so a source always maps to a job that exists — and the label
     * is the job's name, not a filename the user has to decode.
     *
     * @return array<int, array{key: string, label: string, group: string, path: string}>
     */
    private function cronjobLogs(): array
    {
        $dir = rtrim((string) config('server.cronjob_log_dir', '/var/log/cronjobs'), '/');

        return ListSort::caseInsensitive(
            Cronjob::query()->whereNotNull('slug'),
            'name',
        )->get(['name', 'slug'])
            ->map(fn (Cronjob $cronjob) => [
                'key' => "cronjob_{$cronjob->slug}",
                'label' => "Cron — {$cronjob->name}",
                'group' => 'cronjob',
                'path' => "{$dir}/{$cronjob->slug}.log",
                // Read through sudo, unlike every other source here.
                //
                // install.sh puts the panel account in `adm`, which is what
                // makes the system logs readable: they are root:adm 0640. A
                // cron log is 0640 too, but `chown {username}` leaves the group
                // as that user's own — deliberately, because `nobody`'s group
                // is `nogroup` and naming one would fail for it. So `adm` does
                // not help here, the panel is neither owner nor group, and the
                // file it offers on the Logs screen is the one file there it
                // cannot open.
                //
                // Privileged rather than widening the mode: a job's output can
                // contain anything it printed, and 0640 was chosen to keep that
                // off other accounts.
                'kind' => 'privileged',
            ])
            ->all();
    }

    /**
     * @return array<int, array{key: string, label: string, group: string, path: string}>
     */
    private function phpFpmLogs(): array
    {
        $logs = [];

        foreach ($this->stack->versions() as $version) {
            $path = $this->stack->logPath($version);

            if ($path === null) {
                continue;
            }

            $logs[] = [
                'key' => "php{$version}_fpm",
                'label' => "PHP {$version} FPM",
                'group' => 'php',
                'path' => $path,
            ];
        }

        return $logs;
    }

    /**
     * Last N lines, read from the end of the file in chunks (never loads the
     * whole file). `truncated` = there was more content above the window.
     *
     * @return array{lines: array<int, string>, truncated: bool}
     */
    private function tail(string $path, int $lines): array
    {
        $handle = $this->open($path);

        $position = (int) (filesize($path) ?: 0);
        $buffer = '';

        // Stop at the same byte ceiling the incremental path uses. The newline
        // count alone is not a bound: a log with few line breaks — a cron job
        // that printed one large blob, a stack trace on a single line — never
        // satisfies it, and the buffer grows until it holds the whole file and
        // the worker dies. `range()` was given this cap already; this is the
        // path every first page load takes.
        $floor = max(0, $position - self::MAX_INCREMENTAL_BYTES);

        while ($position > $floor && substr_count($buffer, "\n") <= $lines) {
            $read = (int) min(4096, $position - $floor);
            $position -= $read;
            fseek($handle, $position);
            $buffer = ((string) fread($handle, $read)).$buffer;
        }

        fclose($handle);

        $all = $this->split($buffer);
        $truncated = $position > 0;

        // The window may begin halfway through a line, and half a line read as
        // a whole one is a log entry the panel invented.
        if ($position > 0 && $position === $floor && count($all) > 1) {
            array_shift($all);
        }

        if (count($all) > $lines) {
            $all = array_slice($all, -$lines);
            $truncated = true;
        }

        return ['lines' => $all, 'truncated' => $truncated];
    }

    /**
     * All content from byte `offset` to EOF, capped to MAX_LINES.
     *
     * @return array{lines: array<int, string>, truncated: bool}
     */
    private function range(string $path, int $offset): array
    {
        $handle = $this->open($path);

        $start = max($offset, (int) filesize($path) - self::MAX_INCREMENTAL_BYTES);
        $byteTruncated = $start > $offset;

        fseek($handle, $start);
        $content = (string) stream_get_contents($handle, self::MAX_INCREMENTAL_BYTES);
        fclose($handle);

        $lines = $this->split($content);
        if ($byteTruncated) {
            // The capped window may begin halfway through a line.
            array_shift($lines);
        }

        $truncated = $byteTruncated;
        if (count($lines) > self::MAX_LINES) {
            $lines = array_slice($lines, -self::MAX_LINES);
            $truncated = true;
        }

        return ['lines' => $lines, 'truncated' => $truncated];
    }

    /**
     * Last N lines containing `needle` (case-insensitive, literal — not a
     * regex, so no ReDoS/injection). Streams line by line.
     *
     * @return array{lines: array<int, string>, truncated: bool}
     */
    private function grep(string $path, string $needle, int $lines): array
    {
        $handle = $this->open($path);

        $matches = [];
        $truncated = false;

        while (($line = fgets($handle)) !== false) {
            if (stripos($line, $needle) !== false) {
                $matches[] = rtrim($line, "\r\n");

                if (count($matches) > $lines) {
                    array_shift($matches);
                    $truncated = true;
                }
            }
        }

        fclose($handle);

        return ['lines' => $matches, 'truncated' => $truncated];
    }

    /**
     * Open a log for reading, or say that it could not be opened.
     *
     * The three readers used to answer an unopenable file with an empty list,
     * which renders as a log with nothing in it — the opposite of the truth, on
     * the screen someone opens to find out what went wrong. The controller
     * checks existence and readability first, but a log is a moving target:
     * logrotate renames it mid-request, permissions change, the process runs
     * out of file handles.
     *
     * @return resource
     */
    private function open(string $path)
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            $reference = (string) Str::uuid();

            Log::channel('server-ops')->error('log could not be opened', [
                'feature' => 'log',
                'op' => 'open',
                'path' => $path,
                'reference' => $reference,
                // Almost always a file rotated away between the check and the
                // read, or one whose mode changed under us.
                'detail' => error_get_last()['message'] ?? 'fopen returned false',
            ]);

            throw new LogOperationException($reference);
        }

        return $handle;
    }

    /**
     * @return array<int, string>
     */
    private function split(string $content): array
    {
        $content = rtrim(str_replace("\r\n", "\n", $content), "\n");

        return $content === '' ? [] : explode("\n", $content);
    }
}
