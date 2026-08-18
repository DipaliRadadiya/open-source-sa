<?php

use App\Models\Application;
use App\Models\BackupTarget;
use App\Models\Database;
use App\Models\FirewallRule;
use App\Models\Role;
use App\Models\StorageDestination;
use App\Models\SystemUser;
use App\Models\User;
use App\Support\ListSort;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

/*
 * The five lists that grew a search box on the screen before they had one on
 * the server. Each was returning every row, so the screen filtered and sorted
 * a JavaScript array — correct only for as long as the array was the whole
 * table. These tests pin the contract that replaces it.
 *
 * One file rather than five, because the interesting properties are the ones
 * all five share: a page has a bound, an unknown filter is refused rather than
 * answered with nothing, and an ordering is stable enough to page through.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

describe('every paged list', function () {

    /*
     * Ten, and the client asks for more. Named per endpoint rather than
     * inherited from a base class, but they had better agree — a page size that
     * differs per screen is a thing users notice and nobody decided.
     */
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

    /*
     * A size the control does not offer is a client bug. Honouring it quietly
     * would mean the API has a second, undocumented set of page sizes that
     * nobody chose and no test covers.
     */
    it('refuses a page size that is not one of the offered ones', function () {
        grantPermission($this->user, 'database');

        $this->getJson('/api/databases?per_page=7')->assertStatus(422);
    });

    /*
     * The whole point of the exercise: the row must be found by a query, not by
     * the browser having been handed every row.
     */
    it('searches on the server and says so honestly when there is no match', function () {
        Database::factory()->create(['name' => 'shop_production', 'engine' => 'mariadb']);
        Database::factory()->count(15)->create(['engine' => 'mariadb']);
        grantPermission($this->user, 'database');

        $found = $this->getJson('/api/databases?search=shop_prod')->assertOk();

        expect($found->json('databases'))->toHaveCount(1)
            ->and($found->json('databases.0.name'))->toBe('shop_production')
            // Not `total: 16`. A search that reports the unfiltered count makes
            // the pager offer pages that do not exist.
            ->and($found->json('meta.total'))->toBe(1);

        $this->getJson('/api/databases?search=nothing-by-this-name')
            ->assertOk()
            ->assertJsonCount(0, 'databases')
            ->assertJsonPath('meta.total', 0);
    });

    /*
     * §7.2 of the API standard: reject unknown filter and sort keys with a 422.
     * The alternative — an empty list — is indistinguishable from "you have no
     * databases", which is the failure the backup list shipped with once
     * already.
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
     * The column reaching ORDER BY comes from the endpoint's whitelist, never
     * from the request. A 422 here is also what stops `sort=password` being a
     * way to ask the database about a column the API does not expose.
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

    /*
     * Rows that tie on the sorted column leave the database free to return them
     * in any order, and free to pick a different one next time. Paged, that
     * shows a row on two pages and hides another entirely — which reads as data
     * loss and cannot be reproduced on demand.
     *
     * Asserted against the generated SQL rather than by paging real rows. The
     * paging version was written first and **passed with the tiebreak removed**:
     * SQLite happens to scan in rowid order, so the hazard this guards against
     * cannot be provoked on the database the suite runs on. A test that cannot
     * fail is not evidence, whatever colour it prints. The structural claim —
     * "every sorted query ends with a unique column" — is the part that is
     * actually true here, so that is what gets checked.
     */
    it('always ends a sorted query with a unique column', function () {
        $sql = ListSort::apply(Database::query(), 'name', ['name', 'created_at'])->toSql();

        expect($sql)->toContain('order by "name" asc, "databases"."id" desc');

        // And on the default, where nothing was asked for.
        expect(ListSort::apply(Database::query(), null, ['created_at'])->toSql())
            ->toContain('order by "created_at" desc, "databases"."id" desc');
    });

    /*
     * The whitelist is the only thing that ever reaches ORDER BY. The
     * FormRequest refuses an unknown column first, but this class is what puts
     * a string into SQL, so it checks its own precondition rather than trusting
     * every future caller to have validated.
     */
    it('falls back to the whitelist when handed a column that is not on it', function () {
        $sql = ListSort::apply(Database::query(), 'password', ['created_at', 'name'])->toSql();

        expect($sql)->toContain('order by "created_at" desc')
            ->and($sql)->not->toContain('password');
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

        $this->getJson('/api/system-users?search=deploy')
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

        // The description is searchable because the screen searched it: a role
        // is often remembered by what it is for rather than what it is called.
        $this->getJson('/api/admin/roles?search=billing')
            ->assertOk()
            ->assertJsonCount(1, 'roles')
            ->assertJsonPath('roles.0.name', 'Alpha');
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
            // Deliberately absent. This endpoint answers "which rules are
            // there"; `GET /firewall` answers "what is the state of the
            // firewall", and that one shells out to `ss` to do it.
            ->assertJsonMissingPath('listening')
            ->assertJsonMissingPath('your_ip');
    });

    it('finds a rule by port, by source and by description', function () {
        FirewallRule::create([
            'port_from' => 5432, 'protocol' => 'tcp', 'action' => 'allow',
            'source_ip' => '10.0.0.7', 'description' => 'reporting box', 'origin' => 'user',
        ]);
        FirewallRule::create(['port_from' => 80, 'protocol' => 'tcp', 'action' => 'allow', 'origin' => 'default']);

        foreach (['5432', '10.0.0.7', 'reporting'] as $term) {
            expect($this->getJson("/api/firewall/rules?search={$term}")->json('rules'))
                ->toHaveCount(1, "searching for {$term}");
        }
    });

    /*
     * `filter[enabled]=0` is a request for the disabled rules, not an absent
     * filter. Read with `??` it is falsy and gets dropped, and the endpoint
     * answers with everything — which looks like a filter that found nothing
     * wrong rather than one that never ran.
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
        // Faked locally rather than borrowing FirewallTest's `fakeUfw`. Pest
        // helpers are global once their file is loaded, and "once their file is
        // loaded" makes the dependency depend on which files this run happens
        // to include — an order-dependent pass, which is worse than a duplicate
        // four-line closure.
        //
        // Needed at all because `GET /firewall` asks ufw for its live status.
        // That is precisely the cost this new endpoint exists to keep out of a
        // page turn.
        Process::fake(fn ($process) => in_array('status', $process->command, true)
            ? Process::result(output: "Status: active\nDefault: deny (incoming), allow (outgoing), disabled (routed)\n")
            : Process::result(exitCode: 0));

        FirewallRule::create(['port_from' => 80, 'protocol' => 'tcp', 'action' => 'allow', 'origin' => 'user']);

        // Nothing on the screen breaks the moment this ships; the frontend
        // moves over when it is ready to.
        $this->getJson('/api/firewall')->assertOk()->assertJsonCount(1, 'rules');
    });
});

describe('GET /backup-targets', function () {

    beforeEach(function () {
        grantPermission($this->user, 'backup');

        $this->destination = StorageDestination::create([
            'name' => 'Offsite', 'endpoint' => '', 'region' => 'us-east-1',
            'bucket' => 'backups', 'access_key' => 'k', 'secret_key' => 's',
        ]);
    });

    /*
     * The header says "N of M sites protected". Recomputed per page it would
     * say "2 of 10" on a server with forty sites — a different sentence, and a
     * false one. The counts get their own aggregate queries for this reason.
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

        $response = $this->getJson('/api/backup-targets?search=invoice')->assertOk();

        expect($response->json('meta.matched'))->toBe(1)
            ->and($response->json('meta.total'))->toBe(6);
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
