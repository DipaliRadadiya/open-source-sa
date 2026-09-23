<?php

use App\Enums\FileArchiveStatus;
use App\Exceptions\Server\Application\FileOperationException;
use App\Jobs\RunFileArchive;
use App\Models\Application;
use App\Models\FileArchiveJob;
use App\Models\SystemUser;
use App\Services\Server\Applications\FileBrowser;
use App\Services\Server\Applications\PanelDirectory;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

/*
 * Compressing 18 Ubuntu ISOs — about 110 GB — was reported as "something
 * already exists at that path".
 *
 * It was not a naming collision. `tar -czf` was killed at the 60 s ceiling
 * every file operation shared, which left a partial `archive.tar.gz` on disk,
 * so the obvious next move (try again) hit the does-it-exist guard and
 * reported *that* as the problem. The real failure had already happened a
 * minute earlier and said nothing: `ServerOps` wrote 'process timed out' into
 * stderr and nothing carried it out, so the exception was the generic
 * "the file operation failed" and the log line an empty message.
 *
 * The ceiling is not ours to raise, either. It belongs to the web server, and
 * the three the panel installs disagree: nginx and Apache allow 300 s, while
 * OpenLiteSpeed's `initTimeout` is 60. A timeout tuned to 300 would work on
 * two of them and, on the third, fail *above* PHP — so the cleanup below
 * would never run. Hence a ceiling under the lowest, and a size check that
 * refuses up front instead.
 */

function archiveApplication(): Application
{
    $systemUser = SystemUser::create(['username' => 'wplg', 'home_path' => '/home/wplg']);

    return Application::forceCreate([
        'system_user_id' => $systemUser->id,
        'name' => 'WP Large',
        'slug' => 'wplg',
        'domain' => 'wplg.test',
        'web_root' => 'public_html',
        'php_version' => '8.4',
        'site_type' => 'php',
    ]);
}

/*
 * Every helper here is prefixed. Pest loads all test files into one process,
 * so a bare `fakeProcess()` shares a global namespace with every other suite —
 * `ProcessKillTest` already owns that name, and the collision is a fatal
 * error rather than a failure, so it takes the whole run down.
 */

/**
 * A `ProcessResult` whose `output()` is whatever the test needs `find` or
 * `du` to have printed.
 */
function archiveFakeProcess(string $stdout): Illuminate\Contracts\Process\ProcessResult
{
    $process = Mockery::mock(Illuminate\Contracts\Process\ProcessResult::class);
    $process->shouldReceive('output')->andReturn($stdout);
    $process->shouldReceive('errorOutput')->andReturn('');

    return $process;
}

/**
 * A `ServerOps` double that records every command with the ceiling it was
 * given, and answers the existence probes the way a real box would for
 * "compress this directory into a name nothing occupies yet".
 *
 * Mocked at `ServerOps` rather than the Process facade because the thing
 * under test is the argv the browser assembles and which timeout it passes;
 * faking one layer lower would test Laravel's process plumbing instead. The
 * stat answers are stateful for the reason a static fake would be wrong here
 * — the code asks two different questions with the same op name, and a fake
 * that answers both identically makes the collision guard fire and the test
 * assert against a path it never meant to take.
 *
 * @param  array<string, callable>  $handlers  keyed by the `op` context value
 */
function archiveOps(array &$seen, array $handlers = []): ServerOps
{
    $ops = Mockery::mock(ServerOps::class);

    $ops->shouldReceive('probe')->andReturn(new ServerOpsResult(ok: true, reference: 'p', answered: true));

    $ops->shouldReceive('run')->andReturnUsing(
        function (array $command, array $context = [], int $timeout = 60) use (&$seen, $handlers) {
            $op = $context['op'] ?? '';
            $seen[] = ['op' => $op, 'command' => $command, 'timeout' => $timeout];

            if (isset($handlers[$op])) {
                return $handlers[$op]($command);
            }

            if ($op === 'file_stat') {
                // Answered by the path, not by a call counter. `compress()`
                // stats three things — the source, the destination archive,
                // and the directory the archive lands in — and a fake that
                // counts calls has to know that number, so it breaks the
                // moment the method asks one more question. The archive is
                // the only one that must be absent.
                $target = $command[array_search('find', $command, true) + 1] ?? '';

                return preg_match('/\.(tar\.gz|tgz|zip)$/', $target) === 1
                    ? new ServerOpsResult(ok: false, reference: 'r', answered: true)
                    : new ServerOpsResult(ok: true, reference: 'r', result: archiveFakeProcess("d\t4096"), answered: true);
            }

            return new ServerOpsResult(ok: true, reference: 'r', answered: true);
        }
    );

    return $ops;
}

