<?php

namespace App\Services\Server;

use App\Contracts\PhpStack;
use App\Exceptions\Server\Log\LogOperationException;
use App\Models\Cronjob;
use App\Models\Worker;
use App\Services\Server\Applications\WorkerSupervisor;
use App\Support\ListSort;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Server log files: read, and — for the sources that opt in — empty.
 *
 * `clearable` is opt-in per source. System logs (auth, ufw, fail2ban, syslog, kern, mail, Let's
 * Encrypt) are also `sensitive`: the screen then asks for a confirmation that names what is being
 * destroyed. The journal is never clearable because it is not a file.
 *
 * The catalog is the configured source registry plus one log per PHP version, PostgreSQL cluster,
 * cron job (the only part that reads the DB) and worker. Everything is filtered at read time to
 * files that actually exist.
 *
 * Callers reference a source by its `key`; the real path comes from the registry, so a
 * client-supplied path is never read (no traversal). Reads are native filesystem operations, not
 * shelled commands, so there is no injection surface.
 */
class LogManager
{
    public const DEFAULT_LINES = 200;

    public const MAX_LINES = 5000;

    // Caps an incremental poll's byte window so a busy log cannot exhaust an FPM worker's memory.
    public const MAX_INCREMENTAL_BYTES = 1048576;

    /** Lines pulled through a privileged read before filtering in PHP. */
    private const PRIVILEGED_WINDOW = 5000;

    private const PRESENT = 'present';

    private const ABSENT = 'absent';

    /**
     * Asked, and not answered (a refusal or a timeout, never "no").
     *
     * Kept apart from ABSENT: only ABSENT is a reason to hide the source.
     */
    private const UNKNOWN = 'unknown';

