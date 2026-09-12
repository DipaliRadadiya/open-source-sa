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
 * This was read-only. `clearable` is opt-in per source and now covers every
 * file-based source, including the ones that record what happened to the machine
 * — auth.log, ufw.log, fail2ban.log, syslog, kern.log, mail.log and the Let's
 * Encrypt log. Those carry `sensitive` as well, which the screen turns into a
 * confirmation that names what is being destroyed: they are the files an
 * investigation needs and the first ones an intruder would erase, so the answer
 * is informed consent rather than a silent button. Operator's decision, taken
 * after the alternative was argued.
 *
 * The journal is the one thing still refused, and for a reason that is not
 * policy: it is not a file, so there is nothing to truncate.
 *
 * The catalog is the configured source
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

    private const PRESENT = 'present';

    private const ABSENT = 'absent';

    /**
     * Asked, and not answered.
     *
     * A refusal or a timeout, never "no". Kept apart from ABSENT because the
     * two lead the reader to opposite conclusions: one says this server has no
     * such log, the other says this panel could not look — and only the first
     * is a reason to hide the source.
     */
    private const UNKNOWN = 'unknown';

    public function __construct(
        private PhpStack $stack,
        private ServerOps $serverOps,
        // For the worker log path only. Its own dependencies are ServerOps,
        // ManagedFile and FrameworkDetector — none of which reach back here,
        // so this cannot close a container cycle.
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
     * **Truncated, never deleted.** An open log that is unlinked keeps its disk
     * space until the writer restarts and breaks its own handle — `rm` would
     * free nothing and leave nginx, MySQL or the cron wrapper appending to a
     * file no screen can read. `truncate -s 0` reclaims the space with the
     * writer attached and keeps the inode's owner and mode, which the service
     * will not restore on its own. Same mechanism, and the same reason, as the
     * Disk Cleaner's ServiceLogsTarget.
     *
     * Two refusals, both 404-shaped to the caller rather than 403: a source
     * that is not `clearable`, and the journal, which is not a file at all —
     * emptying that means `journalctl --vacuum` against the host's whole
     * journal, which is a different operation with a different blast radius.
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

        // Absent is the only reason to hide a source. "I could not find out"
        // is not the same answer, and dropping it silently is how a server
        // with an out-of-date sudo grant shows an empty Logs screen and lets
        // the user conclude their cron jobs never wrote anything.
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
            // Whether the panel will empty this one. Opt-in per source; false
            // for the journal, which is not a file and so has nothing to
            // truncate — emptying that means `journalctl --vacuum` against the
            // host's whole journal, a different operation.
            'clearable' => ($source['clearable'] ?? false) === true && $kind !== 'journal',
            // Clearable, but it is the record of what happened to the machine:
            // auth.log, ufw.log, fail2ban.log, syslog, kern.log, mail.log, the
            // Let's Encrypt log. Reported so the confirmation can name what is
            // being destroyed rather than offering the same sentence it uses for
            // an access log. The screen must not infer this from the key — the
            // registry decides, and a second client would get the list wrong.
            'clear_sensitive' => ($source['sensitive'] ?? false) === true,
            'size' => $stat['size'],
            'modified' => $stat['modified'] === null ? null : date('d-m-Y H:i:s', $stat['modified']),
            // A privileged source is read through sudo, so "can the panel
            // account open it" is the wrong question — it never can, and it
            // does not need to.
            // For a privileged source the question is not whether this process
            // can open the file — it never can — but whether sudo will run the
            // reader. Hardcoding `true` here told the screen a source was
            // readable right up until opening it returned a 500.
            'readable' => match (true) {
                // Asked and not answered: the screen should show the source
                // greyed with a reason, not offer something that fails on
                // click and not pretend it does not exist.
                $presence === self::UNKNOWN => false,
                $kind === 'file' => is_readable($source['path']),
                $kind === 'journal' => true,
                default => $this->permitted('tail'),
            },
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
    private function presence(array $source): string
    {
        $kind = $source['kind'] ?? 'file';

        if ($kind === 'file') {
            return is_file($source['path']) ? self::PRESENT : self::ABSENT;
        }

        $result = $kind === 'journal'
            // Asking journalctl for one line is the cheapest way to learn both
            // that it is installed and that the panel may read it.
            ? $this->serverOps->probe(
                $this->journalCommand($source, 1),
                ['feature' => 'log', 'op' => 'exists', 'source' => $source['key']],
                timeout: 15,
            )
            // `probe`, not `run`: exit 1 here means "no such log", which is an
            // ordinary answer for a job that has not run yet — not a failed
            // server operation worth an error in the admin log.
            : $this->serverOps->probe(
                ['test', '-f', $source['path']],
                ['feature' => 'log', 'op' => 'exists', 'source' => $source['key']],
                timeout: 15,
            );

        return match (true) {
            $result->ok => self::PRESENT,
            // The command ran and said no. Anything else — a refusal, a
            // timeout — never asked the question.
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
     * May the panel run this binary at all?
     *
     * Memoised: `list()` describes every source in one request, and asking
     * sudo the same question per cron job would be a process each. Only ever
     * reached on a failure or when describing a privileged source, so a
     * healthy server pays for it once.
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
     * The System Journal source has none and keeps its existing behaviour —
     * the whole box, which is what that source is for. A worker has one, and
     * without `-t` its "logs" would be every unit's output interleaved.
     *
     * @param  array<string, mixed>  $source
     * @return array<int, string>
     */
    private function journalCommand(array $source, int $lines): array
    {
        $command = ['journalctl', '-n', (string) $lines, '--no-pager'];

        $identifier = $source['identifier'] ?? null;

        if (is_string($identifier) && $identifier !== '') {
            // Passed as its own argv element, never interpolated — the value
            // is ours (`sv-worker-{slug}`), and keeping it an argument means it
            // stays ours even if that ever stops being true.
            $command[] = '-t';
            $command[] = $identifier;
        }

        return $command;
    }

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
            ? $this->journalCommand($source, $window)
            : ['tail', '-n', (string) $window, $source['path']];

        $result = $this->serverOps->run(
            $command,
            ['feature' => 'log', 'op' => 'read', 'source' => $source['key']],
            timeout: 30,
        );

        if ($result->failed()) {
            // Refused and broken are different answers, and only one of them
            // is worth a support reference. A grant that predates the binary
            // this needs is a known state with a known remedy, and answering
            // it with a 500 turns one unreadable source into a page that looks
            // crashed. Asked of sudo rather than by matching its refusal
            // prose, which differs per refusal kind and is translated.
            if (! $this->permitted($command[0])) {
                throw LogOperationException::refused();
            }

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
        return array_merge(
            config('server.logs', []),
            $this->phpFpmLogs(),
            $this->cronjobLogs(),
            $this->workerLogs(),
        );
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
                // One job's own output. Emptying it loses nothing the machine
                // is the record of.
                'clearable' => true,
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
     * One source per worker, read from the file supervisord writes.
     *
     * 🔴 **This was `kind: journal` and had to change with the worker itself.**
     * A systemd unit sent stdout and stderr to journald under
     * `sv-worker-{slug}`, so the source read `journalctl -t` that identifier.
     * Supervisor does not use journald at all — the program block sets
     * `stdout_logfile` (with `redirect_stderr`) and everything goes to a file.
     * Migrating the writer without migrating the reader left every worker's
     * log source permanently empty: `journalctl -t sv-worker-…` on a box where
     * nothing writes under that identifier is not an error, it is zero lines.
     *
     * The path comes from `WorkerSupervisor::logFile()` rather than being
     * rebuilt here, because the reader and the writer disagreeing is the bug
     * this comment exists about.
     *
     * `privileged`, like a cron log and unlike the rest of this catalog: the
     * file sits in `{appRoot}/logs`, which `ApplicationLogDirectory` makes
     * `root:{site user} 0750` on purpose, and the panel account is neither. So
     * it is read through sudo `tail`. That costs `follow` and `downloadable`,
     * which a journal source did not have either.
     *
     * Built from the table for the same reason cron jobs are: a source then
     * always maps to a worker that exists, and the label is the worker's name
     * rather than an identifier to decode.
     *
     * @return array<int, array{key: string, label: string, group: string, path: string, kind: string}>
     */
    private function workerLogs(): array
    {
        return ListSort::caseInsensitive(
            // `application.systemUser` because the log path is built from the
            // site's own directory, which is named for its account.
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
