<?php

use App\Models\Database;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/*
 * MongoDB's createCollection does not fail on a database that already exists:
 * it adds to it. The panel's name check only asks its own table, so a database
 * on the server it did not know about was taken for a new one, and when the
 * initial user then failed, the create's cleanup dropped it, data and all
 * (live-reproduced 2026-11-06, reports/api-live-1106). The create now asks the
 * server in the same script and refuses.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;
    config(['server.databases.auth_file_dir' => sys_get_temp_dir()]);
});

/**
 * Fake mongosh by reading the script it is handed. `$onServer` is what
 * listDatabases would answer; `$userFails` breaks the initial user.
 *
 * @param  list<string>  $onServer
 * @param  list<string>  $scripts  every script mongosh ran, in order
 */
function fakeMongo(array $onServer, bool $userFails, array &$scripts): void
{
    Process::fake(function ($process) use ($onServer, $userFails, &$scripts) {
        $command = array_values(array_filter((array) $process->command, fn ($a) => ! in_array($a, ['sudo', '-n'], true)));

        if (($command[0] ?? '') !== 'mongosh') {
            return Process::result();
        }

        $file = $command[array_search('--file', $command, true) + 1] ?? null;
        $script = $file !== null && is_file($file) ? (string) file_get_contents($file) : '';
        $scripts[] = $script;

        if (str_contains($script, 'createCollection("_panel_init")')) {
            foreach ($onServer as $name) {
                if (str_contains($script, 'const candidate = '.json_encode($name).';')) {
                    return Process::result(output: "panel:database_exists\n", exitCode: 3);
                }
            }

            return Process::result();
        }

        if ($userFails && str_contains($script, 'createUser')) {
            return Process::result(exitCode: 1, errorOutput: 'createUser failed');
        }

        return Process::result(output: '1');
    });
}

function mongoDropRan(array $scripts): bool
{
    return collect($scripts)->contains(fn (string $s) => str_contains($s, 'dropDatabase()'));
}

it('refuses a name that already exists on the server, and never drops it', function () {
    $scripts = [];
    fakeMongo(['shop'], userFails: true, scripts: $scripts);

    test()->withHeaders(['Authorization' => 'Bearer '.test()->token])->postJson('/api/databases', [
        'name' => 'shop', 'engine' => 'mongodb',
        'create_user' => ['username' => 'shop_user', 'password' => 'S3cretPass99'],
    ])->assertStatus(422)
        ->assertJsonValidationErrors('name')
        ->assertJsonPath('errors.name.0', __('errors/database.exists_on_server', ['name' => 'shop']));

    expect(mongoDropRan($scripts))->toBeFalse()
        ->and(collect($scripts)->contains(fn (string $s) => str_contains($s, 'createUser')))->toBeFalse()
        ->and(Database::where('name', 'shop')->exists())->toBeFalse();
});

it('still drops a database it created itself when the initial user fails', function () {
    $scripts = [];
    fakeMongo([], userFails: true, scripts: $scripts);

    test()->withHeaders(['Authorization' => 'Bearer '.test()->token])->postJson('/api/databases', [
        'name' => 'shop', 'engine' => 'mongodb',
        'create_user' => ['username' => 'shop_user', 'password' => 'S3cretPass99'],
    ])->assertStatus(500);

    expect(mongoDropRan($scripts))->toBeTrue()
        ->and(Database::where('name', 'shop')->exists())->toBeFalse();
});

it('creates a database whose name is free on the server', function () {
    $scripts = [];
    fakeMongo(['other'], userFails: false, scripts: $scripts);

    test()->withHeaders(['Authorization' => 'Bearer '.test()->token])->postJson('/api/databases', [
        'name' => 'shop', 'engine' => 'mongodb',
    ])->assertStatus(201)
        ->assertJsonPath('database.name', 'shop');

    expect(mongoDropRan($scripts))->toBeFalse();
});

it('asks the server and creates in one script, so a failed check is a failed create', function () {
    $scripts = [];
    fakeMongo([], userFails: false, scripts: $scripts);

    test()->withHeaders(['Authorization' => 'Bearer '.test()->token])->postJson('/api/databases', [
        'name' => 'shop', 'engine' => 'mongodb',
    ])->assertStatus(201);

    $create = collect($scripts)->first(fn (string $s) => str_contains($s, 'createCollection("_panel_init")'));

    expect($create)->toContain('listDatabases')
        ->and(strpos($create, 'listDatabases'))->toBeLessThan(strpos($create, 'createCollection'));
});
