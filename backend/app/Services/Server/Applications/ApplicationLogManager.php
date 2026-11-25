<?php

namespace App\Services\Server\Applications;

use App\Exceptions\Server\Log\LogOperationException;
use App\Models\Application;
use App\Services\Server\ServerOps;
use App\Services\Server\WebServers\WebServerManager;

/**
 * One application's own logs.
 *
 * Separate from the server-wide LogManager (a fixed registry of /var/log paths read natively):
 * these paths are derived per application, some inside the site's own directory and owned by
 * its system user, which the panel account cannot open. Every read goes through ServerOps.
 *
 * Sources are resolved server-side from a key. A client-supplied path is never read, so
 * there is no traversal surface.
 */
class ApplicationLogManager
{
    public const DEFAULT_LINES = 200;

    public const MAX_LINES = 5000;

    public function __construct(
        private ServerOps $serverOps,
        private WebServerManager $webServers,
        private ProcessSupervisor $processes,
        private ApplicationProvisioner $provisioner,
        private ContainerSupervisor $containers,
    ) {}

    /**
     * The sources this application actually has, with live metadata.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(Application $application): array
    {
        return array_values(array_map(
            function (array $source) use ($application): array {
                // A container's log is a command, not a file: nothing on disk to stat.
                $file = ! in_array($source['kind'], ['journal', 'container'], true);
                $exists = ! $file || $this->fileExists($application, $source['path']);

                return [
                    'key' => $source['key'],
                    'label' => __('app_log.sources.'.$source['key']),
                    'kind' => $source['kind'],
                    'exists' => $exists,
                ] + ($file && $exists
                    ? $this->stat($application, $source['path'])
                    // Null, not 0: 0 would read as an empty log.
                    : ['size' => null, 'modified' => null]);
            },
            $this->catalog($application),
        ));
    }

    /**
     * Size and last write, as LogManager::remoteStat reports them. A failed stat is unknown, not zero.
     *
     * @return array{size: int|null, modified: string|null}
     */
    private function stat(Application $application, string $path): array
    {
        $result = $this->serverOps->run(
            ['stat', '-c', '%s %Y', '--', $path],
            $this->context($application, 'app_log_stat'),
            timeout: 15,
        );

        $parts = $result->failed() ? [] : (preg_split('/\s+/', trim($result->output())) ?: []);
        $size = isset($parts[0]) && ctype_digit($parts[0]) ? (int) $parts[0] : null;
        $modified = isset($parts[1]) && ctype_digit($parts[1]) ? (int) $parts[1] : null;

        return [
            'size' => $size,
            'modified' => $modified === null ? null : date('d-m-Y H:i:s', $modified),
        ];
    }

    /**
     * Read one source: the last N lines, or N lines from line `from` on, optionally filtered by
     * text, and on an access log by IP, status, method and URL.
     *
     * @param  array<string, string|null>  $fields  ip, status, method, url
     * @return array{lines: array<int, string>, first_line: int|null, truncated: bool, search_window_capped: bool}|null
     */
    public function read(Application $application, string $key, int $lines, ?string $filter = null, ?int $from = null, array $fields = []): ?array
    {
        $source = $this->find($application, $key);

        if ($source === null) {
            return null;
        }

        $lines = max(1, min($lines, self::MAX_LINES));
        $marker = $source['marker'] ?? null;
        $fields = array_filter($fields, fn ($value) => $value !== null && $value !== '');
        $filtering = ($filter !== null && $filter !== '') || $marker !== null || $fields !== [];

        // Read more than asked for when filtering, or a filter over the last 200 lines finds
        // nothing on a busy site.
        $window = $filtering ? self::MAX_LINES : $lines;

        if ($from !== null) {
            return $this->readFrom($application, $source, $from, $lines, $window, $filter, $marker, $fields);
        }

        // One line beyond the window, to find out whether there is anything above it (without
        // it `truncated` would always be false).
        $raw = match ($source['kind']) {
            'journal' => $this->readJournal($application, $window + 1),
            'container' => $this->containers->logs(
                $application,
                $this->provisioner->documentRoot($application),
                $window + 1,
            ),
            default => $this->readFile($application, $source['path'], $window + 1),
        };

        if ($raw === null) {
            return null;
        }

        $all = $this->split($raw);

        // Whether the source holds more than we looked at; a fact about the file, so set before filtering.
        $hasMore = count($all) > $window;

        if ($hasMore) {
            array_shift($all);
        }

        $all = $this->applyFilters($all, $filter, $marker, $fields);

        $trimmed = count($all) > $lines;

        return [
            'lines' => $trimmed ? array_slice($all, -$lines) : $all,

            // Line numbers are known only for a read that started at one.
            'first_line' => null,

            // More log exists than is shown: above the window, or more lines matched than asked for.
            'truncated' => $hasMore || $trimmed,

            // Filtering only covers the last MAX_LINES, so an empty result may just mean the match
            // is older. Separate from `truncated`: one boolean cannot say which happened.
            'search_window_capped' => $filtering && $hasMore,
        ];
    }

