<?php

use App\Models\Application;
use App\Models\SystemUser;
use App\Services\Server\Applications\Installers\CraftCmsInstaller;
use Illuminate\Support\Facades\Process;

/*
| Which PHP `composer create-project` runs under.
|
| 🔴 Composer is a PHAR whose shebang is `#!/usr/bin/env php`, so it starts
| under whatever `php` is first on PATH — the *server default* — no matter what
| version the site was created with. Composer then resolves the package against
| that interpreter, and for Craft it does not fail: it resolves backwards and
| installs Craft 4 on a site the panel reports as Craft 5, silently.
|
| Craft and Statamic are the only two installers built this way; the other nine
| name an interpreter explicitly via `phpCommand()` and were never exposed.
|
| The site is on 8.2 while the configured server default is 8.4 throughout —
| if those matched, none of these tests could tell the fix from its absence.
*/

beforeEach(function () {
    $this->home = sys_get_temp_dir().'/sv-oss-composer-'.getmypid();

    $systemUser = SystemUser::create([
        'username' => 'composeruser', 'home_path' => $this->home, 'shell' => '/bin/bash', 'sudo' => false,
    ]);

    $this->application = Application::forceCreate([
        'system_user_id' => $systemUser->id,
        'name' => 'Craft', 'slug' => 'craft',
        'domain' => 'craft.example.com',
        'site_type' => 'craftcms',
        'serving_profile' => 'php',
        'php_version' => '8.2',
        'web_root' => '/',
        'status' => 'active',
    ]);

    config([
        'server.php_shim_dir' => '/var/lib/panel/php-shims',
        'server.php_binary_pattern' => '/usr/bin/php{version}',
        // Deliberately not the site's version.
        'server.default_php_version' => '8.4',
    ]);
});

/** Every command the installer ran, in order, sudo prefix stripped. */
function composerRuns(): Closure
{
    $runs = new ArrayObject;

    Process::fake(function ($process) use ($runs) {
        $command = (array) $process->command;
        $args = ($command[0] ?? '') === 'sudo' ? array_slice($command, 2) : $command;
        $runs[] = implode(' ', $args);

        return Process::result(exitCode: 0);
    });

    return fn (): array => iterator_to_array($runs);
}

it('builds under the site\'s PHP, not the server default', function () {
    $runs = composerRuns();

    app(CraftCmsInstaller::class)->install($this->application, "{$this->home}/craft/public_html/web", [
        'db_host' => '127.0.0.1', 'db_port' => 3306, 'database' => 'craft',
        'db_user' => 'craft', 'db_password' => 'secret', 'engine' => 'mysql',
    ]);

    $create = collect($runs())->first(fn (string $c) => str_contains($c, 'create-project'));

    expect($create)->not->toBeNull()
        // The site's 8.2 shim, first on PATH, so composer's own shebang finds
        // it before /usr/bin/php.
        ->and($create)->toContain('env PATH=/var/lib/panel/php-shims/8.2:')
        // Named explicitly: the configured default must not be what composer
        // resolves against, and asserting only the presence of 8.2 would still
        // pass if both were on the path in the wrong order.
        ->and($create)->not->toContain('php-shims/8.4');
});

it('creates the shim before composer runs, not after', function () {
    $runs = composerRuns();

    app(CraftCmsInstaller::class)->install($this->application, "{$this->home}/craft/public_html/web", [
        'db_host' => '127.0.0.1', 'db_port' => 3306, 'database' => 'craft',
        'db_user' => 'craft', 'db_password' => 'secret', 'engine' => 'mysql',
    ]);

    $commands = collect($runs())->values();
    $link = $commands->search(fn (string $c) => str_starts_with($c, 'ln -sfn /usr/bin/php8.2'));
    $create = $commands->search(fn (string $c) => str_contains($c, 'create-project'));

    // Ordering, not just presence: a shim built afterwards satisfies a
    // "was it created" assertion and fixes nothing.
    expect($link)->not->toBeFalse()
        ->and($create)->not->toBeFalse()
        ->and($link)->toBeLessThan($create);
});

it('still installs when the shim cannot be built', function () {
    // Never fatal. A site whose chosen PHP has since been uninstalled must not
    // have its install die inside a helper, in a step nobody can map to
    // anything they did — it runs exactly as it did before shims existed.
    config(['server.php_shim_dir' => '']);

    $runs = composerRuns();

    app(CraftCmsInstaller::class)->install($this->application, "{$this->home}/craft/public_html/web", [
        'db_host' => '127.0.0.1', 'db_port' => 3306, 'database' => 'craft',
        'db_user' => 'craft', 'db_password' => 'secret', 'engine' => 'mysql',
    ]);

    $create = collect($runs())->first(fn (string $c) => str_contains($c, 'create-project'));

    expect($create)->not->toBeNull()
        ->and($create)->not->toContain('env PATH=');
});
