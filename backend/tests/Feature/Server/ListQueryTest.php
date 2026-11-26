<?php

use App\Http\Requests\Server\Application\IndexApplicationsRequest;
use App\Models\Application;
use App\Models\BackupTarget;
use App\Models\Database;
use App\Models\FirewallRule;
use App\Models\Role;
use App\Models\StorageDestination;
use App\Models\SystemUser;
use App\Models\User;
use App\Support\ListSearch;
use App\Support\ListSort;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

/*
 * Server-side search, filter, sort and paging for the five lists the screen used to filter
 * client-side (correct only while the array was the whole table).
 *
 * One file because the properties are shared: a page has a bound, an unknown filter is refused
 * rather than answered with nothing, and an ordering is stable enough to page through.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

describe('every paged list', function () {

    /* Ten per page on every endpoint, even when the client asks for more; the defaults must agree. */
    it('returns ten rows by default and honours the sizes the page-size control offers', function () {
        Database::factory()->count(12)->create(['engine' => 'mariadb']);
        grantPermission($this->user, 'database');

        $this->getJson('/api/databases')
            ->assertOk()
            ->assertJsonCount(10, 'databases')
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 12)
            ->assertJsonPath('meta.last_page', 2);

        $this->getJson('/api/databases?per_page=30')
            ->assertOk()
            ->assertJsonCount(12, 'databases')
            ->assertJsonPath('meta.last_page', 1);
    });

    /* A size the control does not offer is a client bug; honouring it would add an undocumented page size. */
    it('refuses a page size that is not one of the offered ones', function () {
        grantPermission($this->user, 'database');

        $this->getJson('/api/databases?per_page=7')->assertStatus(422);
    });

    /* The row must be found by a query, not by the browser having been handed every row. */
    it('searches on the server and says so honestly when there is no match', function () {
        Database::factory()->create(['name' => 'shop_production', 'engine' => 'mariadb']);
        Database::factory()->count(15)->create(['engine' => 'mariadb']);
        grantPermission($this->user, 'database');

        $found = $this->getJson('/api/databases?search=shop_prod')->assertOk();

        expect($found->json('databases'))->toHaveCount(1)
            ->and($found->json('databases.0.name'))->toBe('shop_production')
            // Not `total: 16`: an unfiltered count makes the pager offer pages that do not exist.
            ->and($found->json('meta.total'))->toBe(1);

        $this->getJson('/api/databases?search=nothing-by-this-name')
            ->assertOk()
            ->assertJsonCount(0, 'databases')
            ->assertJsonPath('meta.total', 0);
    });

    /*
     * API standard §7.2: reject unknown filter and sort keys with a 422. An empty list would be
     * indistinguishable from "you have no databases".
     */
    it('refuses a filter value that is not real rather than answering with nothing', function () {
        Database::factory()->count(3)->create(['engine' => 'mariadb']);
        grantPermission($this->user, 'database');

        $this->getJson('/api/databases?filter[engine]=oracle')->assertStatus(422);

        $this->getJson('/api/databases?filter[engine]=mariadb')
            ->assertOk()
            ->assertJsonCount(3, 'databases');
    });

    /*
     * The column reaching ORDER BY comes from the endpoint's whitelist, never from the request;
     * the 422 stops `sort=password` probing columns the API does not expose.
     */
    it('refuses a sort column the endpoint does not offer', function () {
        grantPermission($this->user, 'database');

        $this->getJson('/api/databases?sort=id; drop table databases')->assertStatus(422);
        $this->getJson('/api/databases?sort=secret_column')->assertStatus(422);

        $this->getJson('/api/databases?sort=-name')->assertOk();
    });

    it('sorts across the whole table, not within the page', function () {
        foreach (['delta', 'alpha', 'charlie', 'bravo'] as $name) {
            Database::factory()->create(['name' => $name, 'engine' => 'mariadb']);
        }

        grantPermission($this->user, 'database');

        $first = $this->getJson('/api/databases?sort=name&per_page=10')->json('databases.0.name');

        expect($first)->toBe('alpha');
    });

    it('searches and sorts database names without case bias', function () {
        foreach (['Case_Zebra', 'case_apple', 'CASE_Banana'] as $name) {
            Database::factory()->create(['name' => $name, 'engine' => 'mariadb']);
        }

        grantPermission($this->user, 'database');

        $names = collect($this->getJson('/api/databases?search=CASE&sort=name')->assertOk()->json('databases'))
            ->pluck('name')->all();

        expect($names)->toBe(['case_apple', 'CASE_Banana', 'Case_Zebra']);
    });

    /*
     * Rows that tie on the sorted column may come back in any order, so paging could show a row
     * on two pages and hide another.
     *
     * Asserted against the generated SQL, not by paging real rows: SQLite scans in rowid order,
     * so a paging test passes even with the tiebreak removed. The claim checked is "every sorted
     * query ends with a unique column".
     */
    it('always ends a sorted query with a unique column', function () {
        $sql = ListSort::apply(Database::query(), 'name', ['name', 'created_at'])->toSql();

        expect($sql)->toContain('order by "name" asc, "databases"."id" desc');

        // The default sort, when nothing was asked for.
        expect(ListSort::apply(Database::query(), null, ['created_at'])->toSql())
            ->toContain('order by "created_at" desc, "databases"."id" desc');
    });

    /*
     * Only the whitelist reaches ORDER BY. The FormRequest refuses unknown columns first, but this
     * class is what puts a string into SQL, so it re-checks rather than trusting its callers.
     */
    it('falls back to the whitelist when handed a column that is not on it', function () {
        $sql = ListSort::apply(Database::query(), 'password', ['created_at', 'name'])->toSql();

        expect($sql)->toContain('order by "created_at" desc')
            ->and($sql)->not->toContain('password');
    });

    /* `%` and `_` are LIKE wildcards: unescaped, `shop_db` would match `shopxdb` and `50%` would match `sale50off`. */
    it('matches what was typed, wildcard characters included', function () {
        foreach (['shopxdb', 'shop_db', 'sale50off', 'sale50%off', 'bang!db', 'bangxdb'] as $name) {
            Database::factory()->create(['name' => $name, 'engine' => 'mariadb']);
        }
        grantPermission($this->user, 'database');

        $names = fn (string $search) => collect($this->getJson('/api/databases?search='.rawurlencode($search))
            ->assertOk()->json('databases'))->pluck('name')->sort()->values()->all();

        expect($names('shop_db'))->toBe(['shop_db'])
            ->and($names('_'))->toBe(['shop_db'])
            ->and($names('50%'))->toBe(['sale50%off'])
            ->and($names('%'))->toBe(['sale50%off'])
            ->and($names('!'))->toBe(['bang!db'])
            ->and($names('!d'))->toBe(['bang!db'])
            ->and($names('SHOP'))->toBe(['shop_db', 'shopxdb']);
    });

    it('compiles portable case-insensitive search for PostgreSQL', function () {
        $connection = Database::query()->getConnection();
        $original = $connection->getQueryGrammar();

        try {
            $connection->setQueryGrammar(new PostgresGrammar($connection));
            $sql = ListSearch::apply(Database::query(), 'SHOP', ['name'])->toSql();
        } finally {
            $connection->setQueryGrammar($original);
        }

        expect($sql)->toContain('ilike');
    });
});

