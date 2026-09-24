<?php

use App\Models\DatabaseConnection;
use App\Services\Server\Databases\PgsqlEngine;
use App\Services\Server\Databases\SqlEngine;
use App\Services\Server\ServerOps;
use Illuminate\Support\Facades\Process;

/*
 * The accounts Server Sync offers as database users. Both engines got this
 * wrong on a real server (2026-09-24): MariaDB offered none at all, and
 * PostgreSQL offered every login role for every database, the superusers
 * included.
 */
beforeEach(function () {
    config(['server.databases.auth_file_dir' => sys_get_temp_dir()]);
});

function listingSqlEngine(): SqlEngine
{
    return new SqlEngine(new DatabaseConnection([
        'engine' => 'mariadb',
        'connection_type' => 'tcp',
        'host' => '127.0.0.1',
        'port' => 3306,
        'username' => 'panel',
        'password' => 'secret',
    ]), app(ServerOps::class));
}

function listingPgEngine(): PgsqlEngine
{
    return new PgsqlEngine(new DatabaseConnection([
        'engine' => 'postgresql',
        'connection_type' => 'tcp',
        'host' => '127.0.0.1',
        'port' => 5432,
        'username' => 'panel_admin',
        'password' => 'secret',
    ]), app(ServerOps::class));
}

/*
 * The fake answers the way the real `mysql --batch` client does. Between
 * columns it prints a real tab. Inside a value it escapes one as the two
 * characters `\t`, which is what CONCAT(user, '\t', host) produced, and why
 * the account it named did not exist.
 */
it('finds a MariaDB user and the database it was granted', function () {
    Process::fake(function ($process) {
        $sql = (string) $process->input;

        if (str_contains($sql, "CONCAT(user, '\t', host)")) {
            return Process::result(output: "brown_user\\tlocalhost\n");
        }

        if (str_contains($sql, 'SELECT user, host FROM mysql.user')) {
            return Process::result(output: "brown_user\tlocalhost\nroot\tlocalhost\n");
        }

        if (str_contains($sql, "SHOW GRANTS FOR 'brown_user'@'localhost'")) {
            return Process::result(output: "GRANT USAGE ON *.* TO `brown_user`@`localhost`\n"
                ."GRANT ALL PRIVILEGES ON `brown_db`.* TO `brown_user`@`localhost`\n");
        }

        // SHOW GRANTS for an account that does not exist.
        return Process::result(exitCode: 1, errorOutput: 'ERROR 1141 (42000): There is no such grant defined');
    });

    expect(listingSqlEngine()->listUsers())->toBe([
        ['username' => 'brown_user', 'host' => 'localhost', 'databases' => ['brown_db']],
    ]);
});

it('never offers a PostgreSQL superuser, which is postgres and the panel\'s own account', function () {
    $ran = collect();
    Process::fake(function ($process) use ($ran) {
        $ran->push((string) $process->input);

        return Process::result(output: '');
    });

    listingPgEngine()->listUsers();

    expect($ran->first())->toContain('rolcanlogin = true')
        ->and($ran->first())->toContain('rolsuper = false');
});

// CONNECT is granted to PUBLIC by default, so asking "can this role connect"
// answered yes for every role and every database. Owned, or granted by name.
it('ties a PostgreSQL user only to databases it owns or was granted by name', function () {
    $ran = collect();
    Process::fake(function ($process) use ($ran) {
        $sql = (string) $process->input;
        $ran->push($sql);

        return Process::result(output: str_contains($sql, 'FROM pg_roles WHERE rolcanlogin') ? "shop_user\n" : "shop\n");
    });

    $users = listingPgEngine()->listUsers();

    $databasesQuery = $ran->first(fn (string $sql) => str_contains($sql, 'pg_database'));

    expect($users)->toBe([['username' => 'shop_user', 'host' => 'localhost', 'databases' => ['shop']]])
        ->and($databasesQuery)->not->toContain('has_database_privilege')
        ->and($databasesQuery)->toContain('d.datdba')
        ->and($databasesQuery)->toContain('aclexplode(d.datacl)');
});
