<?php

namespace App\Services\Server\Applications;

use App\Enums\WafMode;
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
     * @return array{lines: array<int, string>, truncated: bool}|null
     */
    public function read(Application $application, string $key, int $lines, ?string $filter = null): ?array
    {
        $source = $this->find($application, $key);

        if ($source === null) {
            return null;
        }

        $lines = max(1, min($lines, self::MAX_LINES));

        // Read more than asked for when filtering, or a filter over the last
        // 200 lines finds nothing on a busy site and the screen looks broken
        // rather than merely empty.
        $window = $filter !== null && $filter !== '' ? self::MAX_LINES : $lines;

        $raw = $source['kind'] === 'journal'
            ? $this->readJournal($application, $window)
            : $this->readFile($application, $source['path'], $window);

        if ($raw === null) {
            return null;
        }

        $all = $this->split($raw);

        if ($filter !== null && $filter !== '') {
            // Literal, case-insensitive. Not a regex: a user-supplied pattern
            // over a large file is a denial of service waiting to happen, and
            // nobody searching a log wants regex semantics by surprise.
            $all = array_values(array_filter($all, fn (string $line): bool => stripos($line, $filter) !== false));
        }

        $truncated = count($all) > $lines;

        return [
            'lines' => $truncated ? array_slice($all, -$lines) : $all,
            'truncated' => $truncated,
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
        // Only in detect mode, because only detect mode writes it — an
        // enforcing site returns 403 and logs nothing here, so listing it
        // there would show an empty file that reads as broken rather than as
        // "this mode does not produce one".
        if ($application->waf_enabled && $application->waf_mode === WafMode::Detect) {
            $catalog[] = [
                'key' => 'waf_detect',
                'kind' => 'file',
                // Inside `.panel/`, which every vhost template already denies
                // over HTTP — nothing new has to keep it unreadable.
                'path' => $this->provisioner->documentRoot($application).'/.panel/waf-detect.log',
            ];
        }

        return $catalog;
    }

    private function readFile(Application $application, string $path, int $lines): ?string
    {
        $result = $this->serverOps->run(
            ['tail', '-n', (string) $lines, $path],
            $this->context($application, 'app_log_read'),
            timeout: 30,
        );

        return $result->failed() ? null : $result->output();
    }

    private function readJournal(Application $application, int $lines): ?string
    {
        $result = $this->serverOps->run(
            ['journalctl', '-u', $this->processes->unit($application), '-n', (string) $lines, '--no-pager'],
            $this->context($application, 'app_log_journal'),
            timeout: 30,
        );

        return $result->failed() ? null : $result->output();
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