/*
 * The applications list: search, filters, paging and sort all live in the URL. Client-side
 * versions would silently operate on the first page alone.
 */
describe('GET /applications', function () {

    beforeEach(function () {
        grantPermission($this->user, 'application');
    });

    it('orders newest first when nothing is asked for', function () {
        $old = Application::factory()->create(['name' => 'first']);
        $new = Application::factory()->create(['name' => 'second']);

        $names = collect($this->getJson('/api/applications')->assertOk()->json('applications'))
            ->pluck('name')->all();

        expect($names)->toBe([$new->name, $old->name]);
    });

    it('sorts by name across the whole table, not the page', function () {
        foreach (['delta', 'alpha', 'charlie'] as $name) {
            Application::factory()->create(['name' => $name]);
        }

        expect($this->getJson('/api/applications?sort=name&per_page=1')->assertOk()->json('applications.0.name'))
            ->toBe('alpha');
    });

    it('sorts names without separating uppercase and lowercase letters', function () {
        foreach (['Zebra', 'apple', 'Banana', 'alpha'] as $name) {
            Application::factory()->create(['name' => $name]);
        }

        $ascending = collect($this->getJson('/api/applications?sort=name')->assertOk()->json('applications'))
            ->pluck('name')->all();
        $descending = collect($this->getJson('/api/applications?sort=-name')->assertOk()->json('applications'))
            ->pluck('name')->all();

        expect($ascending)->toBe(['alpha', 'apple', 'Banana', 'Zebra'])
            ->and($descending)->toBe(['Zebra', 'Banana', 'apple', 'alpha']);
    });

    it('refuses a sort column the table does not show', function () {
        // `owner` lives on a relation, so it is deliberately not sortable; a 422, not a silent
        // fallback to another order.
        $this->getJson('/api/applications?sort=owner')->assertStatus(422);
        $this->getJson('/api/applications?sort=php_version')->assertStatus(422);
    });

    /*
     * A site nothing has measured yet is not a site of zero bytes, and the two must not mix.
     * The table put unmeasured sites at one end (null as -1); the server must match it.
     */
    it('treats a never-measured site as smaller than an empty one', function () {
        $unmeasured = Application::factory()->create(['name' => 'unmeasured', 'directory_size_bytes' => null]);
        $empty = Application::factory()->create(['name' => 'empty', 'directory_size_bytes' => 0]);
        $big = Application::factory()->create(['name' => 'big', 'directory_size_bytes' => 5_000_000]);

        $ascending = collect($this->getJson('/api/applications?sort=directory_size_bytes')->assertOk()->json('applications'))
            ->pluck('name')->all();

        expect($ascending)->toBe([$unmeasured->name, $empty->name, $big->name]);
    });

    /*
     * The behavioural tests around this one pass even without the null handling: SQLite sorts
     * NULL first ascending, PostgreSQL last, so installs on different databases would order
     * differently. This asserts the generated SQL instead.
     */
    it('orders nulls explicitly rather than inheriting the database default', function () {
        $sql = ListSort::apply(
            Application::query(),
            '-directory_size_bytes',
            IndexApplicationsRequest::SORTS,
            nullsSmallest: IndexApplicationsRequest::NULLS_SMALLEST,
        )->toSql();

        // Descending: unknown goes last, so "is null" ascends (false before true).
        expect($sql)->toContain('"directory_size_bytes" is null asc');

        $ascending = ListSort::apply(
            Application::query(),
            'directory_size_bytes',
            IndexApplicationsRequest::SORTS,
            nullsSmallest: IndexApplicationsRequest::NULLS_SMALLEST,
        )->toSql();

        expect($ascending)->toContain('"directory_size_bytes" is null desc');

        // A column not on the list is untouched: the clause is opt-in per column.
        expect(ListSort::apply(Application::query(), 'name', IndexApplicationsRequest::SORTS)->toSql())
            ->not->toContain('is null');
    });

    /* Descending is "biggest first", and an unknown size is not big. */
    it('puts never-measured last when sorting biggest first', function () {
        Application::factory()->create(['name' => 'unmeasured', 'directory_size_bytes' => null]);
        Application::factory()->create(['name' => 'empty', 'directory_size_bytes' => 0]);
        Application::factory()->create(['name' => 'big', 'directory_size_bytes' => 5_000_000]);

        $descending = collect($this->getJson('/api/applications?sort=-directory_size_bytes')->assertOk()->json('applications'))
            ->pluck('name')->all();

        expect($descending)->toBe(['big', 'empty', 'unmeasured']);
    });

    it('still searches and filters while sorted', function () {
        Application::factory()->create(['name' => 'shop', 'directory_size_bytes' => 10]);
        Application::factory()->create(['name' => 'shop archive', 'directory_size_bytes' => 20]);
        Application::factory()->create(['name' => 'blog', 'directory_size_bytes' => 30]);

        $response = $this->getJson('/api/applications?search=SHOP&sort=-directory_size_bytes')->assertOk();

        expect(collect($response->json('applications'))->pluck('name')->all())
            ->toBe(['shop archive', 'shop'])
            ->and($response->json('meta.total'))->toBe(2);
    });
});

