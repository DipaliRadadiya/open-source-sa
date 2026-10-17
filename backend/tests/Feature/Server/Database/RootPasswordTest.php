<?php

use App\Models\ActivityLog;
use App\Models\DatabaseConnection;
use App\Models\User;
use App\Services\Server\Databases\SqlPasswordCheck;
use App\Services\Server\Databases\SqlRootAccess;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

/*
 * MySQL/MariaDB root password: set, change or reset (2026-10-17).
 *
 * The fake below is a small model of root's login, not a list of canned
 * answers: socket login works until something takes it away, a password works
 * only if it is the one root has, and the ALTER statements change that state.
 * That is what lets these tests catch the two ways this feature can hurt a
 * server — MariaDB losing socket login, and a password stored that the server
 * never accepted.
 */

final class RootLogin
{
    public static string $flavor = 'mariadb';

    public static bool $socket = true;

    public static ?string $password = null;

    public static bool $applyFails = false;

    public static string $applyError = '';

    /** @var list<string> */
    public static array $statements = [];

    /** @var list<array<int, string>> */
    public static array $argv = [];

    public static function reset(string $flavor = 'mariadb'): void
    {
        self::$flavor = $flavor;
        self::$socket = true;
        self::$password = null;
        self::$applyFails = false;
        self::$applyError = '';
        self::$statements = [];
        self::$argv = [];
    }
}

function fakeRootServer(string $flavor = 'mariadb', bool $installed = true): void
{
    RootLogin::reset($flavor);

    Process::fake(function ($process) use ($installed) {
        $cmd = $process->command;
        RootLogin::$argv[] = $cmd;
        $bin = $cmd[0] ?? '';

        if ($bin === 'dpkg-query') {
            return $installed
                ? Process::result(output: 'install ok installed')
                : Process::result(exitCode: 1);
        }

        if (! in_array($bin, ['mysql', 'mariadb'], true)) {
            return Process::result();
        }

        // Signed in? Over the socket as OS root, or with a password from the
        // option file — read while it exists, as the real client would.
        $file = collect($cmd)->first(fn ($arg) => str_starts_with($arg, '--defaults-extra-file='));
        if ($file !== null) {
            preg_match('/password="(.*)"/', (string) @file_get_contents(substr($file, strlen('--defaults-extra-file='))), $m);
            $in = isset($m[1]) && RootLogin::$password !== null && stripslashes($m[1]) === RootLogin::$password;
        } else {
            $in = RootLogin::$socket;
        }

        if (! $in) {
            return Process::result(errorOutput: "ERROR 1698 (28000): Access denied for user 'root'@'localhost'", exitCode: 1);
        }

        $sql = (string) $process->input;
        RootLogin::$statements[] = $sql;

        if (str_contains($sql, 'VERSION()')) {
            return Process::result(output: RootLogin::$flavor === 'mariadb' ? '11.8.6-MariaDB-5ubuntu0.1' : '8.0.43-0ubuntu0.24.04.1');
        }

        if (str_contains($sql, 'SHOW CREATE USER')) {
            return Process::result(output: RootLogin::$flavor === 'mariadb'
                ? "CREATE USER `root`@`localhost` IDENTIFIED VIA mysql_native_password USING 'invalid' OR unix_socket\n"
                : "CREATE USER `root`@`localhost` IDENTIFIED WITH 'auth_socket' REQUIRE NONE PASSWORD EXPIRE DEFAULT ACCOUNT UNLOCK\n");
        }

        if (preg_match("/ALTER USER 'root'@'localhost' IDENTIFIED (VIA|WITH) .*?(?:PASSWORD\\(|BY )'((?:[^'\\\\]|\\\\.)*)'/s", $sql, $m)) {
            if (RootLogin::$applyFails) {
                return Process::result(errorOutput: RootLogin::$applyError, exitCode: 1);
            }

            RootLogin::$password = stripslashes($m[2]);
            // MariaDB: kept only when the statement keeps it.
            RootLogin::$socket = str_contains($sql, 'OR unix_socket');
        }

        if (str_starts_with(trim($sql), 'ALTER USER `root`@`localhost`')) {
            // The snapshot put back.
            RootLogin::$socket = str_contains($sql, 'unix_socket') || str_contains($sql, 'auth_socket');
            RootLogin::$password = null;
        }

        return Process::result();
    });

    app()->instance(SqlPasswordCheck::class, new class extends SqlPasswordCheck
    {
        public bool $broken = false;

        public function works(string $engine, string $password): bool
        {
            return ! $this->broken && RootLogin::$password !== null && $password === RootLogin::$password;
        }
    });
}

function rootAuth(?User $user = null): array
{
    return ['Authorization' => 'Bearer '.($user ?? test()->admin)->createToken('t')->plainTextToken];
}

function changeRoot(string $engine, string $password, ?string $confirm = null, ?User $user = null)
{
    return test()->withHeaders(rootAuth($user))->putJson("/api/databases/connections/{$engine}/root-password", [
        'password' => $password,
        'password_confirmation' => $confirm ?? $password,
    ]);
}

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    config(['server.databases.auth_file_dir' => sys_get_temp_dir()]);
});

