<?php

use App\Models\ActivityLog;
use App\Models\DockerDatabase;
use App\Models\Role;
use App\Models\ServerCapability;
use App\Models\User;
use App\Services\Server\Applications\PortAllocator;
use App\Services\Server\Docker\DatabaseContainerManager;
use App\Services\Server\HostCpus;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * Databases running as containers, for the container sites to connect to.
 *
 * A Docker box manages no engine — that is the stack's own decision, and it is why
 * an application that wants a database brings one. Which is fine until two sites
 * want the same database, or an app needs Redis as well, at which point "bring
 * your own" means pasting the same service into every compose file and having no
 * idea which volume belongs to whom.
 *
 * These are NOT applications. Every site here has a required `domain`, a vhost and
 * `proxy_pass http://127.0.0.1:<port>`; a database speaks its own wire protocol, so
 * as a site it would hold a domain, be issued a certificate no browser can use, and
 * answer 502 for ever.
 *
 * What the tests are mostly about is the compose file, and three properties of it
 * that "the container came up" cannot show: the publish is loopback-only, the
 * password is generated per database, and the volume is the panel's.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();

    ServerCapability::query()->delete();
    ServerCapability::create([
        'stack' => 'docker', 'web_server' => 'nginx',
        'capabilities' => ['php' => true, 'node' => false, 'serving_profiles' => ['docker']],
        'source' => 'installer', 'verified_at' => now(),
    ]);

    // Faked per command. `compose ps --status running` decides the `running` flag
    // on every response, so a blanket empty answer would make every database read
    // as stopped and the tests would assert against a box the fake broke.
    Process::fake(function ($process) {
        $args = $process->command;

        while (in_array($args[0] ?? '', ['sudo', '-n', 'env'], true) || str_contains($args[0] ?? '', '=')) {
            array_shift($args);
        }

        if (($args[0] ?? '') === 'docker' && in_array('ps', $args, true)) {
            return Process::result(output: "abc123\n");
        }

        // The readiness wait asks Docker for the engine's health. Answered
        // explicitly rather than left to the blanket exit-0 above, because a blank
        // answer is what the wait treats as "nothing to wait for" — so a test
        // relying on that would pass for the wrong reason.
        if (($args[0] ?? '') === 'docker' && ($args[1] ?? '') === 'inspect') {
            return Process::result(output: "healthy\n");
        }

        return Process::result(exitCode: 0);
    });
});

function dbHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->admin->createToken('t')->plainTextToken];
}

function createDb(array $overrides = []): array
{
    $response = test()->withHeaders(dbHeaders())->postJson('/api/docker/databases', array_merge([
        'name' => 'shopdb',
        'engine' => 'postgres',
        'version' => '17',
    ], $overrides));

    return [$response, $response->json('database.id')];
}

/*
 * The catalog, which the form is built from.
 */

it('offers every engine the panel can actually render', function () {
    $response = $this->withHeaders(dbHeaders())->getJson('/api/docker/databases')->assertOk();

    $engines = collect($response->json('engines'))->keyBy('name');

    expect($engines->keys()->all())
        ->toEqualCanonicalizing(['postgres', 'mysql', 'mariadb', 'mongodb', 'redis', 'valkey'])
        ->and($engines['postgres']['port'])->toBe(5432)
        ->and($engines['redis']['port'])->toBe(6379)
        ->and($engines['mongodb']['port'])->toBe(27017);

    // Every version is a STRING. PHP keys `'18'` as an int and `'8.4'` as a
    // string, so without a cast the same field is a number for one engine and a
    // string for another — and a client echoing the number back fails a `string`
    // rule with a message about a type it never picked.
    foreach ($engines as $engine) {
        foreach ($engine['versions'] as $version) {
            expect($version)->toBeString();
        }
    }

    // Every advertised version resolves to an image, or the form offers a choice
    // that fails minutes later at `docker pull` — which is exactly how a bad
    // BookStack tag reached a real box.
    foreach ($engines as $engine) {
        // Read out of the ARRAY, not through a dotted config path: a version like
        // `8.4` contains a dot, which `config()` would read as two more segments
        // and answer null for a value that is there.
        $versions = (array) config("server.docker_databases.engines.{$engine['name']}.versions");

        foreach ($engine['versions'] as $version) {
            expect($versions[$version] ?? null)->toBeString()->not->toBe('');
        }
    }
});