describe('GET /system-users', function () {

    it('pages and searches by username', function () {
        SystemUser::factory()->count(12)->create();
        SystemUser::factory()->create(['username' => 'deployer']);
        grantPermission($this->user, 'system_user');

        $this->getJson('/api/system-users')
            ->assertOk()
            ->assertJsonCount(10, 'system_users')
            ->assertJsonPath('meta.total', 13);

        $this->getJson('/api/system-users?search=DEPLOY')
            ->assertOk()
            ->assertJsonCount(1, 'system_users')
            ->assertJsonPath('system_users.0.username', 'deployer');
    });

    it('still refuses a caller without the permission', function () {
        $this->getJson('/api/system-users')->assertForbidden();
    });
});

describe('GET /admin/roles', function () {

    it('pages, searches name and description, and orders by name', function () {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Role::factory()->create(['name' => 'Zulu', 'description' => 'last alphabetically']);
        Role::factory()->create(['name' => 'Alpha', 'description' => 'billing team']);

        $this->getJson('/api/admin/roles')
            ->assertOk()
            // Administrator is seeded, so this is the two above plus it.
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('roles.0.name', 'Administrator');

        // The description is searchable: a role is often remembered by what it is for.
        $this->getJson('/api/admin/roles?search=billing')
            ->assertOk()
            ->assertJsonCount(1, 'roles')
            ->assertJsonPath('roles.0.name', 'Alpha');
    });

    it('searches and sorts role names without case bias', function () {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        foreach (['Case Zebra', 'case apple', 'CASE Banana'] as $name) {
            Role::factory()->create(['name' => $name, 'description' => 'Mixed Case Group']);
        }

        $names = collect($this->getJson('/api/admin/roles?search=mIxEd&sort=name')->assertOk()->json('roles'))
            ->pluck('name')->all();

        expect($names)->toBe(['case apple', 'CASE Banana', 'Case Zebra']);
    });

    it('is still admin only', function () {
        $this->getJson('/api/admin/roles')->assertForbidden();
    });
});