function archiveBrowser(ServerOps $ops): FileBrowser
{
    return new FileBrowser($ops, app(PanelDirectory::class));
}

/** The recorded entry for one op, or null. */
function archiveOpNamed(array $seen, string $op): ?array
{
    foreach ($seen as $entry) {
        if ($entry['op'] === $op) {
            return $entry;
        }
    }

    return null;
}

it('carries the timeout out of ServerOps instead of losing it in stderr', function () {
    // The string 'process timed out' was already being written here; nothing
    // read it, which is why the log line was `production.ERROR:  {` with an
    // empty message.
    Process::fake(function () {
        $process = new Symfony\Component\Process\Process(['sleep', '10']);

        throw new ProcessTimedOutException(
            new Symfony\Component\Process\Exception\ProcessTimedOutException(
                $process,
                Symfony\Component\Process\Exception\ProcessTimedOutException::TYPE_GENERAL,
            ),
            new ProcessResult($process),
        );
    });

    $result = app(ServerOps::class)->run(['tar', '-cf', '/tmp/x.tar', '.'], ['feature' => 'test', 'op' => 'test'], timeout: 1);

    expect($result->timedOut)->toBeTrue()
        ->and($result->failed())->toBeTrue();
});

it('tells the user it timed out rather than that the operation failed', function () {
    $timedOut = new FileOperationException('ref-1', timedOut: true);
    $plain = new FileOperationException('ref-2');

    $request = Request::create('/api/x', 'POST');

    $timedOutBody = $timedOut->render($request)->getData(true);
    $plainBody = $plain->render($request)->getData(true);

    expect($timedOutBody['code'])->toBe('server_operation_timed_out')
        ->and($timedOutBody['message'])->toBe(__('errors/server.operation_timed_out'))
        // And the ordinary failure is untouched — the new branch must not
        // swallow the message every other file failure relies on.
        ->and($plainBody['code'])->toBe('server_operation_failed')
        ->and($plainBody['message'])->toBe(__('errors/application.file_operation_failed'));
});

/*
 * The async half: what the endpoint promises, and what it refuses to promise.
 */

it('accepts the work instead of claiming it is done', function () {
    // 200 + `compressed: true` is what the synchronous version returned, and
    // on anything large it was saying that about work that had not happened —
    // the request died at the web server's ceiling while tar carried on.
    Queue::fake();

    $seen = [];
    $app = archiveApplication();
    $browser = archiveBrowser(archiveOps($seen));

    $job = $browser->compress($app, 'wp-content', 'out.tar.gz');

    expect($job->status)->toBe(FileArchiveStatus::Queued)
        ->and($job->sources)->toBe(['wp-content'])
        ->and($job->target)->toBe('out.tar.gz');

    Queue::assertPushed(RunFileArchive::class);
});

