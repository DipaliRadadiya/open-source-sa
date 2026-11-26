<?php

use App\Services\Server\ServerOps;
use Illuminate\Support\Facades\Log;

/*
 * A download must cost the same memory whether the file is 40 KB or 40 GB.
 *
 * Reading through Symfony's `Process::latestOutput()` buffers the pipe: `cat` outruns the
 * client, memory runs out mid-stream, and the client gets a 200 whose `Content-Length` never
 * arrives.
 *
 * These tests use a *slow* consumer on purpose: a fast drain passes the broken version too.
 */

/**
 * Silences the server-ops channel.
 *
 * `Log::spy()` is not enough: `stream()` logs through `Log::channel(...)`, which a spy answers
 * with null.
 */
function silenceServerOps(): void
{
    Log::shouldReceive('channel')->with('server-ops')->andReturnSelf();
    Log::shouldReceive('info')->andReturnNull();
    Log::shouldReceive('error')->andReturnNull();
    Log::shouldReceive('warning')->andReturnNull();
}

/** A file of $mb megabytes of incompressible-ish bytes. */
function streamFixture(int $mb): string
{
    $path = tempnam(sys_get_temp_dir(), 'stream');
    $handle = fopen($path, 'wb');
    $block = random_bytes(1024 * 1024);

    for ($i = 0; $i < $mb; $i++) {
        fwrite($handle, $block);
    }

    fclose($handle);

    return $path;
}

it('holds a bounded amount of a large file, however slowly it is read', function () {
    silenceServerOps();

    $path = streamFixture(256);
    $ops = app(ServerOps::class);

    // A limit well under the file size, not an exact figure: the number may improve.
    $before = memory_get_usage(true);
    $bytes = 0;

    foreach ($ops->stream(['cat', $path]) as $chunk) {
        $bytes += strlen($chunk);

        // The slow consumer: without it `cat` never gets far enough ahead to show a difference.
        usleep(1000);

        $growth = memory_get_usage(true) - $before;

        expect($growth)->toBeLessThan(
            64 * 1024 * 1024,
            "memory grew by {$growth} bytes after {$bytes} of the file — the stream is accumulating",
        );
    }

    expect($bytes)->toBe(256 * 1024 * 1024);

    @unlink($path);
});

it('yields the file exactly, not merely the right number of bytes', function () {
    silenceServerOps();

    $path = tempnam(sys_get_temp_dir(), 'stream');
    $content = random_bytes(3 * 1024 * 1024 + 517);
    file_put_contents($path, $content);

    $seen = '';

    foreach (app(ServerOps::class)->stream(['cat', $path]) as $chunk) {
        $seen .= $chunk;
    }

    // Byte-exact: a plausible truncated prefix (an ISO that will not mount) is worse than failure.
    expect($seen)->toBe($content)
        ->and(strlen($seen))->toBe(strlen($content));

    @unlink($path);
});

it('records a failing command with what it managed to send', function () {
    // The headers are already sent when a streamed command fails, so the log, with its byte
    // count, is the only record of a truncated download.
    Log::shouldReceive('channel')->with('server-ops')->andReturnSelf();
    Log::shouldReceive('error')->once()->withArgs(function (string $message, array $context) {
        return $message === 'server operation stream'
            && $context['exit_code'] !== 0
            && $context['bytes'] === 0;
    });
    Log::shouldReceive('info')->andReturnNull();

    $chunks = 0;

    foreach (app(ServerOps::class)->stream(['cat', '/definitely/not/here']) as $chunk) {
        $chunks++;
    }

    expect($chunks)->toBe(0);
});

it('gives up on a producer that has stopped, rather than hanging', function () {
    silenceServerOps();

    // `sleep` holds the pipe open and produces nothing. Without an idle bound this blocks until
    // the (long) wall-clock timeout of a download.
    $started = microtime(true);
    $bytes = 0;

    foreach (app(ServerOps::class)->stream(['sleep', '30'], idleSeconds: 2) as $chunk) {
        $bytes += strlen($chunk);
    }

    $elapsed = microtime(true) - $started;

    expect($bytes)->toBe(0)
        ->and($elapsed)->toBeLessThan(10.0);
});

it('does not throw when a command cannot be started at all', function () {
    // `ServerOps` documents "Never throws" and callers read `failed()` to raise their own
    // translated exception. A cwd the panel user cannot enter fails inside `posix_spawn`, and
    // the raw Symfony exception used to bypass every caller.
    silenceServerOps();

    $result = app(ServerOps::class)->run(
        ['true'],
        ['feature' => 'test', 'op' => 'test'],
        cwd: '/definitely/not/a/directory',
    );

    // Failed and *unanswered*: a command that ran and said no differs from one that never ran.
    // The reason is in the server-ops log against the reference; there is no stderr to read.
    expect($result->failed())->toBeTrue()
        ->and($result->answered)->toBeFalse()
        ->and($result->reference)->not->toBe('');
});

it('stops the command when the reader stops reading early', function () {
    silenceServerOps();

    // A log range read drops the stream once it has its lines. `yes` never ends on its own: it
    // dies on its next write after the generator's pipes close. Nothing in stream() does this
    // explicitly.
    $token = 'stream-abandon-'.bin2hex(random_bytes(4));
    $stream = app(ServerOps::class)->stream(['yes', $token], idleSeconds: 5);

    foreach ($stream as $chunk) {
        expect($chunk)->toContain($token);

        break;
    }

    $started = microtime(true);
    unset($stream);
    usleep(300_000);

    expect(microtime(true) - $started)->toBeLessThan(3.0)
        // `[y]es` so pgrep does not find the shell running pgrep itself.
        ->and(trim((string) shell_exec('pgrep -f '.escapeshellarg('[y]es '.$token))))->toBe('');
});
