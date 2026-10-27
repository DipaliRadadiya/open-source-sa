<?php

namespace App\Services\Server;

use App\Exceptions\Server\Cronjob\CronjobOperationException;
use App\Models\Cronjob;
use App\Support\ServerTimezone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A cron job's runs, read back out of its output log.
 *
 * The log is the record, not the database: cron runs a job as its own
 * account, which cannot write to the panel's database, and the wrapper
 * {@see CrontabManager::runLine()} writes already frames every run in it:
 *
 *     --- start pid=4711 at 2026-10-27T03:00:01+00:00
 *     …whatever the job printed…
 *     --- exit=0 pid=4711 at 2026-10-27T03:00:09+00:00
 *
 * Jobs written before the start line existed have only
 * `--- exit=N at <time>`; their runs are still found, without a start time.
 * History reaches as far back as the current log file — logrotate moves the
 * rest aside.
 */
class CronjobHistory
{
    private const TIME = '\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:[+-]\d{2}:?\d{2}|Z)';

    private const START = '/^--- start pid=(\d+)( manual)? at ('.self::TIME.')$/';

    private const EXIT = '/^--- exit=(\d+)(?: pid=(\d+))? at ('.self::TIME.')$/';

    public function __construct(
        private ServerOps $serverOps,
        private CrontabManager $crontab,
    ) {}

    /**
     * The job's latest runs, newest first, each with its output.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws CronjobOperationException
     */
    public function runs(Cronjob $cronjob, ?int $limit = null): array
    {
        if ($cronjob->logKey() === null) {
            return [];
        }

        $window = (int) config('server.cronjob_history_window_bytes', 1048576);

        $result = $this->serverOps->run(
            ['tail', '-c', (string) $window, '--', $this->crontab->logPath($cronjob)],
            ['feature' => 'cronjob', 'op' => 'read_history', 'cronjob' => $cronjob->id],
            timeout: 30,
        );

        if ($result->failed()) {
            throw new CronjobOperationException($result->reference, denied: $result->denied, step: 'read_history');
        }

        $runs = $this->parse($result->output(), strlen($result->output()) >= $window);

        return array_slice(array_reverse($runs), 0, $limit ?? (int) config('server.cronjob_history_runs', 20));
    }

    /**
     * The latest run of each job, keyed by job id — null for a job that has
     * not run. One privileged process for the whole page, not one per job.
     *
     * A read that fails answers null for every job rather than failing the
     * list: the list is how a user reaches the job, and "last run unknown" is
     * still true.
     *
     * @param  iterable<Cronjob>  $cronjobs
     * @return array<int, array<string, mixed>|null>
     */
    public function latest(iterable $cronjobs): array
    {
        $latest = [];
        $paths = [];

        foreach ($cronjobs as $cronjob) {
            $latest[$cronjob->id] = null;

            if ($cronjob->logKey() !== null) {
                $paths[$this->crontab->logPath($cronjob)] = $cronjob->id;
            }
        }

        if ($paths === []) {
            return $latest;
        }

        // Each file's tail behind a line nobody can predict. tail's own
        // `==> file <==` headers would do, except that a job can print one
        // and pass its output off as another account's job.
        $boundary = '--- panel-'.Str::random(32);
        $bytes = (string) (int) config('server.cronjob_latest_window_bytes', 16384);

        $result = $this->serverOps->run(
            [
                'sh', '-c',
                'b=$1; n=$2; shift 2; for f; do printf "\n%s %s %s\n" "$b" "$(stat -c %s -- "$f" 2>/dev/null || echo 0)" "$f"; tail -c "$n" -- "$f" 2>/dev/null; done; true',
                'sh', $boundary, $bytes, ...array_keys($paths),
            ],
            ['feature' => 'cronjob', 'op' => 'read_latest'],
            timeout: 30,
        );

        if ($result->failed()) {
            return $latest;
        }

        $sections = preg_split('/^'.preg_quote($boundary, '/').' /m', $result->output()) ?: [];

        foreach (array_slice($sections, 1) as $section) {
            [$header, $content] = array_pad(explode("\n", $section, 2), 2, '');
            [$size, $path] = array_pad(explode(' ', $header, 2), 2, '');

            if (isset($paths[$path])) {
                // The file's size, not the length read: the last section has
                // no separator after it, so the two cannot be compared alike.
                $runs = $this->parse($content, (int) $size > (int) $bytes);
                $latest[$paths[$path]] = $runs === [] ? null : $this->withoutOutput(end($runs));
            }
        }

        return $latest;
    }

