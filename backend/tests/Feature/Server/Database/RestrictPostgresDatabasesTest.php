<?php

use App\Models\Database;
use App\Models\DatabaseUser;
use Illuminate\Support\Facades\Process;

/*
 * Bug #35 on databases made before the fix: PUBLIC could CONNECT, so one
 * site's user could open another site's database. `databases:restrict-postgres`
 * revokes that, keeping every role that really uses each database.
 *
 * psql is faked: each SQL batch is recorded, and the answers stand in for
 * the server's ACL and for the roles found using the database.
 */

/**
 * @param  array<string, string>  $publicCanConnect  database => 't' | 'f'
 * @param  array<string, string>  $rolesUsing  database => psql output
 */
function fakePostgres(array $publicCanConnect, array $rolesUsing = [], bool $installed = true): ArrayObject
{
    $sql = new ArrayObject;

    Process::fake(function ($process) use ($sql, $publicCanConnect, $rolesUsing, $installed) {
        $cmd = $process->command;

        if (($cmd[0] ?? '') === 'dpkg-query') {
            return $installed
                ? Process::result(output: 'install ok installed')
                : Process::result(exitCode: 1);
        }

        $input = (string) ($process->input ?? '');
        $database = collect($cmd)->first(fn ($a) => str_starts_with((string) $a, '--dbname='));
        $database = substr((string) $database, 9);
        $sql[] = ['db' => $database, 'sql' => $input];

        if (str_contains($input, 'aclexplode(datacl)')) {
            preg_match("/datname = '([^']+)'/", $input, $m);

            return Process::result(output: $publicCanConnect[$m[1]] ?? '');
        }

        if (str_contains($input, 'FROM pg_roles r')) {
            return Process::result(output: $rolesUsing[$database] ?? '');
        }

        return Process::result(output: '1');
    });

    return $sql;
}

function restrictablePgDatabase(string $name, array $users = []): Database
{
    $database = Database::create(['name' => $name, 'engine' => 'postgresql']);

    foreach ($users as $user) {
        DatabaseUser::create(['database_id' => $database->id, 'username' => $user, 'password' => 'x', 'host' => 'localhost']);
    }

    return $database;
}

function changesTo(ArrayObject $sql, string $database): array
{
    return collect($sql)->pluck('sql')
        ->filter(fn (string $s) => str_contains($s, 'REVOKE') && str_contains($s, "\"{$database}\""))
        ->values()->all();
}

it('grants the roles that use a database, then revokes PUBLIC, in one transaction', function () {
    restrictablePgDatabase('shop', ['shop_user']);
    $sql = fakePostgres(['shop' => 't'], ['shop' => "shop_user\nreporting\n"]);

    $this->artisan('databases:restrict-postgres')
        ->expectsOutputToContain('1 restricted to their own users')
        ->assertSuccessful();

    $change = changesTo($sql, 'shop');

    expect($change)->toHaveCount(1)
        ->and($change[0])->toBe('BEGIN; GRANT CONNECT, TEMPORARY ON DATABASE "shop" TO "shop_user", "reporting"; REVOKE CONNECT, TEMPORARY ON DATABASE "shop" FROM PUBLIC; COMMIT;');
});

it('asks inside the database which roles use it, naming the recorded users too', function () {
    restrictablePgDatabase('shop', ['shop_user']);
    $sql = fakePostgres(['shop' => 't'], ['shop' => "shop_user\n"]);

    $this->artisan('databases:restrict-postgres')->assertSuccessful();

    // Inside the database: ownership and grants live in its own catalogs.
    $query = collect($sql)->first(fn ($q) => str_contains($q['sql'], 'FROM pg_roles r'));

    expect($query['db'])->toBe('shop')
        ->and($query['sql'])->toContain("r.rolname IN ('shop_user')")
        ->and($query['sql'])->toContain('NOT r.rolsuper');
});

it('leaves a database alone when PUBLIC already has no CONNECT', function () {
    restrictablePgDatabase('fresh', ['fresh_user']);
    $sql = fakePostgres(['fresh' => 'f']);

    $this->artisan('databases:restrict-postgres')
        ->expectsOutputToContain('0 restricted to their own users, 1 already restricted')
        ->assertSuccessful();

    expect(changesTo($sql, 'fresh'))->toBe([]);
});

it('touches only PostgreSQL databases the panel lists', function () {
    Database::create(['name' => 'blog', 'engine' => 'mariadb']);
    $sql = fakePostgres([]);

    $this->artisan('databases:restrict-postgres')
        ->expectsOutputToContain('No PostgreSQL databases')
        ->assertSuccessful();

    expect(collect($sql)->pluck('sql')->filter(fn ($s) => str_contains($s, 'REVOKE'))->all())->toBe([]);
});

it('does nothing, and does not fail, where PostgreSQL is not installed', function () {
    restrictablePgDatabase('shop');
    $sql = fakePostgres(['shop' => 't'], installed: false);

    $this->artisan('databases:restrict-postgres')->assertSuccessful();

    expect(changesTo($sql, 'shop'))->toBe([]);
});

it('reports a database it could not change and carries on with the rest', function () {
    restrictablePgDatabase('broken');
    restrictablePgDatabase('shop');

    Process::fake(function ($process) {
        $input = (string) ($process->input ?? '');

        return match (true) {
            ($process->command[0] ?? '') === 'dpkg-query' => Process::result(output: 'install ok installed'),
            str_contains($input, 'aclexplode(datacl)') => Process::result(output: 't'),
            str_contains($input, 'REVOKE') && str_contains($input, '"broken"') => Process::result(exitCode: 3, errorOutput: 'ERROR'),
            default => Process::result(output: '1'),
        };
    });

    $this->artisan('databases:restrict-postgres')
        ->expectsOutputToContain('1 restricted to their own users, 0 already restricted, 1 failed')
        ->expectsOutputToContain('broken')
        ->assertSuccessful();
});
