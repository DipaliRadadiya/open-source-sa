<?php

use App\Models\Cronjob;
use App\Models\User;
use App\Services\Server\CronjobHistory;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Process as RealProcess;

/*
 * #4 (support list, 2026-10-27): a cron job's last run, its run history and
 * whether it is running, read back out of the markers its log already has.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);

    $this->base = sys_get_temp_dir().'/sv-oss-cronhist-'.getmypid();
    File::deleteDirectory($this->base);
    File::makeDirectory("{$this->base}/logs", 0755, true);
    config([
        'server.cron_d' => "{$this->base}/cron.d",
        'server.cronjob_log_dir' => "{$this->base}/logs",
        // The server's clock, pinned: UTC unless a test says otherwise.
        'server.localtime_link' => "{$this->base}/no-localtime",
        'server.timezone_file' => "{$this->base}/timezone",
    ]);

    // The reads really run: the shell loop and tail are the point.
    Process::fake(function ($process) {
        $real = new RealProcess((array) $process->command);
        $real->run();

        return Process::result(output: $real->getOutput(), errorOutput: $real->getErrorOutput(), exitCode: $real->getExitCode());
    });
});

afterEach(fn () => File::deleteDirectory($this->base));

function histJob(string $name, ?string $log = null, array $attributes = []): Cronjob
{
    $job = Cronjob::create([
        'name' => $name,
        'slug' => Cronjob::uniqueSlug($name),
        'username' => 'deploy',
        'command' => 'php artisan schedule:run',
        'expression' => '* * * * *',
        'active' => true,
        ...$attributes,
    ]);

    if ($log !== null) {
        file_put_contents(test()->base."/logs/{$job->slug}.log", $log);
    }

    return $job;
}

/** A pid that cannot be running: above Linux's highest. */
const HIST_DEAD_PID = 99999999;