describe('MariaDB', function () {
    it('sets root\'s password and keeps socket login, so OS root and the installer still get in', function () {
        fakeRootServer('mariadb');

        changeRoot('mariadb', "N3w-root pass'with\"quotes")
            ->assertOk()
            ->assertJsonPath('mariadb.has_root_password', true)
            ->assertJsonMissingPath('mariadb.root_password');

        expect(RootLogin::$password)->toBe("N3w-root pass'with\"quotes")
            ->and(RootLogin::$socket)->toBeTrue();

        $alter = collect(RootLogin::$statements)->first(fn ($sql) => str_contains($sql, "ALTER USER 'root'"));
        expect($alter)->toContain('IDENTIFIED VIA mysql_native_password USING PASSWORD(')
            ->and($alter)->toContain('OR unix_socket');
    });

    it('stores the password encrypted, and only after the server accepted it', function () {
        fakeRootServer('mariadb');

        changeRoot('mariadb', 'Correct-horse-42')->assertOk();

        $raw = DB::table('database_connections')->where('engine', 'mariadb')->value('root_password');
        expect($raw)->not->toBeNull()->not->toContain('Correct-horse-42')
            ->and(DatabaseConnection::where('engine', 'mariadb')->first()->root_password)->toBe('Correct-horse-42');
    });

    it('leaves the panel\'s own account alone', function () {
        fakeRootServer('mariadb');
        DatabaseConnection::create(['engine' => 'mariadb', 'connection_type' => 'socket', 'username' => 'panel_abcdefghij', 'password' => 'panel-secret']);

        changeRoot('mariadb', 'Correct-horse-42')->assertOk();

        $connection = DatabaseConnection::where('engine', 'mariadb')->first();
        expect($connection->username)->toBe('panel_abcdefghij')
            ->and($connection->password)->toBe('panel-secret');
        expect(collect(RootLogin::$statements)->contains(fn ($sql) => str_contains($sql, 'panel_abcdefghij')))->toBeFalse();
    });

    it('updates the panel\'s connection too when the panel signs in as root', function () {
        fakeRootServer('mariadb');
        DatabaseConnection::create(['engine' => 'mariadb', 'connection_type' => 'socket', 'username' => 'root', 'password' => null]);

        changeRoot('mariadb', 'Correct-horse-42')->assertOk();

        expect(DatabaseConnection::where('engine', 'mariadb')->first()->password)->toBe('Correct-horse-42');
    });
});

describe('MySQL', function () {
    it('sets the password with caching_sha2_password, after which the panel reaches root with the stored one', function () {
        fakeRootServer('mysql');

        changeRoot('mysql', 'First-password-1')->assertOk();

        $alter = collect(RootLogin::$statements)->first(fn ($sql) => str_contains($sql, "ALTER USER 'root'"));
        expect($alter)->toContain('IDENTIFIED WITH caching_sha2_password BY')
            // MySQL cannot keep socket login; the fake models that.
            ->and(RootLogin::$socket)->toBeFalse();

        // Reset again with no socket: only the stored password gets the panel in.
        changeRoot('mysql', 'Second-password-2')->assertOk();

        expect(RootLogin::$password)->toBe('Second-password-2')
            ->and(DatabaseConnection::where('engine', 'mysql')->first()->root_password)->toBe('Second-password-2');
    });

    it('answers the server\'s password policy as a 422 the user can act on', function () {
        fakeRootServer('mysql');
        RootLogin::$applyFails = true;
        RootLogin::$applyError = 'ERROR 1819 (HY000): Your password does not satisfy the current policy requirements';

        changeRoot('mysql', 'weakbutlong12')
            ->assertStatus(422)
            ->assertJsonPath('errors.password.0', __('errors/database.root_password_policy'));

        expect(DatabaseConnection::where('engine', 'mysql')->value('root_password'))->toBeNull();
    });
});

it('puts root\'s previous login back and stores nothing when the new password does not work', function () {
    fakeRootServer('mariadb');
    app(SqlPasswordCheck::class)->broken = true;

    changeRoot('mariadb', 'Correct-horse-42')
        ->assertStatus(422)
        ->assertJsonPath('errors.password.0', __('errors/database.root_password_not_applied'));

    expect(collect(RootLogin::$statements)->last())
        ->toStartWith("ALTER USER `root`@`localhost` IDENTIFIED VIA mysql_native_password USING 'invalid' OR unix_socket");
    expect(RootLogin::$socket)->toBeTrue()
        ->and(RootLogin::$password)->toBeNull()
        ->and(DatabaseConnection::where('engine', 'mariadb')->value('root_password'))->toBeNull();
    expect(ActivityLog::where('action', 'root_password_changed')->exists())->toBeFalse();
});

it('says plainly when root cannot be reached at all, and changes nothing', function () {
    fakeRootServer('mysql');
    RootLogin::$socket = false;
    RootLogin::$password = 'set-by-someone-else';

    changeRoot('mysql', 'Correct-horse-42')
        ->assertStatus(422)
        ->assertJsonPath('errors.engine.0', __('errors/database.root_unreachable', ['engine' => 'MySQL']));

    expect(RootLogin::$password)->toBe('set-by-someone-else');
});

