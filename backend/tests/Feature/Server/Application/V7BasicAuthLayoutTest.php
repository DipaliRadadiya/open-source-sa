<?php

use App\Enums\DomainType;
use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Services\Server\Applications\BasicAuthManager;
use Illuminate\Support\Facades\Process;

/*
| v8 follows v7's file layout (step B4): the Basic Auth credential lives at
| `{site}/conf/{web server}/.htpasswd`, as v7 keeps it. On a server from v7
| `conf/` belongs to the site user, so root must not write through anything
| the user could have swapped for a link.
*/

beforeEach(function () {
    ServerCapability::create(['stack' => 'lemp', 'web_server' => 'nginx', 'capabilities' => ['php' => true], 'source' => 'installer', 'verified_at' => now()]);
    $this->su = SystemUser::create(['username' => 'v7demo', 'home_path' => '/home/v7demo', 'shell' => '/bin/bash']);
    $this->site = Application::forceCreate([
        'system_user_id' => $this->su->id, 'name' => 'phpsite', 'slug' => 'phpsite', 'domain' => 'phpsite.example.com',
        'site_type' => 'custom', 'serving_profile' => 'php', 'status' => 'active', 'web_root' => '/', 'php_version' => '8.2',
        'basic_auth_enabled' => true, 'basic_auth_username' => 'staff', 'basic_auth_password' => '$apr1$abc$def',
    ]);
    $this->site->domains()->create(['domain' => 'phpsite.example.com', 'type' => DomainType::Primary]);
});

/** @param  array<int, string>  $links  paths that are symlinks */
function htpasswdServer(array $links = []): ArrayObject
{
    $ran = new ArrayObject;

    Process::fake(function ($process) use ($ran, $links) {
        $c = ($process->command[0] ?? '') === 'sudo' ? array_slice($process->command, 2) : $process->command;
        $ran->append($c);

        if (($c[0] ?? '') === 'test' && ($c[1] ?? '') === '-L') {
            return Process::result(exitCode: in_array($c[2] ?? '', $links, true) ? 0 : 1);
        }

        return Process::result();
    });

    return $ran;
}

it('keeps the credential where v7 does', function () {
    expect($this->site->basicAuthPath())->toBe('/home/v7demo/phpsite/conf/nginx/.htpasswd');
});

it('makes conf/ and conf/nginx root\'s before writing, and the file root\'s 0644', function () {
    $ran = htpasswdServer();

    app(BasicAuthManager::class)->publish($this->site->fresh('systemUser'));

    $lines = collect($ran)->map(fn ($c) => implode(' ', $c))->values();
    $written = $lines->search('tee /home/v7demo/phpsite/conf/nginx/.htpasswd');

    foreach (['/home/v7demo/phpsite/conf', '/home/v7demo/phpsite/conf/nginx'] as $dir) {
        $owned = $lines->search("chown -h root:root {$dir}");

        expect($owned)->not->toBeFalse()->and($owned)->toBeLessThan($written);
    }

    expect($lines)->toContain('chown -h root:root /home/v7demo/phpsite/conf/nginx/.htpasswd')
        ->toContain('chmod 0644 /home/v7demo/phpsite/conf/nginx/.htpasswd')
        // The old location goes, after the new file is in place.
        ->and($lines->search('rm -f /home/v7demo/phpsite/.panel/.htpasswd'))->toBeGreaterThan($written);
});

it('removes a link planted in place of conf/ instead of writing through it', function (string $planted) {
    $ran = htpasswdServer([$planted]);

    app(BasicAuthManager::class)->publish($this->site->fresh('systemUser'));

    $lines = collect($ran)->map(fn ($c) => implode(' ', $c))->values();

    expect($lines->search("rm -f {$planted}"))->not->toBeFalse()
        ->and($lines->search("rm -f {$planted}"))->toBeLessThan($lines->search('tee /home/v7demo/phpsite/conf/nginx/.htpasswd'));
})->with(['/home/v7demo/phpsite/conf', '/home/v7demo/phpsite/conf/nginx']);