describe('reading runs out of a log', function () {

    it('finds a finished run with its output, exit code and duration', function () {
        $runs = app(CronjobHistory::class)->parse(<<<'LOG'
            --- start pid=4711 at 2026-10-27T03:00:01+00:00
            Backing up…
            done
            --- exit=0 pid=4711 at 2026-10-27T03:00:09+00:00

            LOG);

        expect($runs)->toHaveCount(1)
            ->and($runs[0])->toMatchArray([
                'status' => 'succeeded',
                'exit_code' => 0,
                'manual' => false,
                'started_at' => '27-10-2026 03:00:01',
                'finished_at' => '27-10-2026 03:00:09',
                'duration_seconds' => 8,
                'output' => "Backing up…\ndone",
            ]);
    });

    it('tells a failed run from one that printed nothing', function () {
        $runs = app(CronjobHistory::class)->parse(<<<'LOG'
            --- start pid=10 at 2026-10-27T03:00:01+00:00
            --- exit=0 pid=10 at 2026-10-27T03:00:01+00:00
            --- start pid=11 at 2026-10-27T03:01:01+00:00
            --- exit=127 pid=11 at 2026-10-27T03:01:01+00:00
            LOG);

        expect(array_column($runs, 'status'))->toBe(['succeeded', 'failed'])
            ->and($runs[1]['exit_code'])->toBe(127)
            ->and($runs[0]['output'])->toBe('');
    });

    it('reads a log written before the start line existed', function () {
        $runs = app(CronjobHistory::class)->parse(<<<'LOG'
            first
            --- exit=0 at 2026-10-26T03:00:05+00:00
            second
            --- exit=2 at 2026-10-27T03:00:05+00:00
            LOG);

        expect($runs)->toHaveCount(2)
            ->and($runs[1])->toMatchArray([
                'status' => 'failed',
                'exit_code' => 2,
                'started_at' => null,
                'finished_at' => '27-10-2026 03:00:05',
                'duration_seconds' => null,
                'output' => 'second',
            ]);
    });

    it('shows a run still going as running, and one killed before its exit as interrupted', function () {
        $dead = HIST_DEAD_PID;
        $alive = getmypid();

        $runs = app(CronjobHistory::class)->parse(
            "--- start pid={$dead} at 2026-10-27T02:00:01+00:00\nhalf way\n"
            ."--- start pid={$alive} manual at 2026-10-27T03:00:01+00:00\nworking\n"
        );

        expect(array_column($runs, 'status'))->toBe(['interrupted', 'running'])
            ->and($runs[1]['manual'])->toBeTrue()
            ->and($runs[1]['finished_at'])->toBeNull();
    });

    it('does not call a run running because its pid came round again after a reboot', function () {
        $pid = getmypid();

        $runs = app(CronjobHistory::class)->parse(
            "--- start pid={$pid} at 2026-10-26T02:00:01+00:00\n"
            ."--- start pid={$pid} at 2026-10-27T02:00:01+00:00\n"
            ."--- exit=0 pid={$pid} at 2026-10-27T02:00:03+00:00\n"
        );

        expect(array_column($runs, 'status'))->toBe(['interrupted', 'succeeded']);
    });

    it('pairs overlapping runs by pid and says they overlapped', function () {
        $runs = app(CronjobHistory::class)->parse(<<<'LOG'
            --- start pid=20 at 2026-10-27T03:00:00+00:00
            --- start pid=21 manual at 2026-10-27T03:00:30+00:00
            printed while both ran
            --- exit=0 pid=21 at 2026-10-27T03:00:40+00:00
            --- exit=1 pid=20 at 2026-10-27T03:02:00+00:00
            LOG);

        expect($runs[0])->toMatchArray(['exit_code' => 1, 'duration_seconds' => 120, 'overlapped' => true, 'manual' => false])
            ->and($runs[1])->toMatchArray(['exit_code' => 0, 'duration_seconds' => 10, 'overlapped' => true, 'manual' => true])
            // Interleaved output cannot be split; it goes to the newer run.
            ->and($runs[1]['output'])->toBe('printed while both ran')
            ->and($runs[0]['output'])->toBe('');
    });

    it('is not fooled by a job printing something that only looks like a marker', function () {
        $runs = app(CronjobHistory::class)->parse(<<<'LOG'
            --- start pid=30 at 2026-10-27T03:00:00+00:00
            --- exit=0 at yesterday
            --- start of section two
            ---- exit=0 pid=30 at 2026-10-27T03:00:01+00:00
            --- exit=3 pid=30 at 2026-10-27T03:00:05+00:00
            LOG);

        expect($runs)->toHaveCount(1)
            ->and($runs[0]['exit_code'])->toBe(3)
            ->and($runs[0]['output'])->toContain('--- start of section two');
    });

    it('keeps only the end of a very long output, and says so', function () {
        config(['server.cronjob_run_output_bytes' => 10]);

        $runs = app(CronjobHistory::class)->parse(
            "--- start pid=40 at 2026-10-27T03:00:00+00:00\n".str_repeat('x', 50)."\nreal error\n--- exit=1 pid=40 at 2026-10-27T03:00:01+00:00\n"
        );

        expect($runs[0]['output'])->toBe('real error')
            ->and($runs[0]['output_truncated'])->toBeTrue();
    });

    it('drops the cut first line of a clipped window and marks the first run incomplete', function () {
        $runs = app(CronjobHistory::class)->parse(
            "rt of a line\nmore output\n--- exit=0 pid=50 at 2026-10-27T03:00:01+00:00\n",
            clipped: true,
        );

        expect($runs[0]['output'])->toBe('more output')
            ->and($runs[0]['output_truncated'])->toBeTrue();
    });

    it("shows times on the server's clock, the one cron runs on", function () {
        file_put_contents("{$this->base}/timezone", "Asia/Kolkata\n");

        $runs = app(CronjobHistory::class)->parse("--- exit=0 at 2026-10-27T03:00:00+00:00\n");

        expect($runs[0]['finished_at'])->toBe('27-10-2026 08:30:00');
    });

    it('finds nothing in an empty log', function () {
        expect(app(CronjobHistory::class)->parse(''))->toBe([]);
    });
});

