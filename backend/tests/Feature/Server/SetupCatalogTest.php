<?php

use App\Models\DatabaseConnection;
use App\Models\RuntimeInstall;
use App\Models\ServerCapability;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;

    ServerCapability::create([
        'stack' => 'lemp', 'web_server' => 'nginx',
        'capabilities' => ['php' => true, 'node' => false],
        'source' => 'installer', 'verified_at' => now(),
    ]);
});

function setupHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->token];
}

/** A bare server: nothing the panel would install is present. */
function fakeBareServer(): void
{
    Process::fake(fn ($process) => match (true) {
        // No database engine answers.
        ($process->command[0] ?? '') === 'mysql' => Process::result(errorOutput: "can't connect", exitCode: 1),
        // No fnm, so Node is unmanaged.
        in_array('fnm', $process->command, true) => Process::result(exitCode: 1),
        ($process->command[0] ?? '') === 'which' => Process::result(exitCode: 1),
        ($process->command[0] ?? '') === 'dpkg-query' => Process::result(output: 'unknown ok not-installed'),
        default => Process::result(exitCode: 0),
    });
}

function fetchSetup(): array
{
    return test()->withHeaders(setupHeaders())->getJson('/api/setup')->assertOk()->json('setup');
}

it('lists every component with a detected state', function () {
    fakeBareServer();

    $setup = fetchSetup();

    expect(collect($setup['components'])->pluck('key')->all())
        ->toBe(['database', 'php', 'node', 'build_tools', 'redis', 'fail2ban', 'wp_cli']);

    foreach ($setup['components'] as $component) {
        expect($component['state'])->toBeIn(['installed', 'pending', 'installing', 'failed']);
        expect($component['title'])->not->toBeEmpty();
        expect($component['description'])->not->toBeEmpty();
    }
});

it('derives percent from the components rather than hard-coding it', function () {
    // The commercial panel hard-codes a number per branch, which can go backwards
    // and drifts the moment a step is added. This has to be arithmetic.
    fakeBareServer();

    $setup = fetchSetup();
    $total = count($setup['components']);
    $done = collect($setup['components'])->where('state', 'installed')->count();

    expect($setup['percent'])->toBe((int) round($done / $total * 100));
});

it('reports the stack the installer recorded', function () {
    fakeBareServer();

    $setup = fetchSetup();

    expect($setup['stack'])->toBe('lemp');
    expect($setup['web_server'])->toBe('nginx');
});

it('offers one engine choice per database, with MariaDB recommended', function () {
    fakeBareServer();

    $database = collect(fetchSetup()['components'])->firstWhere('key', 'database');
    $options = collect($database['options'])->keyBy('value');

    expect($options->keys()->all())->toContain('mariadb', 'mysql', 'mongodb');
    expect($options['mariadb']['recommended'])->toBeTrue();
    expect($options['mariadb']['action']['endpoint'])->toBe('/api/databases/engines/mariadb');

    // MongoDB was the last engine that was operable but not installable — it
    // needed its own apt repository, which MongoDbInstaller now adds. It is a
    // real button, and not the recommended one: MariaDB stays the default
    // because Ubuntu packages it directly.
    expect($options['mongodb']['installable'])->toBeTrue();
    expect($options['mongodb']['action']['endpoint'])->toBe('/api/databases/engines/mongodb');
    expect($options['mongodb']['recommended'])->toBeFalse();
});

it('names the engine version plainly (FS-A9)', function () {
    Process::fake(fn ($process) => match (true) {
        str_contains((string) $process->input, 'VERSION()') && ($process->command[0] ?? '') === 'mariadb' => Process::result(output: '10.11.14-MariaDB-0ubuntu0.24.04.1'),
        ($process->command[0] ?? '') === 'mysql' => Process::result(errorOutput: "can't connect", exitCode: 1),
        ($process->command[0] ?? '') === 'which' => Process::result(exitCode: 1),
        default => Process::result(exitCode: 0),
    });

    expect(collect(fetchSetup()['components'])->firstWhere('key', 'database')['detail'])->toBe('MariaDB 10.11.14');
});