it('refuses an invalid target before any job exists', function () {
    // The ordering that matters. Validation is the security boundary and it is
    // cheap, so it answers inside the request; only the part whose cost scales
    // with the user's data goes to the queue. A job row created for work that
    // was never going to be allowed is a row that shows up in the panel as a
    // failure the user did not cause.
    Queue::fake();

    $seen = [];

    expect(fn () => archiveBrowser(archiveOps($seen))->compress(archiveApplication(), 'wp-content', 'out.txt'))
        ->toThrow(HttpException::class);

    expect(FileArchiveJob::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('refuses to overwrite an archive that already exists, before queueing', function () {
    Queue::fake();

    $seen = [];
    // Every stat answers "present", including the destination.
    $ops = archiveOps($seen, [
        'file_stat' => fn () => new ServerOpsResult(ok: true, reference: 'r', result: archiveFakeProcess("f\t10"), answered: true),
    ]);

    expect(fn () => archiveBrowser($ops)->compress(archiveApplication(), 'wp-content', 'out.tar.gz'))
        ->toThrow(HttpException::class);

    expect(FileArchiveJob::count())->toBe(0);
});

it('runs one job per target path, however many times the button is pressed', function () {
    // A held-down button used to start as many operations as it was clicked,
    // each writing the same archive. Two tars writing one file produce a
    // corrupt archive and no error at all.
    $app = archiveApplication();

    $first = FileArchiveJob::create([
        'application_id' => $app->id, 'operation' => 'compress',
        'sources' => ['a'], 'target' => 'out.tar.gz', 'status' => FileArchiveStatus::Queued,
    ]);
    $second = FileArchiveJob::create([
        'application_id' => $app->id, 'operation' => 'compress',
        'sources' => ['a'], 'target' => 'out.tar.gz', 'status' => FileArchiveStatus::Queued,
    ]);

    // Different rows, same lock — which is the point. Keying on the row id
    // would give every request its own lock and prevent nothing.
    expect((new RunFileArchive($first->id))->uniqueId())
        ->toBe((new RunFileArchive($second->id))->uniqueId());

    // And a different target is genuinely allowed to run alongside it.
    $other = FileArchiveJob::create([
        'application_id' => $app->id, 'operation' => 'compress',
        'sources' => ['a'], 'target' => 'other.tar.gz', 'status' => FileArchiveStatus::Queued,
    ]);

    expect((new RunFileArchive($other->id))->uniqueId())
        ->not->toBe((new RunFileArchive($first->id))->uniqueId());
});

it('gives the lock an expiry, so a killed worker does not wedge the path forever', function () {
    // `Illuminate\Bus\UniqueLock` falls back to 0 seconds and RedisLock reads
    // that as setnx with no TTL. Because the lock is keyed on the target path,
    // a worker killed outright would mean that path could never be compressed
    // again — silently, with no error and no failed_jobs row.
    $job = new RunFileArchive(1);

    expect($job->uniqueFor())->toBeGreaterThan($job->timeout);
});

it('keeps the archive job inside its own reservation window', function () {
    // The property that was violated once already, by raising one literal
    // without the other. A job that outlives `retry_after` is not retried — it
    // is dispatched a second time as a fresh reservation, concurrently.
    foreach (['database', 'redis', 'beanstalkd'] as $connection) {
        expect((new RunFileArchive(1))->timeout)
            ->toBeLessThan((int) config("queue.connections.{$connection}.retry_after"), $connection);
    }
});

it('settles a stranded row instead of spinning forever', function () {
    // A worker killed outright never reaches `failed()`. Without this the row
    // sits at `running`, the screen shows a spinner that will not resolve, and
    // the unique lock keeps that path unusable.
    $app = archiveApplication();

    $job = FileArchiveJob::create([
        'application_id' => $app->id, 'operation' => 'compress',
        'sources' => ['a'], 'target' => 'out.tar.gz', 'status' => FileArchiveStatus::Running,
        'started_at' => now()->subSeconds((new RunFileArchive(1))->uniqueFor() + 60),
    ]);

    expect($job->isStale())->toBeTrue();

    $fresh = FileArchiveJob::create([
        'application_id' => $app->id, 'operation' => 'compress',
        'sources' => ['a'], 'target' => 'fresh.tar.gz', 'status' => FileArchiveStatus::Running,
        'started_at' => now(),
    ]);

    expect($fresh->isStale())->toBeFalse();
});

it('records why it failed, in the viewer locale, and clears the partial archive', function () {
    $app = archiveApplication();

    $job = FileArchiveJob::create([
        'application_id' => $app->id, 'operation' => 'compress',
        'sources' => ['wp-content'], 'target' => 'out.tar.gz', 'status' => FileArchiveStatus::Queued,
    ]);

    $seen = [];
    $ops = archiveOps($seen, [
        'file_compress' => fn () => new ServerOpsResult(ok: false, reference: 'ref-9', timedOut: true),
    ]);

    expect(fn () => archiveBrowser($ops)->runArchiveJob($job))
        ->toThrow(FileOperationException::class);

    // A killed tar has already created its output file. Leaving it is what
    // made a timeout look like a filename collision on the next attempt.
    $cleanup = archiveOpNamed($seen, 'file_archive_cleanup');

    expect($cleanup)->not->toBeNull()
        ->and($cleanup['command'])->toContain('/home/wplg/wplg/public_html/out.tar.gz');

    // The reason is a stored code, so the sentence is built in the reader's
    // locale rather than the locale of whoever started the job.
    $job->update(['status' => FileArchiveStatus::Failed, 'reason' => 'timed_out']);

    expect($job->fresh()->message())->toBe(__('errors/application.archive_failed.timed_out'))
        ->and($job->fresh()->message())->not->toContain('archive_failed');
});

it('translates every failure reason in every locale the panel ships', function () {
    // A missing key renders as the key itself, which reaches the user as
    // `errors/application.archive_failed.worker` in place of a sentence.
    foreach (['en', 'es', 'fr', 'de', 'hi', 'ja', 'pt', 'ru'] as $locale) {
        foreach (['timed_out', 'command_failed', 'application_missing', 'worker', 'unknown'] as $reason) {
            $key = "errors/application.archive_failed.{$reason}";

            expect(__($key, [], $locale))->not->toBe($key, "{$reason} missing in {$locale}");
        }

        expect(__('errors/server.operation_timed_out', [], $locale))
            ->not->toBe('errors/server.operation_timed_out', "missing in {$locale}");
    }
});
