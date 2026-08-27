<?php

use App\Exceptions\Server\Database\EngineInstallException;
use App\Jobs\InstallDatabaseEngine;
use App\Models\DatabaseConnection;
use App\Models\RuntimeInstall;
use App\Models\User;
use App\Services\Runtime\InstallTracker;
use App\Services\Server\Databases\DatabaseManager;
use App\Services\Server\Databases\Installers\EngineInstallerManager;
use Database\Seeders\PermissionSeeder;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;
});

function installer(string $engine = 'mariadb')
{
    return app(EngineInstallerManager::class)->installer($engine);
}

/**
 * A box where the engine is absent, apt succeeds, and the engine answers.
 * `$seen` collects every command so the tests can assert on what ran — and,
 * more importantly, on what was never put on a command line.
 */
function fakeCleanBox(array &$seen, string $installedPackage = ''): void
{
    Process::fake(function ($process) use (&$seen, $installedPackage) {
        $seen[] = $process;
        $command = $process->command;

        if (($command[0] ?? '') === 'dpkg-query') {
            $asked = end($command);

            return Process::result(output: $asked === $installedPackage ? 'install ok installed' : 'unknown ok not-installed');
        }

        return Process::result(exitCode: 0);
    });
}

describe('installing', function () {
    it('installs the package, starts it and stores a credential', function () {
        $seen = [];
        fakeCleanBox($seen);

        installer('mariadb')->install();

        expect(collect($seen)->contains(fn ($p) => $p->command[0] === 'apt-get'
            && in_array('mariadb-server', $p->command, true)))->toBeTrue();
        expect(collect($seen)->contains(fn ($p) => $p->command === ['systemctl', 'enable', '--now', 'mariadb']))->toBeTrue();

        $connection = DatabaseConnection::query()->where('engine', 'mariadb')->firstOrFail();
        expect($connection->username)->toMatch('/^panel_[a-z0-9]{10}$/');
        expect($connection->password)->not->toBeEmpty();
    });

    it('reports the SQL install stages in the order they actually happen', function () {
        $seen = [];
        $steps = [];
        fakeCleanBox($seen);

        installer('mariadb')->install(function (string $step) use (&$steps) {
            $steps[] = $step;
        });

        expect($steps)->toBe([
            'checking_conflicts',
            'preparing',
            'starting_service',
            'verifying_connection',
            'creating_panel_account',
        ]);
    });

    it('never puts the password on a command line', function () {
        // Anything on argv is readable from /proc for the life of the process,
        // and this credential holds GRANT OPTION.
        $seen = [];
        fakeCleanBox($seen);

        installer('mariadb')->install();

        // Already plaintext: the model's `encrypted` cast decrypts on read.
        $decrypted = (string) DatabaseConnection::query()->where('engine', 'mariadb')->first()->password;

        foreach ($seen as $process) {
            expect(implode(' ', $process->command))->not->toContain($decrypted);
        }

        // It went over stdin instead, in a GRANT for our own account.
        expect(collect($seen)->contains(fn ($p) => str_contains((string) $p->input, 'GRANT ALL PRIVILEGES')
            && str_contains((string) $p->input, $decrypted)))->toBeTrue();
    });

    it('never touches the root account', function () {
        // On MariaDB 10.4+ root has password login disabled and authenticates by
        // being OS root. Giving it a password would be creating a secret, not
        // reading one — and would make root usable over TCP.
        $seen = [];
        fakeCleanBox($seen);

        installer('mariadb')->install();

        foreach ($seen as $process) {
            expect((string) $process->input)->not->toContain("'root'@");
            expect((string) $process->input)->not->toContain('ALTER USER root');
        }
    });

    it('keeps the username under 16 characters', function () {
        // MySQL 5.7 capped usernames at 16. An over-long name is silently
        // truncated and then fails authentication, which is miserable to debug.
        $seen = [];
        fakeCleanBox($seen);

        installer('mariadb')->install();

        expect(strlen((string) DatabaseConnection::query()->where('engine', 'mariadb')->value('username')))
            ->toBeLessThanOrEqual(16);
    });

    it('stores the password encrypted', function () {
        $seen = [];
        fakeCleanBox($seen);

        installer('mariadb')->install();

        $raw = (string) DB::table('database_connections')->where('engine', 'mariadb')->value('password');
        // decryptString, not decrypt(): the `encrypted` cast stores a raw string
        // rather than a serialised payload.
        $plain = Crypt::decryptString($raw);

        expect($raw)->not->toBe($plain);
        expect($raw)->not->toContain($plain);
        expect($plain)->toBe((string) DatabaseConnection::query()->where('engine', 'mariadb')->first()->password);
    });
});