it('refuses an engine that is not installed', function () {
    fakeRootServer('mysql', installed: false);

    changeRoot('mysql', 'Correct-horse-42')
        ->assertStatus(422)
        ->assertJsonPath('errors.engine.0', __('errors/database.root_engine_not_installed', ['engine' => 'MySQL']));
});

it('is MySQL and MariaDB only', function (string $engine) {
    fakeRootServer();

    changeRoot($engine, 'Correct-horse-42')->assertNotFound();
})->with(['postgresql', 'mongodb', 'oracle']);

it('validates the password', function (array $body, string $field) {
    fakeRootServer();

    test()->withHeaders(rootAuth())->putJson('/api/databases/connections/mariadb/root-password', $body)
        ->assertStatus(422)
        ->assertJsonValidationErrors($field);

    expect(RootLogin::$statements)->toBe([]);
})->with([
    'missing' => [[], 'password'],
    'too short' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
    'not confirmed' => [['password' => 'Correct-horse-42', 'password_confirmation' => 'Correct-horse-43'], 'password'],
    'newline' => [['password' => "Correct-horse\n42", 'password_confirmation' => "Correct-horse\n42"], 'password'],
]);

it('is refused to a user who may only view databases', function () {
    fakeRootServer();
    $viewer = User::factory()->create();
    grantPermission($viewer, 'database', view: true, manage: false);

    changeRoot('mariadb', 'Correct-horse-42', user: $viewer)->assertForbidden();

    expect(RootLogin::$statements)->toBe([]);
});

it('never puts the password on a command line or in the activity log', function () {
    fakeRootServer('mysql');

    changeRoot('mysql', 'Secret-on-argv-77')->assertOk();
    changeRoot('mysql', 'Secret-on-argv-88')->assertOk();

    foreach (RootLogin::$argv as $argv) {
        expect(implode(' ', $argv))->not->toContain('Secret-on-argv');
    }

    $entry = ActivityLog::where('type', 'database')->where('action', 'root_password_changed')->latest('id')->first();
    expect($entry)->not->toBeNull()
        ->and(json_encode($entry->toArray()))->not->toContain('Secret-on-argv');
});

describe('saving root\'s current password (a server moved from v7)', function () {
    it('stores it once the server accepts it', function () {
        fakeRootServer('mysql');
        RootLogin::$socket = false;
        RootLogin::$password = 'from-v7-panel';

        test()->withHeaders(rootAuth())->putJson('/api/databases/connections/mysql', [
            'connection_type' => 'socket', 'socket' => '/var/run/mysqld/mysqld.sock', 'username' => 'root', 'root_password' => 'from-v7-panel',
        ])->assertOk()->assertJsonPath('mysql.has_root_password', true);

        expect(DatabaseConnection::where('engine', 'mysql')->first()->root_password)->toBe('from-v7-panel');

        // And with it stored, the panel reaches root again — and can reset it.
        changeRoot('mysql', 'Correct-horse-42')->assertOk();
        expect(RootLogin::$password)->toBe('Correct-horse-42');
    });

    it('refuses a wrong one and stores nothing', function () {
        fakeRootServer('mysql');
        RootLogin::$password = 'the-real-one';

        test()->withHeaders(rootAuth())->putJson('/api/databases/connections/mysql', [
            'connection_type' => 'socket', 'socket' => '/var/run/mysqld/mysqld.sock', 'root_password' => 'a-wrong-one',
        ])->assertStatus(422)->assertJsonPath('errors.root_password.0', __('errors/database.root_password_wrong'));

        expect(DatabaseConnection::where('engine', 'mysql')->value('root_password'))->toBeNull();
    });

    it('refuses one for an engine with no such account', function () {
        fakeRootServer();

        test()->withHeaders(rootAuth())->putJson('/api/databases/connections/postgresql', [
            'connection_type' => 'tcp', 'host' => '127.0.0.1', 'port' => 5432, 'root_password' => 'whatever-it-is',
        ])->assertStatus(422)->assertJsonValidationErrors('root_password');
    });
});

it('lets the engine installer reach root with the stored password once socket login is gone', function () {
    fakeRootServer('mysql');
    RootLogin::$socket = false;
    RootLogin::$password = 'stored-root-pw';
    DatabaseConnection::create(['engine' => 'mysql', 'connection_type' => 'socket', 'username' => 'root']);
    $connection = DatabaseConnection::where('engine', 'mysql')->first();
    $connection->root_password = 'stored-root-pw';
    $connection->save();

    $result = app(SqlRootAccess::class)->run('mysql', 'SELECT 1;', 'provision_account');

    expect($result->failed())->toBeFalse();
    // Socket first, then the option file — never the password on argv.
    expect(RootLogin::$argv[0])->toContain('--user=root')
        ->and(collect(RootLogin::$argv[1])->contains(fn ($arg) => str_starts_with($arg, '--defaults-extra-file=')))->toBeTrue();
});
