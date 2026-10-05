<?php

use App\Models\User;
use App\Services\Server\Php\LegacyPhpTimezone;
use App\Services\Server\Runtimes\PhpRuntime;
use App\Support\ServerTimezone;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/*
| Junior re-test #14: after the server timezone was set to Asia/Kolkata, every
| site on PHP 5.6–7.4 answered 500 ("Timezone database is corrupt"). Measured
| on the nginx test server: those versions die guessing any zone but UTC, and
| work with `date.timezone` set explicitly. 8.x guesses correctly.
*/

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();

    $this->dir = sys_get_temp_dir().'/sv-oss-legacytz-'.uniqid();

    foreach (['5.6', '7.4', '8.4'] as $version) {
        foreach (['cli', 'fpm'] as $sapi) {
            File::ensureDirectoryExists("{$this->dir}/{$version}/{$sapi}/conf.d");
        }
        File::put("{$this->dir}/php-fpm{$version}", '');
    }

    config([
        'server.php_dir' => $this->dir,
        'server.php_fpm_binary_pattern' => $this->dir.'/php-fpm{version}',
        'server.hosts_file' => $this->dir.'/hosts',
    ]);

    ServerTimezone::forget();
});

afterEach(function () {
    File::deleteDirectory($this->dir);
    ServerTimezone::forget();
});

/** Every command, with what was piped into it. */
function legacyTzServer(): ArrayObject
{
    $runs = new ArrayObject;

    Process::fake(function ($process) use ($runs) {
        $command = ($process->command[0] ?? '') === 'sudo' ? array_slice($process->command, 2) : $process->command;
        $runs[] = ['command' => $command, 'input' => $process->input];

        return Process::result();
    });

    return $runs;
}

function legacyTzWrites(ArrayObject $runs): array
{
    return collect($runs)
        ->filter(fn (array $run) => ($run['command'][0] ?? '') === 'tee' && str_ends_with((string) end($run['command']), LegacyPhpTimezone::FILE))
        ->mapWithKeys(fn (array $run) => [str_replace(test()->dir.'/', '', (string) end($run['command'])) => $run['input']])
        ->all();
}

it('tells PHP 5.6 and 7.4 the new zone when the server timezone changes, and leaves 8.x alone', function () {
    $runs = legacyTzServer();

    $this->actingAs($this->admin)
        ->putJson('/api/settings/general', ['timezone' => 'Asia/Kolkata', 'hostname' => 'web-01', 'ntp' => true])
        ->assertOk();

    $expected = LegacyPhpTimezone::contents('Asia/Kolkata');

    expect(legacyTzWrites($runs))->toBe([
        '7.4/cli/conf.d/'.LegacyPhpTimezone::FILE => $expected,
        '7.4/fpm/conf.d/'.LegacyPhpTimezone::FILE => $expected,
        '5.6/cli/conf.d/'.LegacyPhpTimezone::FILE => $expected,
        '5.6/fpm/conf.d/'.LegacyPhpTimezone::FILE => $expected,
    ])
        // Reloaded, so running workers stop guessing; 8.4 untouched.
        ->and(collect($runs)->pluck('command')->all())->toContain(['systemctl', 'reload', 'php5.6-fpm'], ['systemctl', 'reload', 'php7.4-fpm'])
        ->and(collect($runs)->pluck('command')->contains(['systemctl', 'reload', 'php8.4-fpm']))->toBeFalse();
});

it('writes nothing when the timezone did not change', function () {
    $runs = new ArrayObject;

    // Only the hostname changes here.
    Process::fake(function ($process) use ($runs) {
        $command = ($process->command[0] ?? '') === 'sudo' ? array_slice($process->command, 2) : $process->command;
        $runs[] = ['command' => $command, 'input' => $process->input];

        return ($command[0] ?? '') === 'timedatectl' && in_array('--property=Timezone', $command, true)
            ? Process::result(output: "Asia/Kolkata\n")
            : Process::result();
    });

    $this->actingAs($this->admin)
        ->putJson('/api/settings/general', ['timezone' => 'Asia/Kolkata', 'hostname' => 'web-02', 'ntp' => true])
        ->assertOk();

    expect(legacyTzWrites($runs))->toBe([]);
});

it('gives a newly installed PHP 7.4 the server zone from the start', function () {
    $runs = legacyTzServer();
    File::put($this->dir.'/timezone', "Europe/Berlin\n");
    config(['server.timezone_file' => $this->dir.'/timezone']);

    app(PhpRuntime::class)->install('7.4');

    expect(legacyTzWrites($runs))->toHaveKey('7.4/fpm/conf.d/'.LegacyPhpTimezone::FILE)
        ->and(legacyTzWrites($runs)['7.4/fpm/conf.d/'.LegacyPhpTimezone::FILE])->toBe(LegacyPhpTimezone::contents('Europe/Berlin'));
});

it('does not write the file for PHP 8 installs', function () {
    $runs = legacyTzServer();

    app(PhpRuntime::class)->install('8.4');

    expect(legacyTzWrites($runs))->toBe([]);
});

it('refuses to write anything that is not a zone name', function (string $zone) {
    $runs = legacyTzServer();

    expect(app(LegacyPhpTimezone::class)->apply('7.4', $zone))->toBeFalse()
        ->and(legacyTzWrites($runs))->toBe([]);
})->with(["Asia/Kolkata\nextension=/tmp/x.so", '../../etc', '']);
