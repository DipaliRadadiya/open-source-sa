<?php

use App\Models\DatabaseConnection;
use App\Services\Server\Databases\MongoEngine;
use App\Services\Server\ServerOps;
use Illuminate\Support\Facades\Process;

/*
 * Summing the opcounters inside mongosh concatenated their digits: mongosh keeps them as BSON
 * `Long` objects, so `+` falls back to `toString()` (0, 1, 0, 0, 50000 gave "010050000", not
 * 50001) and `||0` does not help because `Long(0)` is truthy.
 *
 * These tests pin the parse, not the shell: they feed the stdout shapes mongosh produces and
 * assert the totals.
 */

beforeEach(function () {
    config(['server.databases.auth_file_dir' => sys_get_temp_dir()]);

    $this->connection = new DatabaseConnection([
        'engine' => 'mongodb',
        'connection_type' => 'tcp',
        'host' => '127.0.0.1',
        'port' => 27017,
        'username' => 'panel',
        'password' => 'secret',
    ]);
});

/** One value per line, in the order status() prints them. */
function fakeMongoStatus(array $lines): void
{
    Process::fake(fn () => Process::result(output: implode("\n", $lines)."\n"));
}

function mongoEngine(): MongoEngine
{
    return new MongoEngine(test()->connection, app(ServerOps::class));
}

it('adds the opcounters instead of gluing their digits together', function () {
    // connections.current, connections.available, uptime, then the five opcounters; the
    // counters here are the case that concatenated to "010050000".
    fakeMongoStatus([12, 838848, 3600, 0, 1, 0, 0, 50000]);

    $status = mongoEngine()->status();

    expect($status['queries'])->toBe(50001)
        ->and($status['connections'])->toBe(12)
        ->and($status['max_connections'])->toBe(838848)
        ->and($status['uptime_seconds'])->toBe(3600);
});

it('keeps a zero counter as zero rather than as a digit in a longer number', function () {
    // Concatenation returned "00000", which casts to 0 as well, so this only means something
    // next to the case above, where zeros sit between non-zero counters.
    fakeMongoStatus([1, 838848, 60, 0, 0, 0, 0, 0]);

    expect(mongoEngine()->status()['queries'])->toBe(0);
});

it('totals counters of differing digit lengths', function () {
    // A counter gaining a digit shifted every counter left of it and the delta exploded. The sum
    // must depend only on the values, never on how wide they are printed.
    fakeMongoStatus([4, 838848, 7200, 6, 1_037, 12, 3, 999_301]);

    expect(mongoEngine()->status()['queries'])->toBe(6 + 1_037 + 12 + 3 + 999_301);
});

it('survives a counter the server did not report', function () {
    // `o.delete` is absent on some builds; status() prints 0 for null so the
    // line count stays fixed and the fields after it do not shift.
    fakeMongoStatus([2, 838848, 90, 5, 10, 0, 0, 25]);

    expect(mongoEngine()->status()['queries'])->toBe(40);
});

it('reports zeros rather than guesses when mongosh fails', function () {
    Process::fake(fn () => Process::result(exitCode: 1, errorOutput: 'connection refused'));

    $status = mongoEngine()->status();

    expect($status['queries'])->toBe(0)
        ->and($status['connections'])->toBe(0)
        ->and($status['uptime_seconds'])->toBe(0);
});

it('leaves threads_running and slow_queries unknown, not zero', function () {
    // MongoDB reports neither. A zero would read as "none", which is a
    // measurement the panel never took.
    fakeMongoStatus([3, 838848, 120, 1, 2, 3, 4, 5]);

    $status = mongoEngine()->status();

    expect($status['threads_running'])->toBeNull()
        ->and($status['slow_queries'])->toBeNull();
});