describe('GET /firewall/rules', function () {

    beforeEach(function () {
        grantPermission($this->user, 'firewall');
    });

    it('pages the rules without rebuilding the firewall status', function () {
        for ($port = 8000; $port < 8012; $port++) {
            FirewallRule::create(['port_from' => $port, 'protocol' => 'tcp', 'action' => 'allow', 'origin' => 'user']);
        }

        $this->getJson('/api/firewall/rules')
            ->assertOk()
            ->assertJsonCount(10, 'rules')
            ->assertJsonPath('meta.total', 12)
            // Deliberately absent: this endpoint lists rules; `GET /firewall` reports the firewall
            // state and shells out to `ss` to do it.
            ->assertJsonMissingPath('listening')
            ->assertJsonMissingPath('your_ip');
    });

    it('finds a rule by port, by source and by description', function () {
        FirewallRule::create([
            'port_from' => 5432, 'protocol' => 'tcp', 'action' => 'allow',
            'source_ip' => '10.0.0.7', 'description' => 'reporting box', 'origin' => 'user',
        ]);
        FirewallRule::create(['port_from' => 80, 'protocol' => 'tcp', 'action' => 'allow', 'origin' => 'default']);

        foreach (['5432', '10.0.0.7', 'REPORTING'] as $term) {
            expect($this->getJson("/api/firewall/rules?search={$term}")->json('rules'))
                ->toHaveCount(1, "searching for {$term}");
        }
    });

    /*
     * `filter[enabled]=0` asks for the disabled rules, not an absent filter. Read with `??` it is
     * falsy and dropped, and the endpoint answers with everything.
     */
    it('treats filter[enabled]=0 as a request for the disabled rules', function () {
        FirewallRule::create(['port_from' => 80, 'protocol' => 'tcp', 'action' => 'allow', 'origin' => 'user', 'enabled' => true]);
        FirewallRule::create(['port_from' => 443, 'protocol' => 'tcp', 'action' => 'allow', 'origin' => 'user', 'enabled' => false]);

        $this->getJson('/api/firewall/rules?filter[enabled]=0')
            ->assertOk()
            ->assertJsonCount(1, 'rules')
            ->assertJsonPath('rules.0.port_from', 443);
    });

    it('leaves the composite firewall endpoint returning its rules unchanged', function () {
        // Faked locally, not via FirewallTest's `fakeUfw`: Pest helpers are global only once their
        // file is loaded, so borrowing one makes the pass depend on which files run.
        // `GET /firewall` asks ufw for its live status; the paged endpoint exists to avoid that.
        Process::fake(fn ($process) => in_array('status', $process->command, true)
            ? Process::result(output: "Status: active\nDefault: deny (incoming), allow (outgoing), disabled (routed)\n")
            : Process::result(exitCode: 0));

        FirewallRule::create(['port_from' => 80, 'protocol' => 'tcp', 'action' => 'allow', 'origin' => 'user']);

        // The existing endpoint keeps working, so the frontend can move over when ready.
        $this->getJson('/api/firewall')->assertOk()->assertJsonCount(1, 'rules');
    });
});

