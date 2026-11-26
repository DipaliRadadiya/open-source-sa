<?php

use App\Enums\FileArchiveStatus;
use App\Exceptions\Server\Application\FileOperationException;
use App\Exceptions\Server\Application\FileRefusedException;
use App\Jobs\RunFileArchive;
use App\Models\Application;
use App\Models\FileArchiveJob;
use App\Models\SystemUser;
use App\Services\Server\Applications\FileBrowser;
use App\Services\Server\Applications\PanelDirectory;
use App\Services\Server\Docker\VolumeSizes;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
 * A large `tar -czf` killed at the shared 60 s ceiling leaves a partial archive, so a retry hit
 * the does-it-exist guard and blamed a naming collision. The timeout must be carried out of
 * `ServerOps` and the partial file cleaned up.
 *
 * The ceiling belongs to the web server and the three the panel installs disagree: nginx and
 * Apache allow 300 s, OpenLiteSpeed's `initTimeout` is 60. A timeout tuned to 300 would fail
 * above PHP on OLS and skip the cleanup, so the ceiling stays under the lowest and a size check
 * refuses up front.
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
 * Every helper here is prefixed: Pest loads all test files into one process, and a name clash
 * (`ProcessKillTest` already owns `fakeProcess()`) is a fatal error that takes the run down.
 */

/** A `ProcessResult` whose `output()` is whatever the test needs `find` or `du` to have printed. */
function archiveFakeProcess(string $stdout): Illuminate\Contracts\Process\ProcessResult
{
    $process = Mockery::mock(Illuminate\Contracts\Process\ProcessResult::class);
    $process->shouldReceive('output')->andReturn($stdout);
    $process->shouldReceive('errorOutput')->andReturn('');

    return $process;
}

/**
 * A `ServerOps` double that records every command with the ceiling it was given, and answers
 * the existence probes as a real box would for "compress into a name nothing occupies yet".
 *
 * Mocked at `ServerOps` because the thing under test is the argv and the timeout the browser
 * passes. The stat answers depend on the path: the code asks different questions with the same
 * op name, and one answer for all would trip the collision guard.
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
                // Answered by the path, not a call counter, so it survives `compress()` asking
                // one more question. Of the source, the archive and its directory, only the
                // archive must be absent.
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
    // `VolumeSizes` comes from the container, not a fake: nothing here measures a container
    // site, but it is a required constructor dependency.
    return new FileBrowser($ops, app(PanelDirectory::class), app(VolumeSizes::class));
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
    // 'process timed out' was written to stderr but nothing read it, leaving an empty log message.
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
        // The ordinary failure is untouched: the timeout branch must not swallow its message.
        ->and($plainBody['code'])->toBe('server_operation_failed')
        ->and($plainBody['message'])->toBe(__('errors/application.file_operation_failed'));
});

/* The async half: what the endpoint promises, and what it refuses to promise. */

it('accepts the work instead of claiming it is done', function () {
    // A 200 + `compressed: true` would claim work that has not happened: on anything large the
    // request dies at the web server's ceiling while tar carries on.
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
    // Validation is the cheap security boundary and answers inside the request; only the part
    // that scales with the user's data is queued. A job row for refused work would show as a
    // failure the user did not cause.
    Queue::fake();

    $seen = [];

    expect(fn () => archiveBrowser(archiveOps($seen))->compress(archiveApplication(), 'wp-content', 'out.txt'))
        ->toThrow(FileRefusedException::class);

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
        ->toThrow(FileRefusedException::class);

    expect(FileArchiveJob::count())->toBe(0);
});

it('runs one job per target path, however many times the button is pressed', function () {
    // Two tars writing one file produce a corrupt archive and no error.
    $app = archiveApplication();

    $first = FileArchiveJob::create([
        'application_id' => $app->id, 'operation' => 'compress',
        'sources' => ['a'], 'target' => 'out.tar.gz', 'status' => FileArchiveStatus::Queued,
    ]);
    $second = FileArchiveJob::create([
        'application_id' => $app->id, 'operation' => 'compress',
        'sources' => ['a'], 'target' => 'out.tar.gz', 'status' => FileArchiveStatus::Queued,
    ]);

    // Different rows, same lock: keying on the row id would prevent nothing.
    expect((new RunFileArchive($first->id))->uniqueId())
        ->toBe((new RunFileArchive($second->id))->uniqueId());

    // A different target may run alongside it.
    $other = FileArchiveJob::create([
        'application_id' => $app->id, 'operation' => 'compress',
        'sources' => ['a'], 'target' => 'other.tar.gz', 'status' => FileArchiveStatus::Queued,
    ]);

    expect((new RunFileArchive($other->id))->uniqueId())
        ->not->toBe((new RunFileArchive($first->id))->uniqueId());
});

