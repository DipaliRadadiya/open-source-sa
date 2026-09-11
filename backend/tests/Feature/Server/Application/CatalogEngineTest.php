<?php

use App\Models\Application;
use App\Models\Database;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Applications\SiteTypeManager;
use App\Services\Server\Applications\InstallerManager;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/**
 * Whether a card is offered depends on an engine the application can actually
 * use.
 *
 * `missingEngines()` used to return early for anything accepting MySQL or
 * MariaDB — nearly the whole catalog — so only NodeBB was ever checked. On a
 * MongoDB-only server every SQL-backed type reported itself available, took a
 * filled-in form, and failed at provisioning with `no-database-engine`.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;

    ServerCapability::query()->delete();
    ServerCapability::query()->create([
        'stack' => 'lemp', 'web_server' => 'nginx',
        'capabilities' => ['php' => true, 'node' => true],
        'source' => 'installer', 'verified_at' => now(),
    ]);
});

/**
 * Answer as a server with only `$engines` reachable.
 *
 * The client binary in the command is what says which engine is being asked:
 * `available()` is a live query through ServerOps either way, so a server
 * without MySQL is one where the `mysql` client's query does not succeed.
 */
function onlyEngines(array $engines): void
{
    // Counted in the fake rather than read back from `Process::recorded()`,
    // which is not reachable through the facade here.
    $GLOBALS['engineProbes'] = [];

    Process::fake(function ($process) use ($engines) {
        $command = implode(' ', (array) $process->command);

        foreach (['mysql' => 'mysql', 'mariadb' => 'mariadb', 'mongodb' => 'mongosh', 'postgresql' => 'psql'] as $engine => $client) {
            if (str_contains($command, $client)) {
                $GLOBALS['engineProbes'][] = $engine;

                return in_array($engine, $engines, true)
                    ? Process::result(output: '1')
                    : Process::result(exitCode: 1, errorOutput: 'command not found');
            }
        }

        return Process::result(output: '');
    });
}

/**
 * Prefixed because Pest helpers share one global namespace across the whole
 * suite: a bare catalog() collides with the permissions one and takes the
 * entire run down with a fatal, not a failed test.
 *
 * @return array<string, array<string, mixed>> keyed by type name
 */
function siteTypeCatalog(): array
{
    return collect(
        test()->withHeaders(['Authorization' => 'Bearer '.test()->token])
            ->getJson('/api/site-types')
            ->assertOk()
            ->json('site_types')
    )->keyBy('name')->all();
}

it('greys a SQL-backed type on a server with only MongoDB', function () {
    onlyEngines(['mongodb']);

    $types = siteTypeCatalog();

    expect($types['wordpress']['available'])->toBeFalse()
        ->and($types['wordpress']['unavailable_code'])->toBe(SiteTypeManager::BLOCKED_DATABASE)
        // Named so the sentence can say what to install, rather than "a
        // database" — the whole point of the block is that it is actionable.
        ->and($types['wordpress']['unavailable_reason'])->toContain('MySQL');
});

it('offers a MongoDB type on that same server', function () {
    // The other half of the same answer: greying everything would be as wrong
    // as greying nothing, and this is the type the old check did handle.
    onlyEngines(['mongodb']);

    expect(siteTypeCatalog()['nodebb']['available'])->toBeTrue();
});

it('offers SQL-backed types when MySQL answers', function () {
    onlyEngines(['mysql']);

    $types = siteTypeCatalog();

    expect($types['wordpress']['available'])->toBeTrue()
        ->and($types['nodebb']['available'])->toBeFalse();
});

it('accepts either of the engines a type lists', function () {
    // MariaDB alone is a MySQL-compatible server. A type accepting both must
    // not be refused because the first name in its list is absent.
    onlyEngines(['mariadb']);

    expect(siteTypeCatalog()['wordpress']['available'])->toBeTrue();
});

it('leaves a type that needs no database alone when nothing is installed', function () {
    // The check must not become "this server has a database engine". A static
    // site does not care, and greying it would hide the catalog for the exact
    // reason the old early return worried about.
    onlyEngines([]);

    expect(siteTypeCatalog()['php']['available'])->toBeTrue();
});

it('probes each engine once however many types ask about it', function () {
    // Not an optimisation. `available()` is a live query through sudo, and the
    // check that used to run for one type now runs for eleven — unmemoized
    // that is dozens of subprocesses to render a grid of cards.
    onlyEngines(['mysql']);

    siteTypeCatalog();

    $probes = collect($GLOBALS['engineProbes']);

    // One per distinct engine, not one per type that asks.
    expect($probes->count())->toBe($probes->unique()->count());
});