describe('GET /backup-targets', function () {

    beforeEach(function () {
        grantPermission($this->user, 'backup');

        $this->destination = StorageDestination::create([
            'name' => 'Offsite',
            'provider' => 's3',
            'config' => ['endpoint' => '', 'region' => 'us-east-1', 'bucket' => 'backups', 'access_key' => 'k', 'secret_key' => 's'],
        ]);
    });

    /*
     * The header says "N of M sites protected". Recomputed per page it would say "2 of 10" on a
     * server with forty sites, so the counts use their own aggregate queries.
     */
    it('keeps the coverage counts describing the server, not the page', function () {
        $applications = Application::factory()->count(12)->create();

        foreach ($applications->take(3) as $application) {
            BackupTarget::create([
                'application_id' => $application->id,
                'storage_destination_id' => $this->destination->id,
                'type' => 'full', 'retention_count' => 7, 'frequency' => 'daily', 'enabled' => true,
            ]);
        }

        $response = $this->getJson('/api/backup-targets?page=2')->assertOk();

        expect($response->json('meta.total'))->toBe(12)
            ->and($response->json('meta.protected'))->toBe(3)
            ->and($response->json('meta.unprotected'))->toBe(9)
            // The page itself holds the remaining two rows.
            ->and($response->json('backup_targets'))->toHaveCount(2);
    });

    it('separates how many sites exist from how many the search matched', function () {
        Application::factory()->count(5)->create();
        Application::factory()->create(['name' => 'invoices']);

        $response = $this->getJson('/api/backup-targets?search=INVOICE')->assertOk();

        expect($response->json('meta.matched'))->toBe(1)
            ->and($response->json('meta.total'))->toBe(6);
    });

    it('sorts backup target names without case bias', function () {
        foreach (['Case Zebra', 'case apple', 'CASE Banana'] as $name) {
            Application::factory()->create(['name' => $name]);
        }

        $names = collect($this->getJson('/api/backup-targets?search=case&sort=name')->assertOk()->json('backup_targets'))
            ->pluck('application_name')->all();

        expect($names)->toBe(['case apple', 'CASE Banana', 'Case Zebra']);
    });

    it('filters to the sites that are not backed up at all', function () {
        $protected = Application::factory()->create();
        Application::factory()->count(2)->create();

        BackupTarget::create([
            'application_id' => $protected->id,
            'storage_destination_id' => $this->destination->id,
            'type' => 'full', 'retention_count' => 7, 'frequency' => 'daily', 'enabled' => true,
        ]);

        $this->getJson('/api/backup-targets?filter[protected]=0')
            ->assertOk()
            ->assertJsonCount(2, 'backup_targets');

        $this->getJson('/api/backup-targets?filter[protected]=1')
            ->assertOk()
            ->assertJsonCount(1, 'backup_targets');
    });
});