    /**
     * From line N onwards, the same window as a tail read: `lines` lines, or the first
     * MAX_LINES when filtering.
     *
     * Streamed with `tail -n +N` and abandoned once the window is full, so a range near the top
     * of a gigabyte log does not read or hold the rest. `tail` is already on the privilege list.
     *
     * Files only: the journal and Docker have no stable line numbers (the caller refuses those).
     *
     * @param  array<string, string>  $fields
     * @param  array{key: string, kind: string, path: string}  $source
     * @return array{lines: array<int, string>, first_line: int, truncated: bool, search_window_capped: bool}|null
     */
    private function readFrom(Application $application, array $source, int $from, int $lines, int $window, ?string $filter, ?string $marker, array $fields): ?array
    {
        $from = max(1, $from);
        $read = [];
        $buffer = '';
        $chunks = $this->serverOps->stream(['tail', '-n', '+'.$from, $source['path']], $this->context($application, 'app_log_range'), idleSeconds: 30);

        foreach ($chunks as $chunk) {
            $buffer .= $chunk;

            while (($newline = strpos($buffer, "\n")) !== false) {
                $read[] = substr($buffer, 0, $newline);
                $buffer = substr($buffer, $newline + 1);
            }

            // One beyond the window, to know whether the file runs on.
            if (count($read) > $window) {
                break;
            }
        }

        // Dropping the generator closes its pipe, and `tail` stops on its next write.
        unset($chunks);

        if ($buffer !== '' && count($read) <= $window) {
            $read[] = $buffer;
        }

        if ($read === [] && ! $this->fileExists($application, $source['path'])) {
            return null;
        }

        $hasMore = count($read) > $window;
        $all = $this->split(implode("\n", array_slice($read, 0, $window)));
        $filtering = ($filter !== null && $filter !== '') || $marker !== null || $fields !== [];
        $all = $this->applyFilters($all, $filter, $marker, $fields);
        $trimmed = count($all) > $lines;

        return [
            // Reading forwards: the start of the range is what was asked for.
            'lines' => $trimmed ? array_slice($all, 0, $lines) : $all,
            'first_line' => $from,
            'truncated' => $hasMore || $trimmed,
            'search_window_capped' => $filtering && $hasMore,
        ];
    }

    /**
     * @param  array<int, string>  $lines
     * @param  array<string, string>  $fields
     * @return array<int, string>
     */
    private function applyFilters(array $lines, ?string $filter, ?string $marker, array $fields): array
    {
        if ($marker !== null) {
            $lines = array_values(array_filter($lines, fn (string $line): bool => str_contains($line, $marker)));
        }

        if ($filter !== null && $filter !== '') {
            // Literal, case-insensitive. Never a regex: a user-supplied pattern over a large file is a DoS.
            $lines = array_values(array_filter($lines, fn (string $line): bool => stripos($line, $filter) !== false));
        }

        if ($fields !== []) {
            $lines = array_values(array_filter($lines, fn (string $line): bool => $this->matchesFields($line, $fields)));
        }

        return $lines;
    }

