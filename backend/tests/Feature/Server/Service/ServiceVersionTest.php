<?php

use App\Enums\InstallStatus;
use App\Models\RuntimeInstall;
use App\Models\User;
use App\Services\Server\PackageVersions;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/*
 * The Services screen shows each service's installed version beside its name
 * (Dipali, 2026-10-27: support asked for installed package versions; just the
 * version, on the existing screen). One dpkg-query, null when there is no
 * package to read.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->token = User::factory()->admin()->create()->createToken('t')->plainTextToken;

    $this->phpDir = sys_get_temp_dir().'/sv-oss-ver-'.uniqid();
    File::ensureDirectoryExists($this->phpDir);
    config([
        'server.php_dir' => $this->phpDir,
        'server.php_fpm_binary_pattern' => $this->phpDir.'/php-fpm{version}',
        'server.dpkg_status' => $this->phpDir.'/dpkg-status',
    ]);
    $this->dpkgCalls = 0;
});

afterEach(fn () => File::deleteDirectory($this->phpDir));

/**
 * Fake a server: systemd units from $units, dpkg packages from $packages
 * (name => version), answered like dpkg-query would for the patterns asked.
 *
 * @param  array<int, string>  $units
 * @param  array<string, string>  $packages
 */
function svFakeServer(array $units, array $packages): void
{
    $state = fn () => ['load' => 'loaded', 'active' => 'active', 'file' => 'enabled'];

    Process::fake(function ($process) use ($units, $packages, $state) {
        $cmd = (array) $process->command;
        $args = ($cmd[0] ?? '') === 'sudo' ? array_slice($cmd, 2) : $cmd;

        if (($args[1] ?? null) === 'show') {
            return Process::result(output: systemctlShowOutput($args, array_fill_keys($units, $state())));
        }

        if (($args[0] ?? null) === 'dpkg-query') {
            test()->dpkgCalls++;
            $lines = [];
            foreach (array_slice($args, 3) as $pattern) {
                foreach ($packages as $name => $version) {
                    if (fnmatch($pattern, $name)) {
                        $lines[$name] = "ii \t{$name}\t{$version}";
                    }
                }
            }

            return Process::result(output: implode("\n", $lines)."\n", exitCode: 1);
        }

        return Process::result();
    });
}