it('refuses a version that belongs to a different engine', function () {
    // `postgres` with MySQL's `8.4` resolves to no image. Caught on the field
    // rather than at provisioning, because the valid set depends on another field
    // and no single-field rule can see that.
    [$response] = createDb(['engine' => 'postgres', 'version' => '8.4']);

    $response->assertStatus(422)->assertJsonValidationErrors('version');

    expect(DockerDatabase::count())->toBe(0);
});

/*
 * The compose file.
 */

it('publishes to loopback only, never to every address', function (string $engine, string $version, int $enginePort) {
    // The single most important line in the file. Docker writes its own rules into
    // the DOCKER chain ahead of the ones ufw manages, so `- "5432:5432"` — the form
    // every tutorial uses — puts a database on the public internet while the
    // panel's Firewall page says the port is closed.
    [$response, $id] = createDb(['name' => 'db'.$engine, 'engine' => $engine, 'version' => $version]);
    $response->assertCreated();

    $database = DockerDatabase::find($id);
    $compose = app(DatabaseContainerManager::class)->contents($database);
    $parsed = Yaml::parse($compose);

    expect($parsed['services']['db']['ports'])->toBe(["127.0.0.1:{$database->port}:{$enginePort}"])
        ->and($compose)->not->toContain('"0.0.0.0:')
        // And a bare `port:port`, which binds every address.
        ->and($compose)->not->toContain("- \"{$database->port}:{$enginePort}\"");
})->with([
    ['postgres', '17', 5432],
    ['mysql', '8.4', 3306],
    ['mariadb', '11.4', 3306],
    ['mongodb', '8', 27017],
    ['redis', '8', 6379],
    ['valkey', '8', 6379],
]);

it('gives every engine a memory ceiling and bounded logs', function (string $engine, string $version) {
    // An engine sizes its buffers from what it can see, and Docker's json-file
    // driver has no max size: a database logging every slow query fills the disk,
    // and the first symptom is every site on the box failing to write.
    [, $id] = createDb(['name' => 'lim'.$engine, 'engine' => $engine, 'version' => $version]);
    $service = Yaml::parse(app(DatabaseContainerManager::class)->contents(DockerDatabase::find($id)))['services']['db'];

    expect($service['mem_limit'])->not->toBeEmpty()
        ->and($service['logging']['driver'])->toBe('json-file')
        ->and($service['logging']['options']['max-size'])->toBe('10m');
})->with([['postgres', '17'], ['mysql', '8.4'], ['mongodb', '8'], ['redis', '8']]);

it('declares the volume external, so the panel owns the data', function () {
    // Declared without `external: true`, Compose would make its own volume — one
    // the Docker page cannot list, cannot attribute to this database, and does not
    // guard against deletion while something is using it.
    [, $id] = createDb();
    $database = DockerDatabase::find($id);
    $parsed = Yaml::parse(app(DatabaseContainerManager::class)->contents($database));

    expect($parsed['volumes'][$database->volume()]['external'])->toBeTrue()
        ->and($parsed['services']['db']['volumes'])->toBe([$database->volume().':/var/lib/postgresql/data']);
});

it('escapes every dollar sign, because Compose interpolates its own file', function (string $engine, string $version) {
    // The WordPress lesson, applied where it would bite next: MySQL's healthcheck
    // reads `$MYSQL_ROOT_PASSWORD`, and written plainly Compose would substitute it
    // away — leaving a healthcheck that authenticates with an empty password and a
    // database that never reports healthy.
    [, $id] = createDb(['name' => 'esc'.$engine, 'engine' => $engine, 'version' => $version]);
    $compose = app(DatabaseContainerManager::class)->contents(DockerDatabase::find($id));

    expect(preg_match('/(?<!\$)\$(?!\$)[A-Za-z_{]/', $compose))
        ->toBe(0, "{$engine} writes an unescaped \$variable that Compose will interpolate away");
})->with([['postgres', '17'], ['mysql', '8.4'], ['mariadb', '11.4'], ['mongodb', '8'], ['redis', '8']]);

/*
 * Credentials.
 */

it('generates a different password for every database', function () {
    // A password in a template would be shared by every panel-installed database
    // on every server. A password the user typed would be in their shell history.
    [, $first] = createDb(['name' => 'one']);
    [, $second] = createDb(['name' => 'two']);

    $a = DockerDatabase::find($first)->credential('password');
    $b = DockerDatabase::find($second)->credential('password');

    expect($a)->not->toBeNull()->and(strlen($a))->toBeGreaterThanOrEqual(24)->and($a)->not->toBe($b);
});

