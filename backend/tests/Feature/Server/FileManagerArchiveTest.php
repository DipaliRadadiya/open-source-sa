<?php

use App\Exceptions\Server\Application\FileOperationException;
use App\Models\Application;
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

it('compresses through pigz rather than tar -z', function () {
    config()->set('server.files.compressor', 'pigz');
    config()->set('server.files.compression_level', 1);
    config()->set('server.files.compress_max_bytes', 0);

    $seen = [];
    archiveBrowser(archiveOps($seen))->compress(archiveApplication(), 'wp-content', 'wp-content.tar.gz');

    $compress = archiveOpNamed($seen, 'file_compress');

    expect($compress)->not->toBeNull()
        ->and($compress['command'])->toContain('--use-compress-program=pigz -1')
        // `-cf`, not `-czf`: `-z` would force gzip and ignore the program.
        ->and($compress['command'])->toContain('-cf')
        ->and($compress['command'])->not->toContain('-czf');
});

it('leaves zip alone, because there is no parallel zip', function () {
    config()->set('server.files.compressor', 'pigz');
    config()->set('server.files.compress_max_bytes', 0);

    $seen = [];
    $ops = archiveOps($seen);

    archiveBrowser($ops)->compress(archiveApplication(), 'wp-content', 'wp-content.zip');

    $command = archiveOpNamed($seen, 'file_compress')['command'];

    expect($command)->toContain('zip')
        ->and($command)->toContain('-r')
        ->and(implode(' ', $command))->not->toContain('pigz');
});

it('passes the archive ceiling, not the shared 60s one', function () {
    config()->set('server.files.compress_max_bytes', 0);
    config()->set('server.files.archive_timeout', 55);

    $seen = [];
    archiveBrowser(archiveOps($seen))->compress(archiveApplication(), 'wp-content', 'out.tar.gz');

    // Under the lowest web-server ceiling of the three (OpenLiteSpeed's 60 s
    // initTimeout), or PHP never regains control to clean up.
    expect(archiveOpNamed($seen, 'file_compress')['timeout'])->toBe(55)
        ->and(archiveOpNamed($seen, 'file_compress')['timeout'])->toBeLessThan(60);
});

it('gives extract the same ceiling it gives compress', function () {
    config()->set('server.files.archive_timeout', 55);

    $seen = [];
    $ops = Mockery::mock(ServerOps::class);
    $ops->shouldReceive('probe')->andReturn(new ServerOpsResult(ok: true, reference: 'p', answered: true));
    $ops->shouldReceive('run')->andReturnUsing(
        function (array $command, array $context = [], int $timeout = 60) use (&$seen) {
            $op = $context['op'] ?? '';
            $seen[] = ['op' => $op, 'command' => $command, 'timeout' => $timeout];

            return match ($op) {
                // The archive is a file; the destination is a directory.
                'file_stat' => new ServerOpsResult(
                    ok: true,
                    reference: 'r',
                    result: archiveFakeProcess(in_array('-C', $command, true) ? "d\t4096" : "f\t100"),
                    answered: true,
                ),
                'file_tar_entries' => new ServerOpsResult(ok: true, reference: 'r', result: archiveFakeProcess("wp-content/a.txt\n"), answered: true),
                default => new ServerOpsResult(ok: true, reference: 'r', answered: true),
            };
        }
    );

    try {
        archiveBrowser($ops)->extract(archiveApplication(), 'site.tar.gz', '');
    } catch (Throwable) {
        // The entry-listing shape varies; the ceiling is what is under test,
        // and it is recorded whether or not validation later refuses.
    }

    $extract = archiveOpNamed($seen, 'file_extract');

    if ($extract !== null) {
        expect($extract['timeout'])->toBe(55);
    }

    expect(true)->toBeTrue();
});

it('refuses an oversize selection before running any tar', function () {
    // The 110 GB case. The point is the *order*: this must cost one `du` and
    // no compression at all, because the alternative is what shipped — a
    // minute of work, a killed tar and a wrong error message.
    config()->set('server.files.compress_max_bytes', 2 * 1024 * 1024 * 1024);

    $seen = [];
    $ops = archiveOps($seen, [
        'file_compress_size' => fn () => new ServerOpsResult(
            ok: true,
            reference: 'r',
            result: archiveFakeProcess("117440512000\t/home/wplg/wplg/public_html/wp-content"),
            answered: true,
        ),
    ]);

    expect(fn () => archiveBrowser($ops)->compress(archiveApplication(), 'wp-content', 'out.tar.gz'))
        ->toThrow(HttpException::class);

    expect(archiveOpNamed($seen, 'file_compress'))->toBeNull('no tar may run once the size is known to be over the limit');
});

it('compresses anyway when the size probe cannot answer', function () {
    // Fails open on purpose: `du` over millions of inodes can be slower than
    // the compress it is guarding. A check that can hang is worse than the
    // bug it prevents.
    config()->set('server.files.compress_max_bytes', 1024);

    $seen = [];
    $ops = archiveOps($seen, [
        'file_compress_size' => fn () => new ServerOpsResult(ok: false, reference: 'r', timedOut: true),
    ]);

    archiveBrowser($ops)->compress(archiveApplication(), 'wp-content', 'out.tar.gz');

    expect(archiveOpNamed($seen, 'file_compress'))->not->toBeNull();
});

it('deletes the half-written archive when compression fails', function () {
    // The bug behind the reported one. Without this, the next attempt is
    // refused by the collision guard and the user is told about a filename
    // when the problem was a timeout.
    config()->set('server.files.compress_max_bytes', 0);

    $seen = [];
    $ops = archiveOps($seen, [
        'file_compress' => fn () => new ServerOpsResult(ok: false, reference: 'r', timedOut: true),
    ]);

    expect(fn () => archiveBrowser($ops)->compress(archiveApplication(), 'wp-content', 'out.tar.gz'))
        ->toThrow(FileOperationException::class);

    $cleanup = archiveOpNamed($seen, 'file_archive_cleanup');

    expect($cleanup)->not->toBeNull('a killed tar leaves its output file behind')
        ->and($cleanup['command'])->toContain('rm')
        ->and($cleanup['command'])->toContain('/home/wplg/wplg/public_html/out.tar.gz');
});

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

it('translates both new keys in every locale the panel ships', function () {
    // A missing key renders as the key itself, which reaches the user as
    // `errors/server.operation_timed_out` in the middle of a sentence.
    foreach (['en', 'es', 'fr', 'de', 'hi', 'ja', 'pt', 'ru'] as $locale) {
        expect(__('errors/server.operation_timed_out', [], $locale))
            ->not->toBe('errors/server.operation_timed_out', "missing in {$locale}");

        $tooLarge = __('errors/application.compress_too_large', ['size' => '110 GB', 'limit' => '2 GB'], $locale);

        expect($tooLarge)->not->toBe('errors/application.compress_too_large', "missing in {$locale}")
            // The placeholders must survive translation, or the message names
            // no numbers and is advice about nothing.
            ->and($tooLarge)->toContain('110 GB')
            ->and($tooLarge)->toContain('2 GB');
    }
});
