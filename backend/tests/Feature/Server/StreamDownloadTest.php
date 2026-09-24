<?php

use App\Services\Server\ServerOps;
use Illuminate\Support\Facades\Log;

/*
 * A download must cost the same memory whether the file is 40 KB or 40 GB.
 *
 * It did not. `stream()` drove Symfony's `Process` and read with
 * `latestOutput()`, and Symfony buffers the pipe on our behalf. `cat` reads a
 * local disk at roughly 1 GB/s while a browser takes the bytes at network
 * speed, so the gap accumulated until PHP's limit was gone:
 *
 *   PHP Fatal error: Allowed memory size of 268435456 bytes exhausted
 *     in vendor/symfony/process/Pipes/UnixPipes.php
 *
 * The headers had already gone out, so the client got a 200 carrying a
 * `Content-Length` it would never receive — ERR_INVALID_RESPONSE in a browser.
 * On a real 6.48 GB file it died at 1.27 GB, 3.17 GB and 1.41 GB on three
 * consecutive attempts, because where it dies depends on how fast the client
 * drains.
 *
 * These tests use a *slow* consumer on purpose. A test that drains as fast as
 * it can is the one condition under which the broken version also passes.
 */

/**
 * Silences the server-ops channel.
 *
 * `Log::spy()` is not enough: `stream()` logs through `Log::channel(...)`, and
 * a spy returns null for that, so the method under test dies on the line that
 * records its own result.
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

    // A ceiling well under the file size. The old implementation accumulated
    // the whole of what `cat` produced ahead of the consumer, so this is the
    // assertion it cannot satisfy — and it is expressed as a *limit* rather
    // than an exact figure because the number is allowed to improve.
    $before = memory_get_usage(true);
    $bytes = 0;

    foreach ($ops->stream(['cat', $path]) as $chunk) {
        $bytes += strlen($chunk);

        // The slow consumer. Without it, `cat` on a temp file never gets far
        // enough ahead for the difference to show.
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

    // Byte-exact, because the failure this replaces produced a *plausible*
    // prefix — a truncated ISO is a file that downloads and then will not
    // mount, which is worse than one that fails outright.
    expect($seen)->toBe($content)
        ->and(strlen($seen))->toBe(strlen($content));

    @unlink($path);
});

it('records a failing command with what it managed to send', function () {
    // The headers are already gone by the time a streamed command fails, so
    // the response cannot become an error. The log is the only record that a
    // download was truncated, which is why it carries the byte count.
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

    // `sleep` produces nothing and holds the pipe open. Without an idle bound
    // this blocks until the wall-clock timeout — which for a download has to
    // be long, so the request would occupy a worker for an hour.
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
    // The class documents "Never throws" and every caller relies on it: they
    // read `failed()` and raise their own translated exception. A command
    // whose working directory the panel user cannot enter fails inside
    // `posix_spawn`, before the process exists, and the raw Symfony exception
    // went straight past every caller's handling and reached the user as a
    // stack trace.
    //
    // Found with `docker compose config` and a cwd of /home/ubuntu, which is
    // 0750 and not the panel's to enter. Nothing about it is Docker-specific.
    silenceServerOps();

    $result = app(ServerOps::class)->run(
        ['true'],
        ['feature' => 'test', 'op' => 'test'],
        cwd: '/definitely/not/a/directory',
    );

    // Failed and *unanswered*, which is the distinction callers act on: a
    // command that ran and said no is not the same as one that never ran.
    // The reason itself is in the server-ops log against the reference —
    // there is no process to read stderr from when the spawn is what failed.
    expect($result->failed())->toBeTrue()
        ->and($result->answered)->toBeFalse()
        ->and($result->reference)->not->toBe('');
});