it('stores credentials encrypted, not as readable json', function () {
    [, $id] = createDb();
    $password = DockerDatabase::find($id)->credential('password');

    $raw = (string) DB::table('docker_databases')->where('id', $id)->value('credentials');

    expect($raw)->not->toContain($password)
        ->and(DockerDatabase::find($id)->credential('password'))->toBe($password);
});

it('keeps the password out of every listing', function () {
    [, $id] = createDb();
    $password = DockerDatabase::find($id)->credential('password');

    foreach (['/api/docker/databases'] as $url) {
        $response = $this->withHeaders(dbHeaders())->getJson($url);

        expect($response->getContent())->not->toContain($password);
    }

    // It comes from its own endpoint, gated on manage and recorded.
    $this->withHeaders(dbHeaders())
        ->getJson("/api/docker/databases/{$id}/credentials")
        ->assertOk()
        ->assertJsonPath('credentials.password', $password);

    expect(ActivityLog::where('type', 'docker_database')->where('action', 'credentials_viewed')->count())->toBe(1);
});

it('never gives the application user root', function () {
    // MySQL needs root to create the schema; nothing else should have it. Two
    // passwords, stored and shown separately.
    [, $id] = createDb(['name' => 'mydb', 'engine' => 'mysql', 'version' => '8.4']);
    $database = DockerDatabase::find($id);

    expect($database->credential('root_password'))->not->toBeNull()
        ->and($database->credential('root_password'))->not->toBe($database->credential('password'))
        ->and($database->credential('username'))->not->toBe('root');
});

it('gives Redis a password and nothing it does not have', function () {
    // Redis has no users and no databases, so the credential set is a password —
    // and the connection details show what exists rather than empty fields for
    // concepts the engine lacks.
    [, $id] = createDb(['name' => 'cache', 'engine' => 'redis', 'version' => '8']);
    $database = DockerDatabase::find($id);

    expect($database->credential('password'))->not->toBeNull()
        ->and($database->credential('username'))->toBeNull()
        ->and($database->credential('database'))->toBeNull();

    // And the password reaches the engine as a command argument, because the
    // official image reads none from the environment — started without it, Redis
    // accepts every connection that can reach it.
    $compose = app(DatabaseContainerManager::class)->contents($database);

    expect($compose)->toContain('--requirepass');
});

it('makes a database name an identifier the engine will accept', function () {
    // A display name is free text; an identifier is not. MySQL forbids a hyphen in
    // an unquoted one, so a database called "my shop-db" has to become something
    // the engine can be handed.
    [, $id] = createDb(['name' => 'my-shop-db', 'engine' => 'mysql', 'version' => '8.4']);
    $database = DockerDatabase::find($id);

    expect($database->credential('database'))->toMatch('/^[a-z0-9_]+$/')
        ->and($database->credential('username'))->toMatch('/^[a-z0-9_]+$/');
});

/*
 * The port, which is shared with the sites.
 */

it('does not hand a site a port a database already holds', function () {
    // The allocator reads both tables. Asked of the rows rather than of what is
    // listening, because between a database row being created and its container
    // starting the port is claimed and nothing is bound — and a site allocated in
    // that window would take it and then fail to bind.
    [, $id] = createDb();
    $port = DockerDatabase::find($id)->port;

    expect(app(PortAllocator::class)->allocate())->not->toBe($port);
});

/*
 * Two hosts, which is the confusing part.
 */

it('answers both the internal and the loopback address', function () {
    // From another container it is the name on a shared network; from the server
    // itself it is 127.0.0.1 and the allocated port. The two being different is the
    // single most confusing thing about a containerised database, so the API says
    // both rather than leaving the UI to explain it.
    [, $id] = createDb(['name' => 'shared', 'engine' => 'postgres', 'version' => '17']);

    $this->withHeaders(dbHeaders())
        ->getJson('/api/docker/databases')
        ->assertOk()
        ->assertJsonPath('databases.0.internal_host', 'shared')
        ->assertJsonPath('databases.0.internal_port', 5432)
        ->assertJsonPath('databases.0.host_port', DockerDatabase::find($id)->port);
});

/*
 * Deleting, where the data is a separate decision.
 */