describe('running it again', function () {
    it('reuses the stored username instead of minting a second account', function () {
        // The whole point of the random name is that it is not guessable — but a
        // random name plus CREATE USER IF NOT EXISTS would create a *new*
        // full-privilege account on every run, and this is re-runnable.
        $seen = [];
        fakeCleanBox($seen, 'mariadb-server');

        installer('mariadb')->install();
        $first = DatabaseConnection::query()->where('engine', 'mariadb')->value('username');

        installer('mariadb')->install();
        $second = DatabaseConnection::query()->where('engine', 'mariadb')->value('username');

        expect($second)->toBe($first);
        expect(DatabaseConnection::query()->where('engine', 'mariadb')->count())->toBe(1);
    });

    it('rotates the password on a re-run', function () {
        $seen = [];
        fakeCleanBox($seen, 'mariadb-server');

        installer('mariadb')->install();
        $first = DatabaseConnection::query()->where('engine', 'mariadb')->value('password');

        installer('mariadb')->install();
        $second = DatabaseConnection::query()->where('engine', 'mariadb')->value('password');

        expect($second)->not->toBe($first);
    });

    it('does not reuse the seeded root username as if it were ours', function () {
        // DatabaseManager::connection() seeds `username => root`. Treating that as
        // "already provisioned" would mean granting to root — the one thing this
        // installer exists to avoid.
        app(DatabaseManager::class)->connection('mariadb');
        expect(DatabaseConnection::query()->where('engine', 'mariadb')->value('username'))->toBe('root');

        $seen = [];
        fakeCleanBox($seen, 'mariadb-server');

        installer('mariadb')->install();

        expect(DatabaseConnection::query()->where('engine', 'mariadb')->value('username'))
            ->toMatch('/^panel_[a-z0-9]{10}$/');
    });

    it('skips apt when the package is already there', function () {
        $seen = [];
        fakeCleanBox($seen, 'mariadb-server');

        installer('mariadb')->install();

        expect(collect($seen)->contains(fn ($p) => $p->command[0] === 'apt-get'))->toBeFalse();
    });
});

describe('refusing', function () {
    it('refuses when the other SQL engine already owns the port', function () {
        $seen = [];
        fakeCleanBox($seen, 'mysql-server');

        expect(fn () => installer('mariadb')->install())
            ->toThrow(EngineInstallException::class);

        // Checked before apt, because installing one over the other removes the
        // first as a conflicting package — taking its databases' server with it.
        expect(collect($seen)->contains(fn ($p) => $p->command[0] === 'apt-get'))->toBeFalse();
    });

    it('refuses when it cannot sign in as root over the socket', function () {
        // A pre-existing engine whose root auth was changed. Overwriting it could
        // lock out whatever else on the box uses it, so the panel asks instead.
        Process::fake(fn ($process) => match (true) {
            ($process->command[0] ?? '') === 'dpkg-query' => Process::result(output: 'unknown ok not-installed'),
            str_contains($process->command[0] ?? '', 'maria') => Process::result(errorOutput: 'Access denied', exitCode: 1),
            default => Process::result(exitCode: 0),
        });

        try {
            installer('mariadb')->install();
            $this->fail('expected the install to be refused');
        } catch (EngineInstallException $e) {
            expect($e->reason)->toBe('root_unreachable');
        }

        // Nothing was written: a refusal must not leave a half-provisioned row.
        expect((string) DatabaseConnection::query()->where('engine', 'mariadb')->value('username'))
            ->not->toMatch('/^panel_/');
    });
});