describe('GET /api/cronjobs/{id}/runs', function () {

    it('returns the runs newest first, with their output', function () {
        $job = histJob('Backup', <<<'LOG'
            --- start pid=1 at 2026-10-27T01:00:00+00:00
            one
            --- exit=0 pid=1 at 2026-10-27T01:00:02+00:00
            --- start pid=2 manual at 2026-10-27T02:00:00+00:00
            two
            --- exit=1 pid=2 at 2026-10-27T02:00:05+00:00

            LOG);

        $this->getJson("/api/cronjobs/{$job->id}/runs")
            ->assertOk()
            ->assertJsonCount(2, 'runs')
            ->assertJsonPath('runs.0.output', 'two')
            ->assertJsonPath('runs.0.status', 'failed')
            ->assertJsonPath('runs.0.manual', true)
            ->assertJsonPath('runs.1.output', 'one')
            ->assertJsonPath('timezone', 'UTC');
    });

    it('returns at most the configured number of runs', function () {
        config(['server.cronjob_history_runs' => 3]);
        $log = '';
        foreach (range(1, 6) as $i) {
            $log .= "--- start pid={$i} at 2026-10-27T0{$i}:00:00+00:00\n--- exit=0 pid={$i} at 2026-10-27T0{$i}:00:01+00:00\n";
        }
        $job = histJob('Many', $log);

        $this->getJson("/api/cronjobs/{$job->id}/runs")
            ->assertOk()
            ->assertJsonCount(3, 'runs')
            ->assertJsonPath('runs.0.started_at', '27-10-2026 06:00:00');
    });

    it('reads only the end of a long log', function () {
        config(['server.cronjob_history_window_bytes' => 200]);
        $old = "--- start pid=1 at 2026-10-26T01:00:00+00:00\n".str_repeat("old line\n", 100)."--- exit=0 pid=1 at 2026-10-26T01:00:09+00:00\n";
        $new = "--- start pid=2 at 2026-10-27T01:00:00+00:00\nnew\n--- exit=0 pid=2 at 2026-10-27T01:00:01+00:00\n";
        $job = histJob('Long', $old.$new);

        $runs = $this->getJson("/api/cronjobs/{$job->id}/runs")->assertOk()->json('runs');

        // The old run's start is out of the window: it is found by its exit,
        // with what is left of its output, and says the output is incomplete.
        expect($runs[0]['output'])->toBe('new')
            ->and($runs[1]['started_at'])->toBeNull()
            ->and($runs[1]['output_truncated'])->toBeTrue();
    });

    it('is empty for a job that has never written a log', function () {
        $job = histJob('Never');

        $this->getJson("/api/cronjobs/{$job->id}/runs")->assertOk()->assertExactJson(['runs' => [], 'timezone' => 'UTC']);
    });

    it('answers with a reference when the log cannot be read, not with no runs', function () {
        $job = histJob('Locked', "--- exit=0 at 2026-10-27T01:00:00+00:00\n");
        Process::fake(['*' => Process::result(exitCode: 1, errorOutput: 'tail: cannot open')]);

        $this->getJson("/api/cronjobs/{$job->id}/runs")
            ->assertStatus(500)
            ->assertJsonPath('code', 'cronjob_read_history')
            ->assertJsonStructure(['message', 'reference']);
    });

    it('lets a read-only user see the history', function () {
        $job = histJob('Visible', "--- exit=0 at 2026-10-27T01:00:00+00:00\n");
        $viewer = User::factory()->create();
        grantPermission($viewer, 'cronjob');
        $this->actingAs($viewer);

        $this->getJson("/api/cronjobs/{$job->id}/runs")->assertOk()->assertJsonCount(1, 'runs');
    });

    it('refuses a user without cron access', function () {
        $job = histJob('Hidden', "--- exit=0 at 2026-10-27T01:00:00+00:00\n");
        $this->actingAs(User::factory()->create());

        $this->getJson("/api/cronjobs/{$job->id}/runs")->assertForbidden();
    });
});