it('keeps the data unless the delete asked for it', function () {
    [, $id] = createDb();
    $volume = DockerDatabase::find($id)->volume();

    $ran = [];
    Process::fake(function ($process) use (&$ran) {
        $args = $process->command;
        while (in_array($args[0] ?? '', ['sudo', '-n'], true)) {
            array_shift($args);
        }
        $ran[] = $args;

        return Process::result(exitCode: 0);
    });

    $this->withHeaders(dbHeaders())->deleteJson("/api/docker/databases/{$id}")->assertOk();

    $removedVolume = collect($ran)->contains(
        fn (array $args) => ($args[0] ?? '') === 'docker' && in_array('volume', $args, true) && in_array('rm', $args, true)
    );

    expect($removedVolume)->toBeFalse('a plain delete must not destroy the data')
        ->and(DockerDatabase::find($id))->toBeNull()
        ->and(collect($ran)->contains(fn (array $a) => in_array('down', $a, true)))->toBeTrue();
});

it('removes the data when the delete opted in', function () {
    [, $id] = createDb();

    $ran = [];
    Process::fake(function ($process) use (&$ran) {
        $args = $process->command;
        while (in_array($args[0] ?? '', ['sudo', '-n'], true)) {
            array_shift($args);
        }
        $ran[] = $args;

        return Process::result(exitCode: 0);
    });

    $this->withHeaders(dbHeaders())
        ->deleteJson("/api/docker/databases/{$id}", ['remove_data' => true])
        ->assertOk();

    expect(collect($ran)->contains(
        fn (array $args) => ($args[0] ?? '') === 'docker' && in_array('volume', $args, true) && in_array('rm', $args, true)
    ))->toBeTrue();

    expect(ActivityLog::where('type', 'docker_database')->where('action', 'deleted')->sole()->properties['data_removed'])
        ->toBeTrue();
});

/*
 * Access and availability.
 */

it('refuses every mutation without docker manage', function () {
    $viewer = User::factory()->create();
    $viewer->roles()->attach(Role::create(['name' => 'Viewer', 'slug' => 'viewer']));
    $headers = ['Authorization' => 'Bearer '.$viewer->createToken('t')->plainTextToken];

    $this->withHeaders($headers)
        ->postJson('/api/docker/databases', ['name' => 'x', 'engine' => 'postgres', 'version' => '17'])
        ->assertForbidden();
});

it('is unavailable on a server that hosts no containers', function () {
    ServerCapability::query()->delete();
    ServerCapability::create([
        'stack' => 'lemp', 'web_server' => 'nginx',
        'capabilities' => ['php' => true, 'node' => true, 'serving_profiles' => ['php']],
        'source' => 'installer', 'verified_at' => now(),
    ]);

    $this->withHeaders(dbHeaders())->getJson('/api/docker/databases')->assertStatus(409);
});

it('records the engine and the port, never a credential', function () {
    [, $id] = createDb();
    $row = ActivityLog::where('type', 'docker_database')->where('action', 'created')->sole();

    expect(json_encode($row->properties))
        ->not->toContain(DockerDatabase::find($id)->credential('password'))
        ->and($row->properties['engine'])->toBe('postgres');
});

/*
 * Readiness, which is what makes the connection details usable rather than merely
 * correct.
 */

it('waits for the engine to answer before reporting success', function () {
    // `up -d` returns as soon as the container is created, which for a database is
    // well before it accepts connections. Measured on a real box: the endpoint
    // replied 201, and a connection made immediately with the credentials it had
    // just handed over was refused — Postgres was still initialising.
    $asked = [];

    Process::fake(function ($process) use (&$asked) {
        $args = $process->command;
        while (in_array($args[0] ?? '', ['sudo', '-n'], true)) {
            array_shift($args);
        }

        if (($args[0] ?? '') === 'docker' && ($args[1] ?? '') === 'inspect') {
            $asked[] = $args;

            // Starting, then healthy — the shape of a real first boot.
            return Process::result(output: count($asked) < 2 ? "starting\n" : "healthy\n");
        }

        if (($args[0] ?? '') === 'docker' && in_array('ps', $args, true)) {
            return Process::result(output: "abc123\n");
        }

        return Process::result(exitCode: 0);
    });

    createDb(['name' => 'waitdb'])[0]->assertCreated();

    expect(count($asked))->toBeGreaterThanOrEqual(2, 'it should have polled until healthy');
});

