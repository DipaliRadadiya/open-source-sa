<?php

use App\Models\DatabaseConnection;
use App\Services\Server\Databases\MongoEngine;
use App\Services\Server\Databases\SqlEngine;
use App\Services\Server\ServerOps;
use Illuminate\Support\Facades\Process;

/*
 * The panel used to appear in its own process list.
 *
 * `SHOW FULL PROCESSLIST` includes the thread running it, and `currentOp`
 * includes the operation asking. Neither row is idle, so both survived the
 * active-query filter: a quiet database reported one permanently running
 * query, which was the panel watching itself. Each also got a Stop Query
 * button that could only fail — the KILL runs on a new connection, by which
 * time that thread is gone.
 *
 * These tests assert the exclusion is asked of the server rather than pattern
 * matched out of the results, because an operator running `SHOW FULL
 * PROCESSLIST` by hand is a real thing to see in this list.
 */

beforeEach(function () {
    config(['server.databases.auth_file_dir' => sys_get_temp_dir()]);
});

function sqlEngineFor(string $engine = 'mysql'): SqlEngine
{
    return new SqlEngine(new DatabaseConnection([
        'engine' => $engine,
        'connection_type' => 'tcp',
        'host' => '127.0.0.1',
        'port' => 3306,
        'username' => 'panel',
        'password' => 'secret',
    ]), app(ServerOps::class));
}

function mongoEngineFor(): MongoEngine
{
    return new MongoEngine(new DatabaseConnection([
        'engine' => 'mongodb',
        'connection_type' => 'tcp',
        'host' => '127.0.0.1',
        'port' => 27017,
        'username' => 'panel',
        'password' => 'secret',
    ]), app(ServerOps::class));
}

it('asks the SQL server to exclude our own connection', function () {
    Process::fake(fn () => Process::result(output: ''));

    sqlEngineFor()->processes();

    Process::assertRan(fn ($process) => str_contains((string) $process->input, 'ID <> CONNECTION_ID()')
        && str_contains((string) $process->input, 'information_schema.PROCESSLIST'));
});

it('no longer asks for the statement that listed itself', function () {
    Process::fake(fn () => Process::result(output: ''));

    sqlEngineFor()->processes();

    Process::assertRan(fn ($process) => ! str_contains((string) $process->input, 'SHOW FULL PROCESSLIST'));
});

it('still parses the columns in the order the batch client prints them', function () {
    // ID USER HOST db COMMAND TIME STATE INFO — NULL selected as the literal
    // the client would have printed, so the parse is unchanged.
    Process::fake(fn () => Process::result(output: implode("\t", [
        '42', 'shop', 'localhost:33065', 'shop_db', 'Query', '87',
        'Sending data', 'SELECT * FROM orders',
    ])."\n".implode("\t", [
        '43', 'root', 'localhost', 'NULL', 'Sleep', '3', '', 'NULL',
    ])."\n"));

    $processes = sqlEngineFor()->processes();

    expect($processes)->toHaveCount(2)
        ->and($processes[0]['id'])->toBe('42')
        ->and($processes[0]['db'])->toBe('shop_db')
        ->and($processes[0]['time'])->toBe(87)
        ->and($processes[0]['query'])->toBe('SELECT * FROM orders')
        ->and($processes[1]['db'])->toBeNull()
        ->and($processes[1]['query'])->toBeNull();
});

it('keeps an operator running the statement themselves visible', function () {
    // The exclusion is by connection, not by text. Somebody else running this
    // is a real row and must not be filtered out.
    Process::fake(fn () => Process::result(output: implode("\t", [
        '51', 'dba', 'localhost', 'NULL', 'Query', '0', '', 'SHOW FULL PROCESSLIST',
    ])."\n"));

    $processes = sqlEngineFor()->processes();

    expect($processes)->toHaveCount(1)
        ->and($processes[0]['query'])->toBe('SHOW FULL PROCESSLIST');
});

it('excludes our own operations from the Mongo process list', function () {
    // mongosh is handed a temp file that run() unlinks, so the script cannot be
    // asserted through Process::assertRan the way the SQL statement can. The
    // source is the artefact that ships, so that is what is pinned.
    $source = file_get_contents(app_path('Services/Server/Databases/MongoEngine.php'));

    expect($source)->toContain('$ownOps: true')
        ->and($source)->toContain('.filter(o => String(o.connectionId) !== own)');
});

it('compares the Mongo connection id as a string, not by object identity', function () {
    // Two BSON Longs holding the same number are different objects, so `!==`
    // between them is always true. Comparing them directly would filter
    // nothing while appearing to work — the same class of bug as the
    // opcounter concatenation.
    $source = file_get_contents(app_path('Services/Server/Databases/MongoEngine.php'));

    expect($source)->toContain('String(o.connectionId) !== own')
        ->and($source)->toContain('$ownOps: true');
});

it('parses Mongo ops into the shared process shape', function () {
    Process::fake(fn () => Process::result(output: implode("\t", [
        '7301', '10.0.0.4:51222', 'shop.orders', 'query', '12', 'conn91',
    ])."\n"));

    $processes = mongoEngineFor()->processes();

    expect($processes)->toHaveCount(1)
        ->and($processes[0]['id'])->toBe('7301')
        ->and($processes[0]['time'])->toBe(12);
});
