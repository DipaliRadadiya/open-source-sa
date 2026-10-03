<?php

use App\Models\Database;
use App\Models\User;
use App\Support\CommandRedactor;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/*
 * Bug #22: a failed CREATE USER or password change put the password into the
 * server log and onto Admin → Error logs, because the engine repeats the
 * statement in its error message and stderr was logged as it came.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    config(['server.databases.auth_file_dir' => sys_get_temp_dir()]);

    $this->dir = storage_path('logs/db-redaction-'.getmypid());
    File::deleteDirectory($this->dir);
    File::makeDirectory($this->dir, 0755, true);
    config(['logging.channels.server-ops.path' => $this->dir.'/server-ops.log']);
    Log::forgetChannel('server-ops');
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

it('keeps the password of a failed CREATE USER out of the log and the Error Log screen', function () {
    // What MySQL prints for a statement it cannot parse: the statement itself.
    Process::fake(function ($process) {
        $sql = (string) ($process->input ?? '');

        return str_contains($sql, 'CREATE USER')
            ? Process::result(errorOutput: "ERROR 1064 (42000) at line 1: You have an error in your SQL syntax; check the manual near 'IDENTIFIED BY 'Leak3dPassw0rd'; GRANT' at line 1\n", exitCode: 1)
            : Process::result(output: str_contains($sql, 'mysql.user') ? 'user_free' : '1');
    });
    $db = Database::create(['name' => 'shop', 'engine' => 'mysql']);
    $token = $this->admin->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/databases/{$db->id}/users", ['username' => 'shop_user', 'password' => 'Leak3dPassw0rd', 'connection_preference' => 'localhost'])
        ->assertStatus(500);

    $screen = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/admin/error-logs')->assertOk();

    expect($screen->getContent())->not->toContain('Leak3dPassw0rd')->toContain('[REDACTED]')
        ->and(File::get($this->dir.'/server-ops.log'))->not->toContain('Leak3dPassw0rd');
});

it('redacts every form the engines send', function (string $line, string $secret) {
    expect(CommandRedactor::line($line))->not->toContain($secret)->toContain('[REDACTED]');
})->with([
    'MySQL, as sent' => ["CREATE USER 'a'@'localhost' IDENTIFIED BY 'Se\\'cret1'; FLUSH PRIVILEGES;", 'cret1'],
    'MySQL, quoted in an error' => ["near 'IDENTIFIED BY 'Secret2'' at line 1", 'Secret2'],
    'MySQL, with a plugin' => ["ALTER USER x IDENTIFIED WITH mysql_native_password BY 'Secret3'", 'Secret3'],
    'MySQL, SET PASSWORD' => ["SET PASSWORD FOR x = 'Secret4'", 'Secret4'],
    'PostgreSQL' => ["CREATE ROLE \"u\" WITH LOGIN PASSWORD 'Sec''ret5';", 'ret5'],
    'PostgreSQL, escape string' => ["ALTER ROLE u WITH PASSWORD E'Secret6'", 'Secret6'],
    'MongoDB' => ['db.createUser({ user: "u", pwd: "Sec\\"ret7", roles: [] })', 'ret7'],
    'MongoDB, JSON' => ['{"pwd":"Secret8"}', 'Secret8'],
]);

it('leaves an ordinary message alone', function () {
    expect(CommandRedactor::line('ERROR 1396 (HY000): Operation CREATE USER failed for shop_user@localhost'))
        ->toBe('ERROR 1396 (HY000): Operation CREATE USER failed for shop_user@localhost');
});