function svServicesById(string $token): array
{
    return collect(test()->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/services')->assertOk()->json('services'))->keyBy('key')->all();
}

function svWithPhp(string $version): void
{
    File::ensureDirectoryExists(test()->phpDir."/{$version}/fpm");
    File::put(test()->phpDir."/php-fpm{$version}", '');
}

it('shows each service\'s version on every stack', function (array $units, array $packages, array $expected, ?string $php = null) {
    if ($php !== null) {
        svWithPhp($php);
    }
    svFakeServer($units, $packages);

    $rows = svServicesById($this->token);

    foreach ($expected as $key => $version) {
        expect($rows)->toHaveKey($key)
            ->and($rows[$key]['version'])->toBe($version, $key);
    }
})->with([
    'nginx (LEMP)' => [
        ['nginx', 'php8.4-fpm', 'mariadb', 'redis-server', 'supervisor'],
        ['nginx' => '1.24.0-2ubuntu7.5', 'php8.4-fpm' => '8.4.13-1+ubuntu24.04.1+deb.sury.org+1', 'mariadb-server' => '1:10.11.14-0ubuntu0.24.04.1', 'redis-server' => '5:7.0.15-1ubuntu0.24.04.1', 'supervisor' => '4.2.5-1'],
        ['nginx' => '1.24.0', 'php8.4-fpm' => '8.4.13', 'mariadb' => '10.11.14', 'redis' => '7.0.15', 'supervisor' => '4.2.5'],
        '8.4',
    ],
    'Apache (LAMP)' => [
        ['apache2', 'mysql', 'fail2ban'],
        ['apache2' => '2.4.58-1ubuntu8.5', 'mysql-server-core-8.0' => '8.0.43-0ubuntu0.24.04.1', 'mysql-server' => '8.0.43-0ubuntu0.24.04.1', 'fail2ban' => '1.0.2-3ubuntu0.1'],
        ['apache' => '2.4.58', 'mysql' => '8.0.43', 'fail2ban' => '1.0.2'],
    ],
    'OpenLiteSpeed' => [
        ['lshttpd', 'postgresql'],
        ['openlitespeed' => '1.8.3-1+noble', 'postgresql' => '16+257build1', 'postgresql-14' => '14.19-0ubuntu0.22.04.1', 'postgresql-16' => '16.10-0ubuntu0.24.04.1'],
        // The server package, not the `postgresql` meta package's "16+257"; the highest wins.
        ['openlitespeed' => '1.8.3', 'postgresql' => '16.10'],
    ],
    'Node (MERN)' => [
        ['nginx', 'mongod', 'postfix'],
        ['nginx' => '1.24.0-2ubuntu7.5', 'mongodb-org-server' => '8.0.4', 'postfix' => '3.8.6-1build2'],
        ['nginx' => '1.24.0', 'mongodb' => '8.0.4', 'postfix' => '3.8.6'],
    ],
    'Docker' => [
        ['docker'],
        ['docker-ce' => '5:27.3.1-1~ubuntu.24.04~noble'],
        ['docker' => '27.3.1'],
    ],
]);

it('gives the full dpkg version too, for the tooltip', function () {
    svFakeServer(['nginx'], ['nginx' => '1.24.0-2ubuntu7.5']);

    $nginx = svServicesById($this->token)['nginx'];

    expect($nginx['version'])->toBe('1.24.0')
        ->and($nginx['package_version'])->toBe('1.24.0-2ubuntu7.5');
});

it('returns null when a running service has no package dpkg knows about', function () {
    // Installed some other way (a tarball, a source build): the unit exists,
    // the package does not. The row stays, without a version.
    svFakeServer(['nginx', 'redis-server'], ['nginx' => '1.24.0-2ubuntu7.5']);

    $rows = svServicesById($this->token);

    expect($rows['redis']['version'])->toBeNull()
        ->and($rows['redis']['package_version'])->toBeNull()
        ->and($rows['nginx']['version'])->toBe('1.24.0');
});

it('does not count a removed package that left its config behind', function () {
    svFakeServer(['redis-server'], []);
    Process::fake(function ($process) {
        $args = (array) $process->command;
        $args = ($args[0] ?? '') === 'sudo' ? array_slice($args, 2) : $args;

        if (($args[1] ?? null) === 'show') {
            return Process::result(output: systemctlShowOutput($args, ['redis-server' => ['load' => 'loaded', 'active' => 'active', 'file' => 'enabled']]));
        }

        return ($args[0] ?? null) === 'dpkg-query'
            ? Process::result(output: "rc \tredis-server\t5:7.0.15-1ubuntu0.24.04.1\n")
            : Process::result();
    });

    expect(svServicesById($this->token)['redis']['version'])->toBeNull();
});

it('asks dpkg once for the whole list, and again only when dpkg changes', function () {
    svFakeServer(['nginx', 'redis-server'], ['nginx' => '1.24.0-2ubuntu7.5', 'redis-server' => '5:7.0.15-1']);
    $status = config('server.dpkg_status');
    File::put($status, 'v1');
    touch($status, time() - 60);

    svServicesById($this->token);
    svServicesById($this->token);
    expect($this->dpkgCalls)->toBe(1);

    // An install or upgrade rewrites dpkg's status file.
    touch($status, time());
    svServicesById($this->token);
    expect($this->dpkgCalls)->toBe(2);
});

it('shortens Debian versions to what the upstream calls them', function (string $full, string $short) {
    expect(PackageVersions::short($full))->toBe($short);
})->with([
    ['1.24.0-2ubuntu7.5', '1.24.0'],
    ['1:10.11.14-0ubuntu0.24.04.1', '10.11.14'],
    ['5:27.3.1-1~ubuntu.24.04~noble', '27.3.1'],
    ['1:10.11.9+maria~ubu2404', '10.11.9'],
    ['8.4.13-1+ubuntu24.04.1+deb.sury.org+1', '8.4.13'],
    ['8.0.4', '8.0.4'],
    ['beta-build', 'beta-build'],
]);

it('keeps the version off a row still being installed', function () {
    svFakeServer([], []);
    RuntimeInstall::query()->create(['runtime' => 'database', 'version' => 'mariadb', 'status' => InstallStatus::Installing, 'started_at' => now()]);

    $row = svServicesById($this->token)['mariadb'] ?? null;

    expect($row)->not->toBeNull()
        ->and($row['version'])->toBeNull()
        ->and($row['package_version'])->toBeNull();
});
