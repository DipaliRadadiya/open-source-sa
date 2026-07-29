<?php

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;

    // A fake /etc/php with two installed versions, so tests never depend on
    // what this machine happens to have.
    $this->phpDir = sys_get_temp_dir().'/sv-oss-php-'.getmypid();
    File::deleteDirectory($this->phpDir);
    foreach (['8.3', '8.4'] as $version) {
        File::makeDirectory("{$this->phpDir}/{$version}/fpm", 0755, true);
        File::put("{$this->phpDir}/{$version}/fpm/php.ini", "memory_limit = 128M\n");
    }

    config([
        'server.php_dir' => $this->phpDir,
        'server.php_fpm_binary' => '/usr/sbin/php-fpm',
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->phpDir);
});

function phpHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->token];
}

it('lists the installed php versions, newest first', function () {
    $response = $this->withHeaders(phpHeaders())->getJson('/api/php-versions');

    $response->assertOk();
    expect(collect($response->json('php_versions'))->pluck('version')->all())->toBe(['8.4', '8.3']);
    $response->assertJsonPath('php_versions.0.service', 'php8.4-fpm');
});

it('returns the raw ini for the editor to load', function () {
    Process::fake(['*' => Process::result(output: "memory_limit = 128M\n")]);

    $response = $this->withHeaders(phpHeaders())->getJson('/api/php-versions/8.4/ini');

    $response->assertOk();
    $response->assertJsonPath('php_ini.version', '8.4');
    expect($response->json('php_ini.contents'))->toContain('memory_limit');
    Process::assertRan(fn ($p) => $p->command === ['cat', "{$this->phpDir}/8.4/fpm/php.ini"]);
});

it('refuses a version that is not installed', function () {
    $this->withHeaders(phpHeaders())->getJson('/api/php-versions/5.6/ini')->assertNotFound();

    // A path is never built from an unchecked client string.
    $this->withHeaders(phpHeaders())->getJson('/api/php-versions/..%2F..%2Fetc/ini')->assertNotFound();
});

it('backs up, writes, tests and reloads on a valid ini', function () {
    Process::fake(['*' => Process::result(exitCode: 0)]);

    $this->withHeaders(phpHeaders())->putJson('/api/php-versions/8.4/ini', [
        'contents' => "memory_limit = 512M\n",
        'acknowledged' => true,
    ])->assertOk();

    $ini = "{$this->phpDir}/8.4/fpm/php.ini";

    Process::assertRan(fn ($p) => $p->command === ['cp', '-f', $ini, $ini.'.panel-bak']);
    Process::assertRan(fn ($p) => $p->command === ['tee', $ini] && str_contains($p->input, '512M'));
    Process::assertRan(fn ($p) => $p->command === ['/usr/sbin/php-fpm8.4', '-t']);
    Process::assertRan(fn ($p) => $p->command === ['systemctl', 'reload', 'php8.4-fpm']);
});

it('restores the previous ini and does not reload when php rejects it', function () {
    Process::fake(fn ($process) => in_array('-t', $process->command, true)
        ? Process::result(errorOutput: 'Failed to parse', exitCode: 1)
        : Process::result(exitCode: 0));

    $response = $this->withHeaders(phpHeaders())->putJson('/api/php-versions/8.4/ini', [
        'contents' => "this is not valid\n",
        'acknowledged' => true,
    ]);

    $response->assertStatus(422);

    $ini = "{$this->phpDir}/8.4/fpm/php.ini";

    // A broken ini can stop FPM starting at all — the working file goes back
    // and nothing is reloaded.
    Process::assertRan(fn ($p) => $p->command === ['cp', '-f', $ini.'.panel-bak', $ini]);
    Process::assertNotRan(fn ($p) => $p->command[0] === 'systemctl');
});

it('requires the impact to be acknowledged', function () {
    Process::fake();

    $this->withHeaders(phpHeaders())->putJson('/api/php-versions/8.4/ini', [
        'contents' => "memory_limit = 512M\n",
    ])->assertStatus(422)->assertJsonValidationErrors('acknowledged');

    // Nothing was touched.
    Process::assertNothingRan();
});

it('only reloads the version that was edited', function () {
    Process::fake(['*' => Process::result(exitCode: 0)]);

    $this->withHeaders(phpHeaders())->putJson('/api/php-versions/8.3/ini', [
        'contents' => "memory_limit = 256M\n",
        'acknowledged' => true,
    ])->assertOk();

    Process::assertRan(fn ($p) => $p->command === ['systemctl', 'reload', 'php8.3-fpm']);
    Process::assertNotRan(fn ($p) => $p->command === ['systemctl', 'reload', 'php8.4-fpm']);
});

it('denies editing with view-only access', function () {
    Process::fake();
    $user = User::factory()->create();
    grantPermission($user, 'service', view: true, manage: false);
    $token = $user->createToken('t')->plainTextToken;

    // Reading is allowed...
    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/php-versions')->assertOk();

    // ...editing is not.
    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson('/api/php-versions/8.4/ini', ['contents' => 'x', 'acknowledged' => true])
        ->assertForbidden();
});