describe('the endpoint', function () {
    it('queues the install and reports progress', function () {
        Queue::fake();
        $seen = [];
        fakeCleanBox($seen);

        $this->withHeaders(['Authorization' => 'Bearer '.$this->token])
            ->postJson('/api/databases/engines/mariadb')
            ->assertStatus(202)->assertJson(['queued' => true]);

        Queue::assertPushed(InstallDatabaseEngine::class);

        // The row exists before the worker starts — written by the controller, so
        // there is no blind window where the page shows nothing happening.
        $body = $this->withHeaders(['Authorization' => 'Bearer '.$this->token])
            ->getJson('/api/databases/engines')->assertOk()->json('engines');

        $mariadb = collect($body)->firstWhere('engine', 'mariadb');
        expect($mariadb['install_status'])->toBe('installing')
            ->and($mariadb['installable'])->toBeTrue()
            ->and($mariadb['install_progress']['status'])->toBe('installing')
            ->and($mariadb['install_progress']['current_step'])->toBe('queued')
            ->and($mariadb['install_progress']['current_step_title'])->toBe('Queued')
            ->and($mariadb['install_progress']['started_at'])->not->toBeNull()
            ->and($mariadb['install_progress']['retryable'])->toBeFalse();
    });

    it('returns the exact failed step, bounded output and support reference', function () {
        $reference = '11111111-2222-3333-4444-555555555555';

        RuntimeInstall::create([
            'runtime' => 'database',
            'version' => 'mariadb',
            'extension' => '',
            'status' => 'failed',
            'reason' => 'unknown',
            'reference' => $reference,
            'current_step' => 'starting_service',
            'output' => 'Setting up mariadb-server failed',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);

        Process::fake(fn ($process) => match ($process->command[0] ?? '') {
            'mysql', 'mariadb' => Process::result(errorOutput: 'not running', exitCode: 1),
            'dpkg-query' => Process::result(output: 'unknown ok not-installed'),
            default => Process::result(exitCode: 0),
        });

        $body = $this->withHeaders(['Authorization' => 'Bearer '.$this->token])
            ->getJson('/api/databases/engines')
            ->assertOk()
            ->json('engines');

        $mariadb = collect($body)->firstWhere('engine', 'mariadb');

        expect($mariadb['install_status'])->toBe('failed')
            ->and($mariadb['install_progress']['current_step'])->toBe('starting_service')
            ->and($mariadb['install_progress']['current_step_title'])->toBe('Starting the database service')
            ->and($mariadb['install_progress']['output'])->toBe('Setting up mariadb-server failed')
            ->and($mariadb['install_progress']['reference'])->toBe($reference)
            ->and($mariadb['install_progress']['retryable'])->toBeTrue();
    });

    it('refuses an engine it has no installer for', function () {
        // Every engine the panel ships now has one — MongoDB was the last
        // without, until it got its own apt repository. So the case is faked
        // rather than borrowed from the catalog: the endpoint has to keep
        // refusing, because `installer` staying nullable is what lets a new
        // engine be *operable* before it is *installable*, and a button that
        // cannot work is worse than a greyed one.
        config(['server.databases.engines.mongodb.installer' => null]);

        Queue::fake();
        Process::fake();

        $this->withHeaders(['Authorization' => 'Bearer '.$this->token])
            ->postJson('/api/databases/engines/mongodb')
            ->assertStatus(422);

        Queue::assertNothingPushed();

        $body = $this->withHeaders(['Authorization' => 'Bearer '.$this->token])
            ->getJson('/api/databases/engines')->json('engines');

        expect(collect($body)->firstWhere('engine', 'mongodb')['installable'])->toBeFalse();
    });

    it('offers MongoDB now that it has an installer', function () {
        Queue::fake();
        Process::fake();

        $this->withHeaders(['Authorization' => 'Bearer '.$this->token])
            ->postJson('/api/databases/engines/mongodb')
            ->assertStatus(202);

        Queue::assertPushed(InstallDatabaseEngine::class);

        $body = $this->withHeaders(['Authorization' => 'Bearer '.$this->token])
            ->getJson('/api/databases/engines')->json('engines');

        // The setup page reads this flag to decide whether the card is a button
        // or a greyed row.
        expect(collect($body)->firstWhere('engine', 'mongodb')['installable'])->toBeTrue();
    });

    it('does not queue anything when the engine is already installed', function () {
        Queue::fake();
        $seen = [];
        fakeCleanBox($seen, 'mariadb-server');

        $this->withHeaders(['Authorization' => 'Bearer '.$this->token])
            ->postJson('/api/databases/engines/mariadb')
            ->assertOk()->assertJson(['queued' => false]);

        Queue::assertNothingPushed();
    });

    it('needs manage permission, not just view', function () {
        Queue::fake();
        Process::fake();
        $viewer = User::factory()->create();
        grantPermission($viewer, 'database');

        $this->withHeaders(['Authorization' => 'Bearer '.$viewer->createToken('t')->plainTextToken])
            ->postJson('/api/databases/engines/mariadb')
            ->assertForbidden();
    });
});

describe('the job', function () {
    it('is unique per engine so two apt runs cannot race', function () {
        // Two `apt-get install` runs at once fight over the dpkg lock, and one
        // fails for a reason that has nothing to do with the user. Asserted
        // directly because Queue::fake() never applies unique-job locking.
        expect(new InstallDatabaseEngine('mariadb'))->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class);
        expect((new InstallDatabaseEngine('mariadb'))->uniqueId())
            ->not->toBe((new InstallDatabaseEngine('mysql'))->uniqueId());
    });

    it('outlives its own apt timeout', function () {
        expect((new InstallDatabaseEngine('mariadb'))->timeout)
            ->toBeGreaterThan((int) config('server.databases.install_timeout'));
    });

    it('stays inside the queue reservation window', function () {
        // Same rule as the provisioning jobs: a job that outlives `retry_after`
        // becomes eligible for a second worker while the first is still running.
        expect((int) config('queue.connections.database.retry_after'))
            ->toBeGreaterThan((new InstallDatabaseEngine('mariadb'))->timeout);
    });

    it('keeps the exact step when account provisioning fails', function () {
        $queries = 0;

        Process::fake(function ($process) use (&$queries) {
            $command = $process->command;

            if (($command[0] ?? '') === 'dpkg-query') {
                return Process::result(output: 'unknown ok not-installed');
            }

            if (in_array(($command[0] ?? ''), ['mysql', 'mariadb'], true)) {
                $queries++;

                return $queries === 2
                    ? Process::result(errorOutput: 'grant denied', exitCode: 1)
                    : Process::result(exitCode: 0);
            }

            return Process::result(exitCode: 0);
        });

        app(InstallTracker::class)->start('database', 'mariadb', initialStep: 'queued');

        expect(fn () => app()->call([new InstallDatabaseEngine('mariadb'), 'handle']))
            ->toThrow(EngineInstallException::class);

        $install = RuntimeInstall::where('runtime', 'database')->where('version', 'mariadb')->firstOrFail();

        expect($install->status->value)->toBe('failed')
            ->and($install->current_step)->toBe('creating_panel_account')
            ->and($install->reason)->toBe('grant_failed')
            ->and($install->reference)->not->toBeNull();
    });

    it('removes transient progress after a successful install', function () {
        $seen = [];
        fakeCleanBox($seen);
        app(InstallTracker::class)->start('database', 'mariadb', initialStep: 'queued');

        app()->call([new InstallDatabaseEngine('mariadb'), 'handle']);

        expect(RuntimeInstall::where('runtime', 'database')->where('version', 'mariadb')->exists())
            ->toBeFalse();
    });
});

it('protects the panel own database account from deletion', function () {
    // It is an ordinary-looking row in the Database Users list otherwise, and
    // deleting it breaks every database operation with no way back through the UI.
    $seen = [];
    fakeCleanBox($seen);

    installer('mariadb')->install();
    $username = (string) DatabaseConnection::query()->where('engine', 'mariadb')->value('username');

    expect(app(DatabaseManager::class)->isSystemUser($username))->toBeTrue();
});