it('publishes the engines each type accepts', function () {
    onlyEngines(['mysql']);

    $types = siteTypeCatalog();

    expect($types['wordpress']['accepted_engines'])->toContain('mysql')
        // MongoDB first: the first available engine wins, so a server
        // with both keeps making Mongo-backed forums.
        ->and($types['nodebb']['accepted_engines'])->toBe(['mongodb', 'postgresql'])
        // Empty rather than null for a type that needs none, so "no
        // constraint" is not a special case for the caller.
        ->and($types['php']['accepted_engines'])->toBe([]);
});

it('never advertises an engine the installer would refuse', function () {
    // The list comes from the installer provisioning asks, so the catalog
    // cannot offer a pairing that creating would reject.
    onlyEngines(['mysql', 'mariadb', 'mongodb']);

    foreach (siteTypeCatalog() as $name => $type) {
        if ($type['accepted_engines'] === []) {
            continue;
        }

        expect($type['needs_database'])->toBeTrue("{$name} lists engines but says it needs no database");
    }
});

/**
 * Choosing the engine, where the server genuinely offers a choice.
 *
 * Until PostgreSQL existed there was never one to offer. Eight types list
 * `mysql, mariadb`, which reads like a choice and is not — the two cannot
 * coexist, they fight over 3306 and the installer refuses the second, so
 * exactly one is ever usable. NodeBB's `mongodb, postgresql` is the first
 * pairing a server can have both halves of, and the panel was picking for the
 * user silently on a decision nothing can undo afterwards.
 */
describe('the database engine picker', function () {
    it('offers no choice when the server has only one of the engines', function () {
        onlyEngines(['mongodb']);

        $fields = collect(siteTypeCatalog()['nodebb']['fields'])->pluck('name');

        // A dropdown with one option is a decision the user cannot make.
        expect($fields)->not->toContain('database_engine');
    });

    it('offers the choice when the server has both', function () {
        onlyEngines(['mongodb', 'postgresql']);

        $field = collect(siteTypeCatalog()['nodebb']['fields'])->firstWhere('name', 'database_engine');

        expect($field)->not->toBeNull()
            ->and(collect($field['options'])->pluck('value')->all())->toBe(['mongodb', 'postgresql'])
            // The default is what provisioning would have chosen anyway, so
            // the form and the fallback cannot disagree.
            ->and($field['default'])->toBe('mongodb')
            // Optional: not choosing is still allowed, and means the default.
            ->and($field['required'])->toBeFalse();
    });

    it('never offers it for a type whose two engines cannot coexist', function () {
        // MySQL and MariaDB are one choice wearing two names. Even with both
        // somehow answering, WordPress must not sprout a picker — and this is
        // the case that would have made the field appear on eight types.
        onlyEngines(['mysql', 'mariadb']);

        $fields = collect(siteTypeCatalog()['wordpress']['fields'])->pluck('name');

        expect($fields)->not->toContain('database_engine');
    })->skip('mysql and mariadb cannot both be installed; kept as documentation of the intent');

    it('provisions the engine the user picked, not the first one', function () {
        onlyEngines(['mongodb', 'postgresql']);

        $app = Application::factory()->create([
            'site_type' => 'nodebb',
            'settings' => ['database_engine' => 'postgresql'],
        ]);

        // The whole point: MongoDB is first in NodeBB's list and available, so
        // the fallback would have chosen it.
        expect(chosenEngineFor($app, ['mongodb', 'postgresql']))->toBe('postgresql');
    });

    it('falls back when nothing was chosen', function () {
        onlyEngines(['mongodb', 'postgresql']);

        $app = Application::factory()->create(['site_type' => 'nodebb', 'settings' => []]);

        expect(chosenEngineFor($app, ['mongodb', 'postgresql']))->toBe('mongodb');
    });

    it('falls back when the chosen engine stopped answering after the request', function () {
        // Validation ran when the form was submitted; provisioning runs later
        // in a queued job. An engine removed or stopped in between must not
        // fail the whole install on a stale choice.
        onlyEngines(['mongodb']);

        $app = Application::factory()->create([
            'site_type' => 'nodebb',
            'settings' => ['database_engine' => 'postgresql'],
        ]);

        expect(chosenEngineFor($app, ['mongodb', 'postgresql']))->toBe('mongodb');
    });
});

