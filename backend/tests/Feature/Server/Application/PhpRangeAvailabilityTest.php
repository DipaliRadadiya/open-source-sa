<?php

use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Applications\SiteTypeManager;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

/**
 * A card whose PHP range nothing on the server can reach.
 *
 * Found on an OpenLiteSpeed server on Ubuntu 26.04, 2026-09-26: PrestaShop runs
 * on 7.2 – 8.1, LiteSpeed publishes lsphp82 – lsphp85 for that release, and the
 * card said "available". The form was filled in and refused with "choose a
 * version in the range". And `POST /php/versions {"version":"8.1"}` — the only
 * way out it pointed at — queued a job that failed minutes later saying the
 * repository was misconfigured, about a repository that was fine.
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

    $this->phpDir = sys_get_temp_dir().'/sv-oss-php-avail-'.getmypid();
    config(['server.php_dir' => $this->phpDir]);
    installedPhp(['8.4']);
});

afterEach(function () {
    File::deleteDirectory($this->phpDir);
});

/**
 * @param  list<string>  $versions
 */
function installedPhp(array $versions): void
{
    File::deleteDirectory(test()->phpDir);

    foreach ($versions as $version) {
        File::makeDirectory(test()->phpDir."/{$version}/fpm", 0755, true);
    }
}

/**
 * A reachable SQL engine, and a package index holding `$index`.
 *
 * @param  list<string>  $index
 */
function phpIndexOffers(array $index): void
{
    $GLOBALS['phpIndexReads'] = 0;

    Process::fake(function (mixed $process) use ($index) {
        if (($process->command[0] ?? '') === 'apt-cache') {
            $GLOBALS['phpIndexReads']++;

            return Process::result(output: implode('', array_map(
                fn (string $version) => "php{$version}-fpm - server-side scripting\n",
                $index,
            )));
        }

        return fakeDatabaseAnswer($process) ?? Process::result(exitCode: 1);
    });
}

function phpAvailabilityCatalog(): array
{
    return collect(
        test()->withHeaders(['Authorization' => 'Bearer '.test()->token])
            ->getJson('/api/site-types')
            ->assertOk()
            ->json('site_types')
    )->keyBy('name')->all();
}

it('greys a type when no PHP in its range is installed or installable', function () {
    phpIndexOffers(['8.4', '8.5']);

    $prestashop = phpAvailabilityCatalog()['prestashop'];

    expect($prestashop['available'])->toBeFalse()
        ->and($prestashop['unavailable_code'])->toBe(SiteTypeManager::BLOCKED_PHP_VERSION)
        ->and($prestashop['unavailable_reason'])->toContain('7.2 – 8.1')
        ->and($prestashop['unavailable_reason'])->toContain('can be installed from this server')
        // Not an offer to install the PHP runtime: it is already here.
        ->and($prestashop['installable_runtime'])->toBeNull();
});

it('names the version to install when the index has one in range', function () {
    phpIndexOffers(['8.0', '8.1', '8.4', '8.5']);

    $prestashop = phpAvailabilityCatalog()['prestashop'];

    expect($prestashop['unavailable_code'])->toBe(SiteTypeManager::BLOCKED_PHP_VERSION)
        // The newest in range, not the first the index happens to list.
        ->and($prestashop['unavailable_reason'])->toContain('Install PHP 8.1');
});

it('offers the type once a version in its range is installed', function () {
    installedPhp(['8.1', '8.4']);
    phpIndexOffers([]);

    expect(phpAvailabilityCatalog()['prestashop']['available'])->toBeTrue();
});

it('leaves a type with no PHP range alone', function () {
    phpIndexOffers([]);

    $types = phpAvailabilityCatalog();

    expect($types['php']['available'])->toBeTrue()
        ->and($types['git']['unavailable_code'])->not->toBe(SiteTypeManager::BLOCKED_PHP_VERSION);
});

it('reads the package index only when nothing installed fits', function () {
    // Every range in the catalog is reachable from 8.1 or 8.4, so the catalog
    // must not pay an apt-cache call per type to find that out.
    installedPhp(['8.1', '8.4']);
    phpIndexOffers(['8.1', '8.4']);

    phpAvailabilityCatalog();

    expect($GLOBALS['phpIndexReads'])->toBe(0);
});

it('refuses to create the type, with the reason the card showed', function () {
    phpIndexOffers(['8.4', '8.5']);
    $user = SystemUser::create(['username' => 'shopowner', 'home_path' => '/home/shopowner']);

    $reason = phpAvailabilityCatalog()['prestashop']['unavailable_reason'];
    // Without this, a catalog that blocks nothing gives null here and null in
    // the response, and the comparison below passes about nothing.
    expect($reason)->toBeString()->not->toBeEmpty();

    $this->withHeaders(['Authorization' => 'Bearer '.$this->token])
        ->postJson('/api/applications', [
            'system_user_id' => $user->id,
            'name' => 'Shop', 'domain' => 'shop.example.com',
            'site_type' => 'prestashop',
            'shop_name' => 'Shop',
            'admin_first_name' => 'Admin', 'admin_last_name' => 'User',
            'admin_email' => 'a@example.com', 'admin_password' => 'a-long-password',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.site_type.0', $reason);
});

it('refuses to install a PHP version the package index does not have', function () {
    Queue::fake();
    phpIndexOffers(['8.2', '8.3', '8.4', '8.5']);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$this->token])
        ->postJson('/api/php/versions', ['version' => '8.1'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('version');

    expect($response->json('errors.version.0'))->toBe(__('php.not_installable', ['version' => '8.1']));
    Queue::assertNothingPushed();
});

it('still installs a version the index has', function () {
    Queue::fake();
    phpIndexOffers(['8.2', '8.3', '8.4', '8.5']);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->token])
        ->postJson('/api/php/versions', ['version' => '8.2'])
        ->assertStatus(202);
});

it('accepts an installed version without asking the index', function () {
    // Asking again is how a half-installed version gets completed, and the
    // index lists only what is not installed.
    Queue::fake();
    phpIndexOffers([]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->token])
        ->postJson('/api/php/versions', ['version' => '8.4'])
        ->assertJsonMissingValidationErrors('version');
});
