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
        ->toBe(['database', 'php', 'node', 'redis', 'fail2ban']);

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

it('says Redis cannot be installed from here rather than offering a dead button', function () {
    fakeBareServer();

    $redis = collect(fetchSetup()['components'])->firstWhere('key', 'redis');

    expect($redis['action'])->toBeNull();
});

it('shows an in-flight install as installing, naming it in the viewer locale', function () {
    fakeBareServer();

    RuntimeInstall::create([
        'runtime' => 'database', 'version' => 'mariadb', 'extension' => '',
        'status' => 'installing', 'started_at' => now(),
    ]);

    $setup = fetchSetup();
    $database = collect($setup['components'])->firstWhere('key', 'database');

    expect($database['state'])->toBe('installing');
    expect($setup['status'])->toBe('installing');
    expect($setup['key'])->toBe('database');
    expect($setup['label'])->toContain('Database');
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

    expect($recommended->all())->toBe(['database', 'fail2ban']);
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