it('rolls back a database that never becomes ready', function () {
    // Two minutes of `starting` is not slow, it is broken — a bad volume
    // permission, an OOM, an image that cannot initialise. Leaving the row would
    // leave connection details that connect to nothing, and a port the allocator
    // keeps reserving.
    config(['server.docker_databases.ready_timeout' => 0]);

    Process::fake(function ($process) {
        $args = $process->command;
        while (in_array($args[0] ?? '', ['sudo', '-n'], true)) {
            array_shift($args);
        }

        if (($args[0] ?? '') === 'docker' && ($args[1] ?? '') === 'inspect') {
            return Process::result(output: "starting\n");
        }

        return Process::result(exitCode: 0);
    });

    [$response] = createDb(['name' => 'neverready']);

    $response->assertStatus(422)->assertJsonPath('step', 'not_ready');

    expect(DockerDatabase::count())->toBe(0);
});

it('does not hang when the health status cannot be read', function () {
    // The rule is "only `starting` and `unhealthy` mean wait". Written the other
    // way round — wait unless healthy — an unanswered inspect is
    // indistinguishable from starting and the whole timeout elapses on every
    // call. That is exactly what happened, which is why this asserts the
    // permissive direction.
    Process::fake(function ($process) {
        $args = $process->command;
        while (in_array($args[0] ?? '', ['sudo', '-n'], true)) {
            array_shift($args);
        }

        if (($args[0] ?? '') === 'docker' && ($args[1] ?? '') === 'inspect') {
            return Process::result(output: '');
        }

        return Process::result(exitCode: 0);
    });

    createDb(['name' => 'unreadable'])[0]->assertCreated();
});

/*
 * Per-instance sizes.
 *
 * Before these columns a database read one server-wide value, so a Redis cache
 * used as a session store and the primary Postgres behind everything were sized
 * identically at 512m. The size is the product for anyone selling compute, and an
 * engine's own buffers are sized from what it can see — so this is the field that
 * decides how a database performs, and it has to be per row.
 */

it('sizes each database from its own row, not one server-wide number', function () {
    [, $small] = createDb(['name' => 'cache', 'engine' => 'redis', 'version' => '8', 'memory_limit' => '128m', 'cpu_limit' => '0.5']);
    [, $large] = createDb(['name' => 'primary', 'engine' => 'postgres', 'version' => '17', 'memory_limit' => '2g', 'cpu_limit' => '2']);

    $manager = app(DatabaseContainerManager::class);
    $cache = Yaml::parse($manager->contents(DockerDatabase::find($small)))['services']['db'];
    $primary = Yaml::parse($manager->contents(DockerDatabase::find($large)))['services']['db'];

    expect($cache['mem_limit'])->toBe('128m')
        ->and($cache['cpus'])->toBe(0.5)
        ->and($primary['mem_limit'])->toBe('2g')
        ->and($primary['cpus'])->toBe(2);
});

it('falls back to the configured default for memory and to no quota for cpu', function () {
    // The asymmetry is the design. A database with no memory ceiling can take the
    // box down, so that one has a default; a database with no CPU quota is merely
    // unthrottled, and defaulting it would have capped every engine already
    // running here the next time it was recreated.
    [, $id] = createDb(['name' => 'plain']);
    $service = Yaml::parse(app(DatabaseContainerManager::class)->contents(DockerDatabase::find($id)))['services']['db'];

    expect($service['mem_limit'])->toBe((string) config('server.docker.default_db_memory_limit'))
        ->and($service)->not->toHaveKey('cpus');
});

it('refuses a cpu quota larger than the machine, at the form', function () {
    // Docker refuses it too — `compose up` answers "range of CPUs is from 0.01 to
    // 4.00, as there are only 4 CPUs available" — but it refuses at deploy time,
    // which for a database means the row is created, the container never starts,
    // and `CreateDockerDatabase` rolls the whole thing back. A field error is the
    // same refusal where somebody can act on it.
    app()->instance(HostCpus::class, new class extends HostCpus
    {
        public function count(): int
        {
            return 2;
        }
    });

    [$response] = createDb(['name' => 'toobig', 'cpu_limit' => '8']);

    $response->assertStatus(422)->assertJsonValidationErrors('cpu_limit');

    expect(DockerDatabase::query()->where('name', 'toobig')->exists())->toBeFalse();
});

it('refuses a memory ceiling Docker would not start', function () {
    // Docker's own floor is 6MB: "Minimum memory limit allowed is 6MB", measured.
    // Accepted here it would be a database that saves and never runs.
    [$tooSmall] = createDb(['name' => 'tiny', 'memory_limit' => '2m']);
    $tooSmall->assertStatus(422)->assertJsonValidationErrors('memory_limit');

    // And a bare number is BYTES to Docker, not megabytes — the mistake the
    // message exists to name, and it has to be the message that actually fires.
    // 512 bytes is also below the 6m floor, so the size check would answer first
    // and describe the consequence while hiding the cause. Measured on the box
    // before this was split: `512` got "less than 6m of memory", to somebody who
    // plainly meant 512 megabytes.
    [$noUnit] = createDb(['name' => 'nounit', 'memory_limit' => '512']);
    $noUnit->assertStatus(422)->assertJsonValidationErrors('memory_limit');

    expect($noUnit->json('errors.memory_limit.0'))->toContain('bytes to Docker, not megabytes');
});