    /**
     * Split a log into runs, oldest first.
     *
     * Output belongs to the run that is open when it is printed. Two open at
     * once (a Run now during cron's own run) write into the same file
     * interleaved, and nothing can untangle that — the output goes to the
     * newer one and both say they overlapped.
     *
     * @param  bool  $clipped  The text is the end of a longer file, so its
     *                         first line may be cut and its first run's output
     *                         incomplete.
     * @return array<int, array<string, mixed>>
     */
    public function parse(string $log, bool $clipped = false): array
    {
        $lines = preg_split('/\r?\n/', $log) ?: [];

        if ($clipped && $lines !== []) {
            array_shift($lines);
        }

        $runs = [];
        /** @var array<int, int> pid => index into $runs */
        $open = [];
        $loose = [];

        foreach ($lines as $line) {
            if (preg_match(self::START, $line, $m)) {
                // A pid still open from an earlier start is a run that never
                // wrote its exit (a reboot) and whose pid came round again.
                if (isset($open[(int) $m[1]])) {
                    $runs[$open[(int) $m[1]]]['killed'] = true;
                    unset($open[(int) $m[1]]);
                }

                $runs[] = $this->run(pid: (int) $m[1], manual: $m[2] !== '', startedAt: $m[3]);

                if ($open !== []) {
                    $runs[array_key_last($runs)]['overlapped'] = true;

                    foreach ($open as $index) {
                        $runs[$index]['overlapped'] = true;
                    }
                }

                $open[(int) $m[1]] = array_key_last($runs);

                continue;
            }

            if (preg_match(self::EXIT, $line, $m)) {
                $pid = ($m[2] ?? '') === '' ? null : (int) $m[2];

                if ($pid !== null && isset($open[$pid])) {
                    $index = $open[$pid];
                    unset($open[$pid]);
                } else {
                    // Its start is before the window, or it predates the start
                    // line: everything since the last run ended is its output.
                    $runs[] = $this->run(pid: $pid, manual: false, startedAt: null);
                    $index = array_key_last($runs);
                    $runs[$index]['output'] = $loose;
                    $runs[$index]['output_truncated'] = $clipped && $index === 0;
                }

                $runs[$index]['exit_code'] = (int) $m[1];
                $runs[$index]['finished_at'] = $m[3];
                $loose = [];

                continue;
            }

            if ($open !== []) {
                $runs[max($open)]['output'][] = $line;
            } else {
                $loose[] = $line;
            }
        }

        return array_map(fn (array $run) => $this->finish($run), $runs);
    }

    /**
     * @return array<string, mixed>
     */
    private function run(?int $pid, bool $manual, ?string $startedAt): array
    {
        return [
            'pid' => $pid,
            'manual' => $manual,
            'started_at' => $startedAt,
            'finished_at' => null,
            'exit_code' => null,
            'overlapped' => false,
            'killed' => false,
            'output' => [],
            'output_truncated' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $run
     * @return array<string, mixed>
     */
    private function finish(array $run): array
    {
        $timezone = ServerTimezone::get();
        $started = $run['started_at'] !== null ? Carbon::parse($run['started_at'])->setTimezone($timezone) : null;
        $finished = $run['finished_at'] !== null ? Carbon::parse($run['finished_at'])->setTimezone($timezone) : null;

        $status = match (true) {
            $run['exit_code'] === 0 => 'succeeded',
            $run['exit_code'] !== null => 'failed',
            // No exit line yet: still going if the shell that wrote the start
            // line is, otherwise it was killed (a reboot, an OOM) before it
            // could say how it ended.
            // /proc lists every process to every account, so the panel can
            // ask without sudo.
            ! $run['killed'] && $run['pid'] !== null && is_dir("/proc/{$run['pid']}") => 'running',
            default => 'interrupted',
        };

        $limit = (int) config('server.cronjob_run_output_bytes', 65536);
        // A trailing empty line is the log's last newline, not output.
        $lines = $run['output'];
        while ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }
        $output = implode("\n", $lines);
        $truncated = $run['output_truncated'] || strlen($output) > $limit;

        if (strlen($output) > $limit) {
            // The end is what says why it failed.
            $output = substr($output, -$limit);
        }

        return [
            'status' => $status,
            'exit_code' => $run['exit_code'],
            'manual' => $run['manual'],
            'overlapped' => $run['overlapped'],
            'started_at' => $started?->format('d-m-Y H:i:s'),
            'finished_at' => $finished?->format('d-m-Y H:i:s'),
            'duration_seconds' => $started && $finished ? max(0, (int) $started->diffInSeconds($finished)) : null,
            'output' => $output,
            'output_truncated' => $truncated,
        ];
    }

    /**
     * @param  array<string, mixed>  $run
     * @return array<string, mixed>
     */
    private function withoutOutput(array $run): array
    {
        unset($run['output'], $run['output_truncated']);

        return $run;
    }
}
