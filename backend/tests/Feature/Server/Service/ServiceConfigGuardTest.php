<?php

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/*
 * Bug #11: restarting nginx over a broken config took nginx down — and with it
 * every site and the panel itself, which is served by the same nginx. A
 * restart, start or reload now runs the service's own config test first.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->token = User::factory()->admin()->create()->createToken('t')->plainTextToken;

    $this->phpDir = sys_get_temp_dir().'/sv-oss-svc-guard-'.getmypid();
    File::deleteDirectory($this->phpDir);
    File::makeDirectory("{$this->phpDir}/8.4/fpm", 0755, true);
    config(['server.php_dir' => $this->phpDir]);
});

afterEach(function () {
    File::deleteDirectory($this->phpDir);
});

/**
 * Every unit installed and running; `$answers` overrides single commands.
 *
 * @param  array<string, ProcessResult>  $answers  keyed by the joined command
 */
function fakeGuardedServices(array $answers = []): void
{
    $ran = test()->ran = new ArrayObject;

    Process::fake(function ($process) use ($answers, $ran) {
        $command = (array) $process->command;
        $ran[] = implode(' ', $command);

        if (($command[0] ?? '') === 'systemctl' && ($command[1] ?? '') === 'show') {
            return Process::result(output: systemctlShowOutput($command, default: ['load' => 'loaded', 'active' => 'active', 'file' => 'enabled', 'reload' => true]));
        }

        return $answers[implode(' ', $command)] ?? Process::result(exitCode: 0);
    });
}

function guardHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->token];
}

it('refuses to restart nginx when its configuration test fails', function (string $action) {
    fakeGuardedServices([
        'nginx -t' => Process::result(errorOutput: 'nginx: [emerg] unknown directive "foo" in /etc/nginx/x.conf:3', exitCode: 1),
    ]);

    $response = $this->withHeaders(guardHeaders())->putJson('/api/services/nginx', ['action' => $action]);

    $response->assertStatus(422)
        ->assertJsonPath('config_test.ok', false)
        ->assertJsonValidationErrors('action');
    expect($response->json('config_test.output'))->toContain('unknown directive');

    Process::assertNotRan(fn ($p) => $p->command === ['systemctl', $action, 'nginx']);
})->with(['restart', 'reload']);

it('restarts nginx after its configuration test passes', function () {
    fakeGuardedServices();

    $this->withHeaders(guardHeaders())->putJson('/api/services/nginx', ['action' => 'restart'])->assertOk();

    $ran = collect($this->ran->getArrayCopy());
    $test = $ran->search('nginx -t');
    $restart = $ran->search('systemctl restart nginx');

    expect($test)->not->toBeFalse()
        ->and($restart)->not->toBeFalse()
        ->and($test)->toBeLessThan($restart);
});

it('refuses to restart PHP-FPM over an ini PHP could not parse', function () {
    // php-fpm -t exits 0 here and ignores every line after the error.
    fakeGuardedServices([
        '/usr/sbin/php-fpm8.4 -t' => Process::result(
            errorOutput: "PHP:  syntax error, unexpected end of file in /etc/php/8.4/fpm/php.ini on line 4\n",
        ),
    ]);

    $this->withHeaders(guardHeaders())->putJson('/api/services/php8.4-fpm', ['action' => 'restart'])
        ->assertStatus(422);

    Process::assertNotRan(fn ($p) => $p->command === ['systemctl', 'restart', 'php8.4-fpm']);
});

it('does not hold back a service that has no configuration test', function () {
    fakeGuardedServices();

    $this->withHeaders(guardHeaders())->putJson('/api/services/redis', ['action' => 'restart'])->assertOk();

    Process::assertRan(fn ($p) => $p->command === ['systemctl', 'restart', 'redis-server']);
});

it('does not test before a stop', function () {
    fakeGuardedServices([
        'nginx -t' => Process::result(exitCode: 1, errorOutput: 'broken'),
    ]);

    // MariaDB: stoppable, unlike the panel's own web server.
    $this->withHeaders(guardHeaders())->putJson('/api/services/mariadb', ['action' => 'stop'])->assertOk();

    Process::assertNotRan(fn ($p) => $p->command === ['nginx', '-t']);
});

it('tests OpenLiteSpeed through the check that can actually fail', function () {
    // The raw `openlitespeed -t` exits 0 on any config when /tmp/lshttpd is
    // missing, and 1 for a mere warning. Only OlsConfigCheck reads both right.
    fakeGuardedServices([
        '/usr/local/lsws/bin/openlitespeed -t' => Process::result(exitCode: 1),
    ]);

    $this->withHeaders(guardHeaders())->putJson('/api/services/openlitespeed', ['action' => 'restart'])->assertOk();

    Process::assertRan(fn ($p) => $p->command === ['mkdir', '-p', '/tmp/lshttpd']);
    Process::assertRan(fn ($p) => $p->command === ['systemctl', 'restart', 'lshttpd']);
});

it('refuses an OpenLiteSpeed restart when the check reports an error', function () {
    fakeGuardedServices([
        '/usr/local/lsws/bin/openlitespeed -t' => Process::result(exitCode: 2, errorOutput: '[ERROR] bad vhost'),
    ]);

    $this->withHeaders(guardHeaders())->putJson('/api/services/openlitespeed', ['action' => 'restart'])->assertStatus(422);

    Process::assertNotRan(fn ($p) => $p->command === ['systemctl', 'restart', 'lshttpd']);
});
