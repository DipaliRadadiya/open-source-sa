<?php

namespace App\Services\Server\Applications;

use App\Exceptions\Server\Log\LogOperationException;
use App\Models\Application;
use App\Services\Server\ServerOps;
use App\Services\Server\WebServers\WebServerManager;

/**
 * One application's own logs.
 *
 * Deliberately separate from the server-wide LogManager, and not a filter over
 * it. That one answers "what is happening on this machine" from a fixed
 * registry of paths under /var/log, read with native filesystem calls. This
 * one answers "what is happening to my site", from paths derived per
 * application, some of which live inside the site's own directory and are
 * owned by its system user — which the panel account cannot open. Every read
 * here therefore goes through ServerOps.
 *
 * Sources are resolved server-side from a key. A client-supplied path is never
 * read, so there is no traversal surface.
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
    ) {}

    /**
     * The sources this application actually has, with live metadata.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(Application $application): array
    {
        return array_values(array_map(
            fn (array $source): array => [
                'key' => $source['key'],
                'label' => __('app_log.sources.'.$source['key']),
                'kind' => $source['kind'],
                'exists' => $source['kind'] === 'journal' || $this->fileExists($application, $source['path']),
            ],
            $this->catalog($application),
        ));
    }

    /**
     * Read one source: the last N lines, optionally filtered.
     *
     * @return array{lines: array<int, string>, truncated: bool, search_window_capped: bool}|null
     */
    public function read(Application $application, string $key, int $lines, ?string $filter = null): ?array
    {
        $source = $this->find($application, $key);

        if ($source === null) {
            return null;
        }

        $lines = max(1, min($lines, self::MAX_LINES));
        $filtering = $filter !== null && $filter !== '';

        // Read more than asked for when filtering, or a filter over the last
        // 200 lines finds nothing on a busy site and the screen looks broken
        // rather than merely empty.
        $window = $filtering ? self::MAX_LINES : $lines;

        // One line beyond the window, purely to find out whether there is
        // anything above it. `tail -n 200` can never return more than 200
        // lines, so comparing its count against 200 answered the same question
        // every time — `truncated` was structurally false, and the last 200
        // lines of a million-line access log were reported as the whole log.
        $raw = $source['kind'] === 'journal'
            ? $this->readJournal($application, $window + 1)
            : $this->readFile($application, $source['path'], $window + 1);

        if ($raw === null) {
            return null;
        }

        $all = $this->split($raw);

        // Whether the source holds more than we looked at. Established before
        // filtering, because it is a fact about the file rather than about the
        // search.
        $hasMore = count($all) > $window;

        if ($hasMore) {
            array_shift($all);
        }

        if ($filtering) {
            // Literal, case-insensitive. Not a regex: a user-supplied pattern
            // over a large file is a denial of service waiting to happen, and
            // nobody searching a log wants regex semantics by surprise.
            $all = array_values(array_filter($all, fn (string $line): bool => stripos($line, $filter) !== false));
        }

        $trimmed = count($all) > $lines;

        return [
            'lines' => $trimmed ? array_slice($all, -$lines) : $all,

            // "There is more log than you are seeing" — either because the
            // source runs on above the window, or because more lines matched
            // than were asked for.
            'truncated' => $hasMore || $trimmed,

            // The distinction that matters when a search comes back empty.
            // Filtering only ever covers the last MAX_LINES, so a match that
            // happened earlier is not found — and "no results" then reads as
            // "this is not in your log" when the truthful answer is "I only
            // looked at the last 5,000 lines". Two facts, two fields: one
            // boolean cannot say which of them happened.
            'search_window_capped' => $filtering && $hasMore,
        ];
    }

    /**
     * @return array{key: string, kind: string, path: string}|null
     */
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

        // For a Node application the web-server logs describe the *proxy* —
        // they will happily show a healthy 502 while the actual error is in
        // the journal. Without this source the screen would be confidently
        // useless at exactly the moment it is needed.
        if ($this->processes->runs($application)) {
            // Files in the site's own directory, not the journal: the unit
            // writes stdout and stderr there so the logs belong to the
            // application rather than to the host, and stderr is kept apart
            // because "what did it print" and "what went wrong" are different
            // questions and mixing them buries the second in the first.
            foreach (ProcessSupervisor::logFiles($application) as $key => $path) {
                $catalog[] = ['key' => $key, 'kind' => 'file', 'path' => $path];
            }
        }

        // The firewall's detect-mode log: the requests that *would* have been
        // blocked. Offering "just watch and see what happens" while giving the
        // user no way to see what happened made detect mode close to
        // pointless.
        //
        // Listed in BOTH modes, not just detect.
        //
        // It used to appear only while the mode was `detect`, on the reasoning
        // that an enforcing site writes nothing here so an empty file would
        // read as broken. That reasoning had it backwards: the intended flow is
        // detect → read this → add exceptions → enforce, so the log vanished
        // from the UI at the exact moment someone acted on it, and
        // `GET /logs/{key}` 404'd with it. Checking your work afterwards is not
        // an edge case, it is the point.
        //
        // The empty-file worry is already handled: `list()` reports `exists`
        // per source, so "enforcing, nothing recorded" and "detect, nothing
        // matched yet" are both visible as an existing key with no content —
        // which is a different statement from the key not being offered.
        if ($application->waf_enabled) {
            $catalog[] = [
                'key' => 'waf_detect',
                'kind' => 'file',
                // `panelPath()`, which is where the vhost is told to write it
                // (AbstractWebServerDriver::wafContext). This read the old
                // pre-relocation location under the document root, so the file
                // the panel opened was never the file nginx wrote — detect mode
                // showed an empty log no matter how much it had matched, which
                // reads as "nothing would be blocked" and invites someone to
                // enforce a ruleset they have not actually checked.
                'path' => $application->panelPath().'/waf-detect.log',
            ];
        }

        return $catalog;
    }

    /**
     * Null here means "this log has not been written yet", and nothing else.
     *
     * It used to mean that *and* every way the read could fail — `tail` denied,
     * the path unreadable, the command timing out — because the caller turned
     * any null into `exists: false`. The screen then said the site had no logs
     * at the moment someone opened it to find out why the site was down, which
     * sends them to look somewhere else. `fileExists` already knows which of
     * the two it is; this asks it.
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
     * The journal has no equivalent of "not written yet" — a unit with no
     * output answers with an empty result, not a failure. So anything that
     * fails here really did fail: journalctl missing, the unit name wrong, the
     * panel not permitted to read it.
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
        return $this->serverOps->run(
            ['test', '-f', $path],
            $this->context($application, 'app_log_exists'),
            timeout: 15,
        )->ok;
    }

    /**
     * @return array<int, string>
     */
    private function split(string $content): array
    {
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
}