/**
 * The engine `provisionDatabase()` actually provisions.
 *
 * Goes through that method rather than calling the two decision helpers
 * directly, which is what the first version did — and reverting the line that
 * *uses* them left every test green, because nothing exercised the wiring. A
 * test of two methods the caller might not call is a test of nothing.
 *
 * It creates a real database row, which is the point: the answer is read back
 * from what was provisioned, not from what was returned.
 *
 * @param  array<int, string>  $accepted
 */
function chosenEngineFor(Application $app, array $accepted): ?string
{
    $method = new ReflectionMethod(app(InstallerManager::class), 'provisionDatabase');
    $method->invoke(app(InstallerManager::class), $app, $accepted);

    return Database::query()->where('application_id', $app->id)->value('engine');
}

describe('the create endpoint and the engine choice', function () {
    beforeEach(function () {
        $this->seed(PermissionSeeder::class);
        $this->admin = User::factory()->admin()->create();
        $this->token = $this->admin->createToken('t')->plainTextToken;
        $this->su = SystemUser::create(['username' => 'siteowner', 'home_path' => '/home/siteowner']);
    });

    it('refuses an engine the application cannot use', function () {
        onlyEngines(['mysql', 'postgresql']);

        // WordPress is MySQL/MariaDB only. Accepting this would create a
        // PostgreSQL database and hand WordPress a config it cannot use —
        // the site reported Active and broken at first load.
        createWithEngine('wordpress', 'postgresql')
            ->assertStatus(422)
            ->assertJsonValidationErrors('database_engine');
    });

    it('refuses an engine this server does not have', function () {
        onlyEngines(['mongodb']);

        // Accepted by NodeBB, absent here. A different mistake from the one
        // above and a different way out — install it, rather than pick again.
        createWithEngine('nodebb', 'postgresql')
            ->assertStatus(422)
            ->assertJsonValidationErrors('database_engine');
    });

    it('stores a valid choice so provisioning can honour it', function () {
        onlyEngines(['mongodb', 'postgresql']);

        createWithEngine('nodebb', 'postgresql')->assertSuccessful();

        expect(Application::query()->where('site_type', 'nodebb')->value('settings'))
            ->toHaveKey('database_engine', 'postgresql');
    });

    it('still accepts a request that names no engine at all', function () {
        onlyEngines(['mongodb', 'postgresql']);

        // Every existing client. The field is a choice, not a new obligation.
        $this->withHeaders(['Authorization' => 'Bearer '.$this->token])
            ->postJson('/api/applications', [
                'system_user_id' => $this->su->id,
                'name' => 'Forum plain', 'domain' => 'plain.example.com',
                'site_type' => 'nodebb', 'node_version' => '22',
                'admin_username' => 'admin',
                'admin_email' => 'a@example.com',
                'admin_password' => 'a-long-password',
            ])->assertSuccessful();
    });
});

function createWithEngine(string $type, string $engine)
{
    $payload = [
        'system_user_id' => test()->su->id,
        'name' => ucfirst($type).' '.$engine,
        'domain' => "{$type}-{$engine}.example.com",
        'site_type' => $type,
        'database_engine' => $engine,
    ];

    if ($type === 'nodebb') {
        $payload += [
            'node_version' => '22',
            'admin_username' => 'admin',
            'admin_email' => 'a@example.com',
            'admin_password' => 'a-long-password',
        ];
    } else {
        $payload += ['admin_email' => 'a@example.com', 'admin_password' => 'a-long-password', 'site_title' => 'T'];
    }

    return test()->withHeaders(['Authorization' => 'Bearer '.test()->token])
        ->postJson('/api/applications', $payload);
}

it('lists PostgreSQL last for every type that now takes it', function () {
    onlyEngines(['mysql']);

    $types = siteTypeCatalog();

    // Order is behaviour, not presentation: provisioning takes the first
    // accepted engine the server actually has, so PostgreSQL ahead of MySQL
    // would silently move every new site on an existing server onto a
    // different database. Last is what keeps them where they are.
    foreach (['craftcms', 'joomla', 'moodle', 'nextcloud'] as $type) {
        expect($types[$type]['accepted_engines'])->toBe(['mysql', 'mariadb', 'postgresql'], $type);
    }
});

it('keeps the four MySQL-only types MySQL-only', function () {
    onlyEngines(['mysql']);

    $types = siteTypeCatalog();

    // Upstream has no supported PostgreSQL path for any of these, so a card
    // offering it would be an install that fails at the application's own
    // setup — which is the failure acceptedEngines() exists to prevent.
    foreach (['wordpress', 'prestashop', 'mautic', 'akaunting'] as $type) {
        expect($types[$type]['accepted_engines'])->toBe(['mysql', 'mariadb'], $type);
    }
});