it('records the size it was created at', function () {
    createDb(['name' => 'sized', 'memory_limit' => '1g', 'cpu_limit' => '1']);

    $log = ActivityLog::where('type', 'docker_database')->where('action', 'created')->sole();

    expect($log->properties['memory_limit'])->toBe('1g')
        ->and($log->properties['cpu_limit'])->toBe('1');
});

it('answers the size it was given, and what an empty field would have meant', function () {
    // The raw value, not a resolved one: the UI has to be able to distinguish
    // "chosen" from "inherited", and a resolved number hides that difference.
    [, $id] = createDb(['name' => 'shown', 'memory_limit' => '1g']);

    $row = collect($this->withHeaders(dbHeaders())->getJson('/api/docker/databases')->json('databases'))
        ->firstWhere('id', $id);

    expect($row['memory_limit'])->toBe('1g')
        ->and($row['cpu_limit'])->toBeNull()
        ->and($row['default_memory_limit'])->toBe((string) config('server.docker.default_db_memory_limit'));
});

/*
 * Where an image keeps its data, which is not always the engine's default.
 *
 * 🔴 Found on the box by using the new one-click control: Postgres **18** is the
 * newest version the catalog offers, so it is what that control starts by default —
 * and it was the one version that could not start at all. The container says why
 * itself:
 *
 *   The suggested container configuration for 18+ is to place a single mount at
 *   /var/lib/postgresql which will then place PostgreSQL data in a subdirectory,
 *   allowing usage of "pg_upgrade --link" without mount point boundary issues.
 *
 * Verified with `docker inspect` on all three images: 16 and 17 have
 * `PGDATA=/var/lib/postgresql/data` and declare their volume there; 18 has
 * `PGDATA=/var/lib/postgresql/18/docker` and declares `/var/lib/postgresql`.
 *
 * The panel handled the failure correctly — `CreateDockerDatabase` rolled the row,
 * the container and the port back — so the only symptom was a create that failed,
 * with nothing left to inspect. Which is why this is a test and not a comment.
 */

it('mounts Postgres 18 where Postgres 18 keeps its data', function () {
    [, $id] = createDb(['name' => 'pg18', 'engine' => 'postgres', 'version' => '18']);

    $parsed = Yaml::parse(app(DatabaseContainerManager::class)->contents(DockerDatabase::find($id)));

    expect($parsed['services']['db']['volumes'][0])->toEndWith(':/var/lib/postgresql')
        // And NOT the path that makes it refuse to start.
        ->and($parsed['services']['db']['volumes'][0])->not->toEndWith(':/var/lib/postgresql/data');
});

it('leaves the older Postgres versions where they were', function () {
    // The override is per version, so 16 and 17 must be untouched — moving them
    // would be an existing database whose data directory the panel stopped mounting.
    foreach (['17', '16'] as $version) {
        [, $id] = createDb(['name' => 'pg'.$version, 'engine' => 'postgres', 'version' => $version]);

        $parsed = Yaml::parse(app(DatabaseContainerManager::class)->contents(DockerDatabase::find($id)));

        expect($parsed['services']['db']['volumes'][0])
            ->toEndWith(':/var/lib/postgresql/data', "postgres {$version} moved");
    }
});

it('matches a data path override by version string, not by PHP array key', function () {
    // `'18' => …` keys as the INT 18 in PHP, so a lookup with the string '18'
    // misses unless both sides are cast. The engine catalog hit exactly this and
    // returned 18 as a number while '8.4' stayed a string.
    $database = DockerDatabase::find(createDb(['name' => 'pgcast', 'engine' => 'postgres', 'version' => '18'])[1]);

    expect($database->dataPath())->toBe('/var/lib/postgresql')
        ->and($database->version)->toBeString();
});

it('falls back to the engine default for an engine with no overrides', function () {
    [, $id] = createDb(['name' => 'mysqlpath', 'engine' => 'mysql', 'version' => '8.4']);

    expect(DockerDatabase::find($id)->dataPath())->toBe('/var/lib/mysql');
});