    public function __construct(
        private PhpStack $stack,
        private ServerOps $serverOps,
        // For the worker log path only. Nothing it depends on reaches back here (no container cycle).
        private WorkerSupervisor $workers,
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
    /**
     * Empty one log, when the source allows it.
     *
     * Truncated, never deleted: an unlinked open log frees no space and leaves the writer appending
     * to a file nobody can read. `truncate -s 0` keeps the inode, owner and mode (as the Disk
     * Cleaner's ServiceLogsTarget does).
     *
     * Returns false (404-shaped, not 403) for a source that is not `clearable` and for the journal,
     * which is not a file: emptying it means `journalctl --vacuum` on the host's whole journal.
     *
     * @throws LogOperationException
     */
    public function clear(string $key): bool
    {
        $source = $this->find($key);

        if ($source === null
            || ($source['clearable'] ?? false) !== true
            || ($source['kind'] ?? 'file') === 'journal') {
            return false;
        }

        $result = $this->serverOps->run(
            ['truncate', '-s', '0', $source['path']],
            ['feature' => 'log', 'op' => 'clear', 'source' => $key],
            timeout: 30,
        );

        if ($result->failed()) {
            throw new LogOperationException($result->reference);
        }

        return true;
    }

    public function describe(array $source): ?array
    {
        $kind = $source['kind'] ?? 'file';
        $presence = $this->presence($source);

        // Only ABSENT hides a source. Hiding an "unknown" one would show an empty Logs screen on a
        // server with an out-of-date sudo grant.
        if ($presence === self::ABSENT) {
            return null;
        }

        $stat = $kind === 'file' && $presence === self::PRESENT
            ? ['size' => (int) (filesize($source['path']) ?: 0), 'modified' => filemtime($source['path']) ?: 0]
            : $this->remoteStat($source);

        return [
            'key' => $source['key'],
            'label' => $source['label'],
            'group' => $source['group'],
            'kind' => $kind,
            // Whether the panel will empty this one; never the journal, which has nothing to truncate.
            'clearable' => ($source['clearable'] ?? false) === true && $kind !== 'journal',
            // Clearable, but a record of what happened to the machine (auth.log, ufw.log, ...). Lets
            // the confirmation name what is destroyed. The registry decides, not the screen.
            'clear_sensitive' => ($source['sensitive'] ?? false) === true,
            'size' => $stat['size'],
            'modified' => $stat['modified'] === null ? null : date('d-m-Y H:i:s', $stat['modified']),
            // A privileged source is read through sudo, so the question is whether sudo will run the
            // reader, not whether this process can open the file (it never can).
            'readable' => match (true) {
                // Asked and not answered: show the source greyed with a reason.
                $presence === self::UNKNOWN => false,
                $kind === 'file' => is_readable($source['path']),
                $kind === 'journal' => true,
                default => $this->permitted('tail'),
            },
            // Following by byte offset needs bytes and a stable file; the journal and privileged files
            // re-tail instead.
            'follow' => $kind === 'file',
            // Nothing to stream: there is no handle to open, and sudo would hold a worker for the
            // whole transfer.
            'downloadable' => $kind === 'file',
        ];
    }

    /**
     * Whether this source has anything to show, asked the way its kind allows.
     *
     * @param  array<string, mixed>  $source
     */
    private function presence(array $source): string
    {
        $kind = $source['kind'] ?? 'file';

        if ($kind === 'file') {
            return is_file($source['path']) ? self::PRESENT : self::ABSENT;
        }

        $result = $kind === 'journal'
            // One journalctl line is the cheapest check that it is installed and readable.
            ? $this->serverOps->probe(
                $this->journalCommand($source, 1),
                ['feature' => 'log', 'op' => 'exists', 'source' => $source['key']],
                timeout: 15,
            )
            // `probe`, not `run`: exit 1 means "no such log", an ordinary answer, not a failed
            // operation.
            : $this->serverOps->probe(
                ['test', '-f', $source['path']],
                ['feature' => 'log', 'op' => 'exists', 'source' => $source['key']],
                timeout: 15,
            );

        // journalctl answers an empty match with exit 0 and `-- No entries --`, so a per-program
        // journal source exists only once the program has logged.
        if ($kind === 'journal' && ($source['hide_when_empty'] ?? false) === true && $result->ok) {
            $output = trim($result->output());

            return $output === '' || str_contains($output, '-- No entries --') ? self::ABSENT : self::PRESENT;
        }

        return match (true) {
            $result->ok => self::PRESENT,
            // Ran and said no. A refusal or timeout never asked the question.
            $result->answered => self::ABSENT,
            default => self::UNKNOWN,
        };
    }

    /**
     * Size and mtime for a source the panel cannot stat itself.
     *
     * @param  array<string, mixed>  $source
     * @return array{size: int|null, modified: int|null}
     */
    /**
     * May the panel run this binary at all? Memoised per binary.
     *
     * `list()` describes every source in one request; asking sudo per cron job would cost a
     * process each.
     *
     * @var array<string, bool>
     */
    private array $permitted = [];

    private function permitted(string $binary): bool
    {
        return $this->permitted[$binary] ??= $this->serverOps->probe(
            ['sudo', '-n', '-l', $binary],
            ['feature' => 'log', 'op' => 'permitted'],
            timeout: 10,
        )->ok;
    }

    /**
     * `journalctl` for a source, narrowed to its identifier when it has one.
     *
     * The System Journal source has none and reads the whole box; without `-t` a worker's log
     * would be every unit's output interleaved.
     *
     * @param  array<string, mixed>  $source
     * @return array<int, string>
     */
    private function journalCommand(array $source, int $lines): array
    {
        $command = ['journalctl', '-n', (string) $lines, '--no-pager'];

        $identifier = $source['identifier'] ?? null;

        if (is_string($identifier) && $identifier !== '') {
            // Its own argv element, never interpolated.
            $command[] = '-t';
            $command[] = $identifier;
        }

        return $command;
    }

    private function remoteStat(array $source): array
    {
        if (($source['kind'] ?? 'file') === 'journal') {
            // The journal has no single size or mtime. Null, not a zero that reads as an empty log.
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
     * @return array{lines: array<int, string>, cursor: int, truncated: bool, search_window_capped: bool}|null
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

            // The whole file is searched, never a window of it.
            return ['lines' => $match['lines'], 'cursor' => $size, 'truncated' => $match['truncated'], 'search_window_capped' => false];
        }

        // Incremental follow: bytes appended since `after`. If the file is now
        // smaller than the cursor it was rotated/truncated → fall back to tail.
        if ($after !== null && $after <= $size) {
            $result = $this->range($path, $after);

            return ['lines' => $result['lines'], 'cursor' => $size, 'truncated' => $result['truncated'], 'search_window_capped' => false];
        }

        // Initial load (or post-rotation): last N lines.
        $tail = $this->tail($path, $lines);

        return ['lines' => $tail['lines'], 'cursor' => $size, 'truncated' => $tail['truncated'], 'search_window_capped' => false];
    }

    /**
     * A source the panel cannot open itself: read a bounded window through ServerOps, filter here.
     *
     * Filtered here rather than by `grep`: `grep -m` counts matches from the start of the file while
     * a log viewer wants the last ones, and an unbounded grep returns everything it finds.
     * `cursor` is null: there are no byte offsets to come back with.
     *
     * @param  array<string, mixed>  $source
     * @return array{lines: array<int, string>, cursor: int|null, truncated: bool}|null
     */
    private function readPrivileged(array $source, int $lines, ?string $filter): ?array
    {
        $lines = max(1, min($lines, self::MAX_LINES));
        // One line more than asked for: the only way to know there was more above (`truncated`).
        $window = $filter !== null && $filter !== '' ? self::PRIVILEGED_WINDOW : $lines + 1;

        $command = ($source['kind'] ?? 'file') === 'journal'
            ? $this->journalCommand($source, $window)
            : ['tail', '-n', (string) $window, $source['path']];

        $result = $this->serverOps->run(
            $command,
            ['feature' => 'log', 'op' => 'read', 'source' => $source['key']],
            timeout: 30,
        );

        if ($result->failed()) {
            // Refused and broken are different answers: a grant that predates this binary is a known
            // state, and a 500 would make one unreadable source look like a crashed page. Ask sudo
            // rather than match its (translated) refusal text.
            if (! $this->permitted($command[0])) {
                throw LogOperationException::refused();
            }

            // A read that did not happen is not an empty log.
            throw new LogOperationException($result->reference);
        }

        $all = $this->split($result->output());
        $filtering = $filter !== null && $filter !== '';
        // A search covers only the last PRIVILEGED_WINDOW lines. A full window means "no results" is
        // "not in the last N lines", so the screen must be able to say so (same field as app logs).
        $capped = $filtering && count($all) >= $window;

        if ($filtering) {
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
            'search_window_capped' => $capped,
        ];
    }

    /**
     * Configured sources + php-fpm logs detected per installed version.
     *
     * @return array<int, array{key: string, label: string, group: string, path: string}>
     */
    private function catalog(): array
    {
        return array_merge(
            array_map(fn (array $source) => $this->withReadAccess($source), (array) config('server.logs', [])),
            $this->phpFpmLogs(),
            $this->postgresLogs(),
            $this->cronjobLogs(),
            $this->workerLogs(),
        );
    }

    /**
     * Read through sudo what the panel cannot read itself.
     *
     * Also covers a file inside a directory the panel cannot enter (e.g. /var/log/mongodb, 0750),
     * where the file cannot even be seen to exist. A source with an explicit kind is left as it is.
     *
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    private function withReadAccess(array $source): array
    {
        $path = (string) ($source['path'] ?? '');

        if (isset($source['kind']) || $path === '') {
            return $source;
        }

        $dir = dirname($path);
        // A directory that exists but cannot be entered hides whether the file is there; a missing
        // one means nothing is installed.
        $unreadable = file_exists($path)
            ? ! is_readable($path)
            : is_dir($dir) && ! is_executable($dir);

        return $unreadable ? $source + ['kind' => 'privileged'] : $source;
    }

    /**
     * One source per cron job that has captured output.
     *
     * Built from the cronjobs table, not a glob, so every source maps to a job that exists and the
     * label is the job's name.
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
                // One job's own output; emptying it loses nothing the machine is the record of.
                'clearable' => true,
                // Read through sudo, unlike every other source here: the log is chowned to the job's
                // user and its own group, so neither the panel nor its `adm` group can open it. The
                // mode stays 0640 (job output can contain anything) rather than being widened.
                'kind' => 'privileged',
            ])
            ->all();
    }

    /**
     * One source per worker, read from the file supervisord writes.
     *
     * Not journald: Supervisor sets `stdout_logfile`, so `journalctl -t sv-worker-...` returns zero
     * lines rather than an error. The path comes from `WorkerSupervisor::logFile()` so the reader
     * and the writer cannot disagree.
     *
     * `privileged`, like a cron log: `{appRoot}/logs` is `root:{site user} 0750`, so it is read
     * through sudo `tail` (no `follow` or `downloadable`).
     *
     * @return array<int, array{key: string, label: string, group: string, path: string, kind: string}>
     */
    private function workerLogs(): array
    {
        return ListSort::caseInsensitive(
            // `application.systemUser`: the log path is built from the site's directory, which is
            // named for its account.
            Worker::query()->with('application.systemUser'),
            'name',
        )->get()
            ->filter(fn (Worker $worker) => $worker->application?->systemUser !== null)
            ->map(fn (Worker $worker) => [
                'key' => 'sv-worker-'.$worker->slug,
                'label' => trim(($worker->application?->name ? $worker->application->name.' — ' : '').$worker->name),
                'group' => 'worker',
                'path' => $this->workers->logFile($worker),
                'clearable' => true,
                'kind' => 'privileged',
            ])
            ->values()
            ->all();
    }

    /**
     * The keys of the PostgreSQL cluster logs on this server, for the
     * Services row to link to.
     *
     * @return array<int, string>
     */
    public function postgresKeys(): array
    {
        return array_column($this->postgresLogs(), 'key');
    }

    /**
     * One log per PostgreSQL cluster.
     *
     * Debian names the file after version and cluster (`postgresql-18-main.log`), so no fixed
     * registry path fits. The files are `postgres:adm 0640` and the panel is in `adm`.
     *
     * @return array<int, array{key: string, label: string, group: string, path: string, clearable: bool}>
     */
    private function postgresLogs(): array
    {
        $dir = rtrim((string) config('server.postgres_log_dir', '/var/log/postgresql'), '/');
        $logs = [];

        foreach (glob($dir.'/postgresql-*-*.log') ?: [] as $path) {
            if (preg_match('/^postgresql-(\d+(?:\.\d+)?)-([A-Za-z0-9_]+)\.log$/', basename($path), $m) !== 1) {
                continue;
            }

            $logs[] = [
                'key' => "postgresql_{$m[1]}_{$m[2]}",
                'label' => "PostgreSQL {$m[1]} ({$m[2]})",
                'group' => 'database',
                'path' => $path,
                'clearable' => true,
            ];
        }

        return $logs;
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
                // php-fpm creates its log root:root 0600 (logrotate keeps it so), so the `adm` group
                // opens nothing. Such a file is read through sudo `tail`; one the panel can open stays
                // a plain file, keeping follow/download.
                'kind' => file_exists($path) && ! is_readable($path) ? 'privileged' : 'file',
                'path' => $path,
                'clearable' => true,
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

        // Also stop at the incremental path's byte ceiling: a log with few line breaks (one large
        // blob) never satisfies the newline count, and the buffer would grow to the whole file.
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

        // The window may begin mid-line; half a line would read as an invented entry.
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
     * An unopenable file must not read as an empty log. A log is a moving target: logrotate renames
     * it mid-request, modes change, file handles run out.
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
                // Usually a file rotated away between the check and the read, or whose mode changed.
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