it('gives the lock an expiry, so a killed worker does not wedge the path forever', function () {
    // `Illuminate\Bus\UniqueLock` falls back to 0 seconds, which RedisLock reads as no TTL: a
    // worker killed outright would block that path forever, with no failed_jobs row.
    $job = new RunFileArchive(1);

    expect($job->uniqueFor())->toBeGreaterThan($job->timeout);
});

it('keeps the archive job inside its own reservation window', function () {
    // A job that outlives `retry_after` is dispatched a second time, concurrently.
    foreach (['database', 'redis', 'beanstalkd'] as $connection) {
        expect((new RunFileArchive(1))->timeout)
            ->toBeLessThan((int) config("queue.connections.{$connection}.retry_after"), $connection);
    }
});

it('settles a stranded row instead of spinning forever', function () {
    // A worker killed outright never reaches `failed()`: the row would sit at `running` and the
    // unique lock would keep that path unusable.
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

    // A killed tar has already created its output file; leaving it makes the retry look like a
    // filename collision.
    $cleanup = archiveOpNamed($seen, 'file_archive_cleanup');

    expect($cleanup)->not->toBeNull()
        ->and($cleanup['command'])->toContain('/home/wplg/wplg/public_html/out.tar.gz');

    // The reason is a stored code, so the sentence is built in the reader's locale.
    $job->update(['status' => FileArchiveStatus::Failed, 'reason' => 'timed_out']);

    expect($job->fresh()->message())->toBe(__('errors/application.archive_failed.timed_out'))
        ->and($job->fresh()->message())->not->toContain('archive_failed');
});

it('translates every failure reason in every locale the panel ships', function () {
    // A missing key renders as the key itself, e.g. `errors/application.archive_failed.worker`.
    foreach (['en', 'es', 'fr', 'de', 'hi', 'ja', 'pt', 'ru'] as $locale) {
        foreach (['timed_out', 'command_failed', 'application_missing', 'worker', 'unknown'] as $reason) {
            $key = "errors/application.archive_failed.{$reason}";

            expect(__($key, [], $locale))->not->toBe($key, "{$reason} missing in {$locale}");
        }

        expect(__('errors/server.operation_timed_out', [], $locale))
            ->not->toBe('errors/server.operation_timed_out', "missing in {$locale}");
    }
});

it('reports bytes written while a compress is still running', function () {
    // `tar` reports no progress and the worker blocks on it, so the size is read at poll time
    // from the file being written.
    $app = archiveApplication();

    $job = FileArchiveJob::create([
        'application_id' => $app->id, 'operation' => 'compress',
        'sources' => ['wp-content'], 'target' => 'out.tar.gz',
        'status' => FileArchiveStatus::Running, 'started_at' => now(),
    ]);

    $seen = [];
    $ops = archiveOps($seen, [
        'file_stat' => fn () => new ServerOpsResult(
            ok: true, reference: 'r', result: archiveFakeProcess("f\t5961270368"), answered: true,
        ),
    ]);

    expect(archiveBrowser($ops)->archiveSize($job->fresh()))->toBe(5961270368);
});

it('does not claim a size for an extract', function () {
    // Extraction writes a tree into a directory that already had contents, so there is no single
    // artefact to measure; the target directory's size would start non-zero and mean nothing.
    $app = archiveApplication();

    $job = FileArchiveJob::create([
        'application_id' => $app->id, 'operation' => 'extract',
        'sources' => ['site.tar.gz'], 'target' => '', 'status' => FileArchiveStatus::Running,
    ]);

    $seen = [];

    expect(archiveBrowser(archiveOps($seen))->archiveSize($job->fresh()))->toBe(0);
});