it('says why an engine is not offered (OLD-28)', function () {
    // MariaDB answers; MySQL cannot go on beside it.
    Process::fake(fn ($process) => match (true) {
        str_contains((string) $process->input, 'VERSION()') && ($process->command[0] ?? '') === 'mariadb' => Process::result(output: '11.4.2-MariaDB'),
        ($process->command[0] ?? '') === 'mysql' => Process::result(errorOutput: "can't connect", exitCode: 1),
        ($process->command[0] ?? '') === 'which' => Process::result(exitCode: 1),
        default => Process::result(exitCode: 0),
    });
    config(['server.databases.engines.mysql.unsupported_codenames' => []]);

    $database = collect(fetchSetup()['components'])->firstWhere('key', 'database');
    $options = collect($database['options'])->keyBy('value');

    expect($options['mysql']['installable'])->toBeFalse()
        ->and($options['mysql']['unavailable']['code'] ?? null)->toBe('engine_conflict')
        ->and($options['mysql']['unavailable']['reason'])->toBe(__('runtime.install_failed.port_in_use_by_mariadb'))
        ->and($options['mariadb']['unavailable'])->toBeNull();
});

it('says Redis cannot be installed from here rather than offering a dead button', function () {
    fakeBareServer();

    $redis = collect(fetchSetup()['components'])->firstWhere('key', 'redis');

    expect($redis['action'])->toBeNull();
});

it('shows an in-flight install as installing, naming it in the viewer locale', function () {
    fakeBareServer();

    RuntimeInstall::create([
        'runtime' => 'database', 'version' => 'mariadb', 'extension' => '',
        'status' => 'installing', 'current_step' => 'starting_service',
        'output' => 'Setting up mariadb-server', 'started_at' => now(),
    ]);

    $setup = fetchSetup();
    $database = collect($setup['components'])->firstWhere('key', 'database');

    expect($database['state'])->toBe('installing');
    expect($setup['status'])->toBe('installing');
    expect($setup['key'])->toBe('database');
    expect($setup['label'])->toContain('Database');
    expect($database['progress']['current_step'])->toBe('starting_service')
        ->and($database['progress']['current_step_title'])->toBe('Starting the database service')
        ->and($database['progress']['output'])->toBe('Setting up mariadb-server')
        ->and($database['progress']['retryable'])->toBeFalse();
});

it('surfaces a failure with a retry rather than hiding it', function () {
    // The commercial panel deletes the record and the error message on failure.
    // Here the panel *is* the server — there is nowhere to go back to.
    fakeBareServer();

    RuntimeInstall::create([
        'runtime' => 'database', 'version' => 'mariadb', 'extension' => '',
        'status' => 'failed', 'reason' => 'no_space', 'started_at' => now(),
    ]);

    $database = collect(fetchSetup()['components'])->firstWhere('key', 'database');

    expect($database['state'])->toBe('failed');
    expect($database['reason'])->toBe('no_space');
    expect($database['message'])->not->toBeEmpty();
    expect($database['retryable'])->toBeTrue();
});

it('lets detection win over a stale progress row', function () {
    // Otherwise a row left at `installing` for something now present shows a
    // spinner that never resolves.
    Process::fake(fn ($process) => match (true) {
        ($process->command[0] ?? '') === 'mysql' => Process::result(output: '10.11.14-MariaDB'),
        default => Process::result(exitCode: 0),
    });

    DatabaseConnection::create([
        'engine' => 'mariadb', 'connection_type' => 'socket', 'host' => '127.0.0.1',
        'port' => 3306, 'username' => 'panel_abcdefghij', 'password' => 'secret',
    ]);

    RuntimeInstall::create([
        'runtime' => 'database', 'version' => 'mariadb', 'extension' => '',
        'status' => 'installing', 'started_at' => now()->subHour(),
    ]);

    $database = collect(fetchSetup()['components'])->firstWhere('key', 'database');

    expect($database['state'])->toBe('installed');
});