    /**
     * One access-log line against the field filters: client IP, status, method, URL.
     *
     * Parsed, not substring-matched ("404" would match a URL or byte count; an IP would match
     * longer IPs). A line not in the combined format nginx, Apache and OpenLiteSpeed write
     * does not match at all.
     *
     * @param  array<string, string>  $fields
     */
    private function matchesFields(string $line, array $fields): bool
    {
        if (preg_match('/^(\S+) \S+ \S+ \[[^\]]*\] "(\S+) (\S+)[^"]*" (\d{3}) /', $line, $m) !== 1) {
            return false;
        }

        [, $ip, $method, $url, $status] = $m;

        if (isset($fields['ip']) && $ip !== $fields['ip']) {
            return false;
        }

        if (isset($fields['method']) && strcasecmp($method, $fields['method']) !== 0) {
            return false;
        }

        if (isset($fields['url']) && stripos($url, $fields['url']) === false) {
            return false;
        }

        if (isset($fields['status'])) {
            $wanted = strtolower($fields['status']);

            // `404` exactly, or a class such as `5xx`.
            $matches = str_ends_with($wanted, 'xx') ? $status[0] === $wanted[0] : $status === $wanted;

            if (! $matches) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a source is an access log in the combined format, which the field filters need.
     * OpenLiteSpeed's firewall log is its access log with a marker, so it qualifies; nginx and
     * Apache's are ModSecurity's own format.
     */
    public function supportsFieldFilters(Application $application, string $key): bool
    {
        $source = $this->find($application, $key);

        return $source !== null && $source['kind'] === 'file'
            && ($key === 'access' || ($source['marker'] ?? null) !== null);
    }

    /**
     * @return array{key: string, kind: string, path: string}|null
     */
    /**
     * The whole log, streamed. Null when the source has nothing on disk yet; `false` for a
     * source with no file to hand over (a container's output lives in Docker).
     *
     * Streamed through ServerOps: a log can be gigabytes, so it is never read into memory.
     *
     * @return array{filename: string, chunks: \Generator<int, string>}|false|null
     */
    public function download(Application $application, string $key): array|false|null
    {
        $source = $this->find($application, $key);

        if ($source === null || $source['kind'] === 'container') {
            return $source === null ? null : false;
        }

        $filename = "{$application->slug}-{$key}.log";

        if ($source['kind'] === 'journal') {
            return ['filename' => $filename, 'chunks' => $this->serverOps->stream(
                ['journalctl', '-u', $this->processes->unit($application), '--no-pager'],
                $this->context($application, 'app_log_download'),
            )];
        }

        if (! $this->fileExists($application, $source['path'])) {
            return null;
        }

        return ['filename' => $filename, 'chunks' => $this->serverOps->stream(
            ['cat', $source['path']],
            $this->context($application, 'app_log_download'),
        )];
    }

    public function find(Application $application, string $key): ?array
    {
        foreach ($this->catalog($application) as $source) {
            if ($source['key'] === $key) {
                return $source;
            }
        }

        return null;
    }

    /**
     * What this application has, which depends on its web server and whether
     * it runs a process.
     *
     * @return array<int, array{key: string, kind: string, path: string}>
     */
    private function catalog(Application $application): array
    {
        $paths = $this->webServers->driver()->logPaths($application);

        $catalog = [
            ['key' => 'access', 'kind' => 'file', 'path' => $paths['access']],
            ['key' => 'error', 'kind' => 'file', 'path' => $paths['error']],
        ];

        // The HTTPS block's own files, where the web server writes them.
        foreach (['access_ssl', 'error_ssl'] as $key) {
            if (isset($paths[$key])) {
                $catalog[] = ['key' => $key, 'kind' => 'file', 'path' => $paths[$key]];
            }
        }

        // For a Node application the web-server logs only describe the *proxy* (a healthy 502
        // hides the real error).
        if ($this->processes->runs($application)) {
            // Files in the site's own directory, not the journal; stderr is kept apart from stdout.
            foreach (ProcessSupervisor::logFiles($application) as $key => $path) {
                $catalog[] = ['key' => $key, 'kind' => 'file', 'path' => $path];
            }
        }

        // A container writes to Docker's logging driver, not to a site file. Same reasoning as Node:
        // web-server logs only show the proxy's 502, not the container that exited on startup.
        if ($application->serving_profile === 'docker') {
            $catalog[] = ['key' => 'container', 'kind' => 'container', 'path' => ''];
        }

        // The firewall's detect-mode log: the requests that *would* have been blocked.
        // Listed in BOTH modes, not just detect: the flow is detect → read this → add
        // exceptions → enforce, and the log must not vanish when someone acts on it.
        if ($application->waf_enabled) {
            $catalog[] = [
                'key' => 'waf_detect',
                'kind' => 'file',
                // Where the vhost is told to write it (AbstractWebServerDriver::wafContext).
                // A wrong path shows an empty log, which reads as "nothing would be blocked".
                'path' => $application->wafDetectLogPath(),
            ];

            // OpenLiteSpeed allows one access log per site, so detect mode marks its lines there
            // (`waf=1`, see the OLS vhost templates). Same key: the access log, marked lines only.
            if ($this->webServers->driver()->name() === 'openlitespeed') {
                $catalog[array_key_last($catalog)] = [
                    'key' => 'waf_detect',
                    'kind' => 'file',
                    'path' => $paths['access'],
                    'marker' => ' waf=1',
                ];
            }
        }

        return $catalog;
    }

    /**
     * Null here means "this log has not been written yet", and nothing else. Any other failure
     * (denied, unreadable, timeout) throws, so the screen never claims a site has no logs.
     */
    private function readFile(Application $application, string $path, int $lines): ?string
    {
        $result = $this->serverOps->run(
            ['tail', '-n', (string) $lines, $path],
            $this->context($application, 'app_log_read'),
            timeout: 30,
        );

        if (! $result->failed()) {
            return $result->output();
        }

        // A site nobody has visited has no access log. That is ordinary.
        if (! $this->fileExists($application, $path)) {
            return null;
        }

        throw new LogOperationException($result->reference);
    }

    /**
     * The journal has no "not written yet": a unit with no output answers empty, so any failure
     * here is real (journalctl missing, wrong unit name, not permitted).
     */
    private function readJournal(Application $application, int $lines): ?string
    {
        $result = $this->serverOps->run(
            ['journalctl', '-u', $this->processes->unit($application), '-n', (string) $lines, '--no-pager'],
            $this->context($application, 'app_log_journal'),
            timeout: 30,
        );

        if ($result->failed()) {
            throw new LogOperationException($result->reference);
        }

        return $result->output();
    }

    private function fileExists(Application $application, string $path): bool
    {
        $result = $this->serverOps->probe(
            ['test', '-f', $path],
            $this->context($application, 'app_log_exists'),
            timeout: 15,
        );

        // "No log file" and "not allowed to look" are different answers; never report the second as the first.
        if (! $result->answered) {
            throw new LogOperationException($result->reference);
        }

        return $result->ok;
    }

    /**
     * Lines, without terminal colour codes (Node apps colour their output). Stripped before
     * filtering, so a search matches what the user sees.
     *
     * @return array<int, string>
     */
    private function split(string $content): array
    {
        $content = preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $content) ?? $content;
        $content = rtrim(str_replace("\r\n", "\n", $content), "\n");

        return $content === '' ? [] : explode("\n", $content);
    }

    /**
     * @return array<string, mixed>
     */
    private function context(Application $application, string $op): array
    {
        return ['feature' => 'application', 'op' => $op, 'application' => $application->id];
    }

    /**
     * Empty one of this application's logs.
     *
     * **Truncated, never deleted.** An unlinked open log keeps its disk space until the writer
     * restarts, and the writer keeps appending to a file no screen can read. `truncate -s 0`
     * frees the space with the writer attached and keeps the inode, owner and mode (the site
     * user's; the web server will not recreate them correctly).
     *
     * Only this application's own logs, resolved through the same catalog every read uses. The
     * server-wide logs (`auth.log`, `ufw.log`, `fail2ban.log`) deliberately have no
     * equivalent: a one-click wipe of those is an anti-forensics button.
     *
     * @throws LogOperationException
     */
    public function clear(Application $application, string $key): void
    {
        $source = $this->find($application, $key);

        // A marked source is a view onto another log: clearing it would wipe the whole access log.
        // The controller refuses it with a message.
        if ($source === null || ($source['kind'] ?? 'file') !== 'file' || isset($source['marker'])) {
            // The caller 404s on an unknown key. A non-file source is not clearable here
            // (`journalctl --vacuum` would act on the host's journal).
            throw new LogOperationException('');
        }

        $result = $this->serverOps->run(
            ['truncate', '-s', '0', $source['path']],
            $this->context($application, 'app_log_clear'),
            timeout: 30,
        );

        if ($result->failed()) {
            throw new LogOperationException($result->reference);
        }
    }
}