describe('last run in the list and on a job', function () {

    it('gives every job on the page its own last run, in one read', function () {
        $alive = getmypid();
        $ok = histJob('Ok job', "--- start pid=1 at 2026-10-27T01:00:00+00:00\n--- exit=0 pid=1 at 2026-10-27T01:00:04+00:00\n");
        $bad = histJob('Bad job', "--- start pid=2 at 2026-10-27T01:00:00+00:00\noops\n--- exit=1 pid=2 at 2026-10-27T01:00:01+00:00\n");
        $busy = histJob('Busy job', "--- exit=0 at 2026-10-27T00:00:00+00:00\n--- start pid={$alive} at 2026-10-27T01:00:00+00:00\n");
        $never = histJob('Never ran');

        $reads = 0;
        Process::fake(function ($process) use (&$reads) {
            $reads++;
            $real = new RealProcess((array) $process->command);
            $real->run();

            return Process::result(output: $real->getOutput(), exitCode: $real->getExitCode());
        });

        $jobs = collect($this->getJson('/api/cronjobs?per_page=10')->assertOk()->json('cronjobs'))->keyBy('id');

        expect($jobs[$ok->id]['last_run'])->toMatchArray(['status' => 'succeeded', 'duration_seconds' => 4])
            ->and($jobs[$ok->id]['running'])->toBeFalse()
            ->and($jobs[$bad->id]['last_run'])->toMatchArray(['status' => 'failed', 'exit_code' => 1])
            ->and($jobs[$busy->id]['last_run']['status'])->toBe('running')
            ->and($jobs[$busy->id]['running'])->toBeTrue()
            ->and($jobs[$never->id]['last_run'])->toBeNull()
            ->and($jobs[$never->id]['running'])->toBeFalse()
            // The list carries no output: that is what /runs is for.
            ->and($jobs[$bad->id]['last_run'])->not->toHaveKey('output')
            // One for the logs, one for whether cron is running.
            ->and($reads)->toBe(2);
    });

    it("does not let one job's output pose as another job's run", function () {
        // tail's own `==> file <==` header, printed by a job, would have
        // passed its fake run off as the next job's.
        $first = histJob('Aaa', "--- start pid=1 at 2026-10-27T01:00:00+00:00\n--- exit=0 pid=1 at 2026-10-27T01:00:01+00:00\n");
        $liar = histJob('Liar', "--- start pid=2 at 2026-10-27T02:00:00+00:00\n"
            ."==> {$this->base}/logs/aaa.log <==\n--- start pid=9 at 2026-10-27T09:00:00+00:00\n--- exit=99 pid=9 at 2026-10-27T09:00:01+00:00\n"
            ."--- exit=0 pid=2 at 2026-10-27T02:00:01+00:00\n");

        $latest = app(CronjobHistory::class)->latest([$first, $liar]);

        // A job can fake runs in its own history — it is its own log — but
        // never in another job's.
        expect($latest[$first->id])->toMatchArray(['exit_code' => 0, 'finished_at' => '27-10-2026 01:00:01'])
            ->and($latest[$liar->id]['exit_code'])->toBe(99);
    });

    it('reads only the end of each log for the list', function () {
        config(['server.cronjob_latest_window_bytes' => 120]);
        $job = histJob('Chatty', "--- start pid=1 at 2026-10-27T01:00:00+00:00\n".str_repeat("noise\n", 200)."--- exit=0 pid=1 at 2026-10-27T01:00:09+00:00\n");

        $reads = [];
        Process::fake(function ($process) use (&$reads) {
            $reads[] = $process->command;
            $real = new RealProcess((array) $process->command);
            $real->run();

            return Process::result(output: $real->getOutput(), exitCode: $real->getExitCode());
        });

        // The start is outside the window and is read back on its own (RC-38):
        // start lines only, never the 200 lines of output.
        expect(app(CronjobHistory::class)->latest([$job])[$job->id])
            ->toMatchArray(['status' => 'succeeded', 'started_at' => '27-10-2026 01:00:00', 'duration_seconds' => 9, 'manual' => false])
            ->and($reads)->toHaveCount(2)
            ->and($reads[0])->toContain('120');
    });

    it('keeps a Run now whose output is longer than the window as manual (RC-38)', function () {
        // Default window, 16 KB: the start line is long gone from the tail.
        $manual = histJob('Long manual', "--- start pid=7 at 2026-10-27T00:00:00+00:00\n--- exit=0 pid=7 at 2026-10-27T00:00:01+00:00\n"
            ."--- start pid=7 manual at 2026-10-27T01:00:00+00:00\n".str_repeat(str_repeat('x', 99)."\n", 200)
            ."--- exit=0 pid=7 at 2026-10-27T01:00:30+00:00\n");
        $cron = histJob('Long cron', "--- start pid=8 at 2026-10-27T01:00:00+00:00\n".str_repeat(str_repeat('y', 99)."\n", 200)
            ."--- exit=1 pid=8 at 2026-10-27T01:00:05+00:00\n");

        expect(filesize("{$this->base}/logs/{$manual->slug}.log"))->toBeGreaterThan(16384);

        $jobs = collect($this->getJson('/api/cronjobs?per_page=10')->assertOk()->json('cronjobs'))->keyBy('id');

        // The pid's earlier cron run, also in the log, is not mistaken for it.
        expect($jobs[$manual->id]['last_run'])->toMatchArray(['manual' => true, 'started_at' => '27-10-2026 01:00:00', 'duration_seconds' => 30])
            ->and($jobs[$cron->id]['last_run'])->toMatchArray(['manual' => false, 'started_at' => '27-10-2026 01:00:00', 'exit_code' => 1])
            // The list and the history agree.
            ->and($this->getJson("/api/cronjobs/{$manual->id}/runs")->assertOk()->json('runs.0.manual'))->toBeTrue();
    });

    it('knows a log was cut even when it is the last one read', function (string $where) {
        // The job printed `xx--- start pid=6 …`: output, not a marker. A
        // window opening two bytes into that line sees a perfect start line,
        // and only knowing the window was cut stops it being believed.
        $tail = "--- start pid=6 at 2026-10-27T01:00:00+00:00\n--- exit=0 pid=6 at 2026-10-27T02:00:00+00:00\n";
        config(['server.cronjob_latest_window_bytes' => strlen($tail)]);
        $other = histJob('Other', "--- exit=0 at 2026-10-27T00:00:00+00:00\n");
        $cut = histJob('Cut', 'xx'.$tail);

        $latest = app(CronjobHistory::class)->latest($where === 'last' ? [$other, $cut] : [$cut, $other]);

        expect($latest[$cut->id])->toMatchArray(['exit_code' => 0, 'started_at' => null]);
    })->with(['last' => ['last'], 'first' => ['first']]);

    it('still lists the jobs when the logs cannot be read', function () {
        histJob('Unreadable', "--- exit=0 at 2026-10-27T01:00:00+00:00\n");
        Process::fake(['*' => Process::result(exitCode: 1)]);

        $this->getJson('/api/cronjobs')
            ->assertOk()
            ->assertJsonPath('cronjobs.0.last_run', null);
    });

    it('shows the last run on a single job too', function () {
        $job = histJob('Single', "--- start pid=1 at 2026-10-27T01:00:00+00:00\n--- exit=0 pid=1 at 2026-10-27T01:00:02+00:00\n");

        $this->getJson("/api/cronjobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('cronjob.last_run.status', 'succeeded')
            ->assertJsonPath('cronjob.running', false);
    });
});