it('is complete when the recommended set is present, not when everything is', function () {
    // Nothing here is required — the installer already put the web server, PHP and
    // Node in place, so the panel works from first boot. Blocking a wizard on
    // optional extras would be blocking people over preferences.
    fakeBareServer();

    expect(fetchSetup()['complete'])->toBeFalse();

    $setup = fetchSetup();
    $recommended = collect($setup['components'])->where('recommended', true)->pluck('key');

    expect($recommended->all())->toBe(['database', 'build_tools', 'fail2ban']);
});

it('needs the setting permission to read', function () {
    fakeBareServer();
    $stranger = User::factory()->create();

    $this->withHeaders(['Authorization' => 'Bearer '.$stranger->createToken('t')->plainTextToken])
        ->getJson('/api/setup')
        ->assertForbidden();
});

it('lets a user with only view access read it', function () {
    // Reporting what the box has is a read. Installing is what needs `manage`,
    // and that lives on the endpoints this page points at.
    fakeBareServer();
    $viewer = User::factory()->create();
    grantPermission($viewer, 'setting');

    $this->withHeaders(['Authorization' => 'Bearer '.$viewer->createToken('t')->plainTextToken])
        ->getJson('/api/setup')
        ->assertOk();
});

it('offers wp-cli only where PHP sites are hosted', function () {
    // WpCliComponent predated `applies()` on the contract; without it the
    // class could not even be loaded and every Setup page was a fatal error.
    fakeBareServer();

    expect(collect(fetchSetup()['components'])->pluck('key'))->toContain('wp_cli');

    ServerCapability::query()->delete();
    ServerCapability::create([
        'stack' => 'docker', 'web_server' => 'nginx',
        'capabilities' => ['php' => true, 'node' => false, 'serving_profiles' => ['docker']],
        'source' => 'installer', 'verified_at' => now(),
    ]);

    expect(collect(fetchSetup()['components'])->pluck('key'))->not->toContain('wp_cli');
});

it('shows a fail2ban or build-tools install in flight or failed, not as pending (FS-A7)', function (string $key, string $runtime) {
    fakeBareServer();

    RuntimeInstall::create([
        'runtime' => $runtime, 'version' => 'latest', 'extension' => '',
        'status' => 'installing', 'started_at' => now(),
    ]);

    expect(collect(fetchSetup()['components'])->firstWhere('key', $key)['state'])->toBe('installing');

    RuntimeInstall::query()->update(['status' => 'failed', 'reason' => 'network']);

    $row = collect(fetchSetup()['components'])->firstWhere('key', $key);

    expect($row['state'])->toBe('failed')
        ->and($row['reason'])->toBe('network')
        ->and($row['message'])->not->toBeEmpty()
        ->and($row['retryable'])->toBeTrue();
})->with([
    'fail2ban' => ['fail2ban', 'fail2ban'],
    'build tools' => ['build_tools', 'build_tools'],
]);

it('refuses a second install while one is still running (FS-A5)', function (string $endpoint, string $runtime, string $message) {
    fakeBareServer();
    RuntimeInstall::create(['runtime' => $runtime, 'version' => 'latest', 'extension' => '', 'status' => 'installing', 'started_at' => now()]);

    $this->withHeaders(setupHeaders())->postJson($endpoint)
        ->assertStatus(409)
        ->assertJsonPath('message', __($message));

    expect(RuntimeInstall::where('runtime', $runtime)->count())->toBe(1);
})->with([
    'fail2ban' => ['/api/fail2ban/install', 'fail2ban', 'errors/fail2ban.already_installing'],
    'build tools' => ['/api/build-tools/install', 'build_tools', 'errors/build-tools.already_installing'],
]);
