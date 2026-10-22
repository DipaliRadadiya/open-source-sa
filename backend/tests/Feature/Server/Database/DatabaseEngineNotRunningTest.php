<?php

use App\Models\Database;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/*
 * Creating a database on an engine that does not answer on this server.
 *
 * The create screen offers only running engines, but the API accepted any
 * configured one. `mysql` on a MariaDB server reached MariaDB's port as `root`
 * with no password and came back a 500 "operation failed" (seen live on the
 * Apache test server, 2026-10-22). Now a 422 on `engine`, before any SQL runs.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->token = User::factory()->admin()->create()->createToken('t')->plainTextToken;
    config(['server.databases.auth_file_dir' => sys_get_temp_dir()]);
});

it('refuses an engine that does not answer with a 422 naming it, and runs no SQL', function () {
    Process::fake([
        '*' => Process::result(errorOutput: "ERROR 1698 (28000): Access denied for user 'root'@'localhost'\n", exitCode: 1),
    ]);

    test()->withHeaders(['Authorization' => 'Bearer '.test()->token])->postJson('/api/databases', [
        'name' => 'shop', 'engine' => 'mysql',
    ])->assertStatus(422)
        ->assertJsonValidationErrors('engine')
        ->assertJsonPath('errors.engine.0', __('errors/database.engine_not_running', ['engine' => 'MySQL']));

    expect(Database::count())->toBe(0);
    Process::assertNotRan(fn ($process) => str_contains((string) ($process->input ?? ''), 'CREATE DATABASE'));
});

it('still creates the database on an engine that answers', function () {
    Process::fake(function ($process) {
        return str_contains((string) ($process->input ?? ''), 'VERSION()')
            ? Process::result(output: '10.11.14-MariaDB')
            : Process::result(exitCode: 0);
    });

    test()->withHeaders(['Authorization' => 'Bearer '.test()->token])->postJson('/api/databases', [
        'name' => 'shop', 'engine' => 'mariadb',
    ])->assertStatus(201)->assertJsonPath('database.engine', 'mariadb');

    Process::assertRan(fn ($process) => str_contains((string) ($process->input ?? ''), 'CREATE DATABASE'));
});

it('probes only the requested engine, once', function () {
    Process::fake(function ($process) {
        return str_contains((string) ($process->input ?? ''), 'VERSION()')
            ? Process::result(output: '10.11.14-MariaDB')
            : Process::result(exitCode: 0);
    });

    test()->withHeaders(['Authorization' => 'Bearer '.test()->token])->postJson('/api/databases', [
        'name' => 'shop', 'engine' => 'mariadb',
    ])->assertStatus(201);

    Process::assertRanTimes(fn ($process) => str_contains((string) ($process->input ?? ''), 'VERSION()'), 1);
    Process::assertNotRan(fn ($process) => ($process->command[0] ?? '') === 'mongosh');
});

it('does not probe at all for an engine the panel does not know', function () {
    Process::fake();

    test()->withHeaders(['Authorization' => 'Bearer '.test()->token])->postJson('/api/databases', [
        'name' => 'shop', 'engine' => 'oracle',
    ])->assertStatus(422)->assertJsonValidationErrors('engine');

    Process::assertNothingRan();
});

it('has the message in every locale', function (string $locale) {
    expect(trans('errors/database.engine_not_running', ['engine' => 'MySQL'], $locale))
        ->not->toBe('errors/database.engine_not_running')
        ->toContain('MySQL');
})->with(['de', 'en', 'es', 'fr', 'hi', 'ja', 'pt', 'ru']);
