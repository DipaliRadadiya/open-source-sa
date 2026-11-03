<?php

use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Enums\DomainType;
use App\Models\Application;
use App\Models\Certificate;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Applications\SiteConfigResyncer;
use App\Services\Server\WebServers\NginxDriver;
use App\Services\Server\WebServers\VhostParser;
use Illuminate\Support\Facades\Process;

/*
 * While nginx is up and the app behind it is not — a restart, a crash, the
 * seconds after a reboot before it listens — a visitor got nginx's stock
 * "502 Bad Gateway". Measured on a MERN box after a reboot: ~18 s of timeouts
 * (the box is off; nothing covers that), then ~2 s of that plain 502. A
 * proxied site now answers those with a short "This site is restarting" page,
 * 503 with Retry-After, while an error the app itself sends passes through.
 */

beforeEach(function () {
    ServerCapability::create([
        'stack' => 'mern',
        'web_server' => 'nginx',
        'capabilities' => ['node' => true],
        'source' => 'installer',
        'verified_at' => now(),
    ]);

    $this->systemUser = SystemUser::create(['username' => 'siteowner', 'home_path' => '/home/siteowner']);
});

function restartingSite(string $profile, string $tls = 'none', bool $redirect = false): Application
{
    $attributes = [
        'node' => ['site_type' => 'git', 'serving_profile' => 'node', 'app_port' => 3300],
        'docker' => ['site_type' => 'docker', 'serving_profile' => 'docker', 'app_port' => 3300],
        'php' => ['site_type' => 'wordpress', 'serving_profile' => 'php', 'php_version' => '8.4'],
        'static' => ['site_type' => 'static', 'serving_profile' => 'static'],
    ][$profile];

    $site = Application::forceCreate($attributes + [
        'system_user_id' => test()->systemUser->id,
        'name' => $profile, 'slug' => $profile, 'domain' => 'app.example.com',
        'status' => 'active', 'web_root' => '/',
    ]);
    $site->domains()->create(['domain' => 'app.example.com', 'type' => DomainType::Primary]);

    if ($redirect) {
        $site->domains()->create([
            'domain' => 'www.app.example.com', 'type' => DomainType::Redirect, 'redirect_status' => 301,
        ]);
    }

    if ($tls !== 'none') {
        Certificate::create([
            'application_id' => $site->id, 'type' => CertificateType::LetsEncrypt, 'status' => CertificateStatus::Active,
            'domains' => ['app.example.com', 'www.app.example.com'],
            'certificate_path' => '/etc/letsencrypt/live/app/fullchain.pem',
            'private_key_path' => '/etc/letsencrypt/live/app/privkey.pem',
            'force_https' => $tls === 'forced',
            'issued_at' => now(), 'expires_at' => now()->addDays(89),
        ]);
    }

    return $site->fresh(['domains', 'certificate', 'systemUser']);
}

/** @return array<string, string> every file the site is written as, without the ones that must not exist */
function restartingFiles(Application $site): array
{
    return array_filter(
        app(NginxDriver::class)->configFiles($site, app(ApplicationProvisioner::class)->documentRoot($site)),
        fn (?string $contents) => $contents !== null,
    );
}

/** @return array<int, string> each top-level `server { … }` block of a rendered file */
function serverBlocks(string $config): array
{
    return array_values(array_filter(
        preg_split('/^(?=server \{$)/m', $config) ?: [],
        fn (string $part) => str_starts_with($part, 'server {'),
    ));
}

function restartingPageBody(string $block): string
{
    expect(preg_match_all("/^\\s*return 503 '(.*)';\$/m", $block, $matches))->toBe(1);

    return $matches[1][0];
}

it('answers nginx\'s own gateway errors with the restarting page in every proxied server block', function (string $profile, string $tls, bool $ipv6, bool $redirect) {
    if (! $ipv6) {
        config(['server.ipv6_probe_file' => sys_get_temp_dir().'/panel-test-no-if_inet6-'.bin2hex(random_bytes(4))]);
    }

    $files = restartingFiles(restartingSite($profile, $tls, $redirect));
    $proxied = 0;

    foreach ($files as $config) {
        expect($config)->not->toContain('proxy_intercept_errors');

        foreach (serverBlocks($config) as $block) {
            if (! str_contains($block, 'proxy_pass')) {
                // The HTTPS redirect and the redirect-domain blocks proxy nothing.
                expect($block)->not->toContain('@restarting');

                continue;
            }

            $proxied++;

            expect(substr_count($block, 'location @restarting {'))->toBe(1)
                ->and(substr_count($block, 'error_page 502 503 504 @restarting;'))->toBe(1)
                ->and($block)->toContain(
                    'internal;',
                    'default_type text/html;',
                    'add_header Retry-After 10 always;',
                    'add_header Cache-Control "no-store" always;',
                );

            $body = restartingPageBody($block);

            // `'` ends nginx's string, `$` starts a variable, `#` is a comment to
            // the panel's own vhost reader, and braces would read as a block.
            expect($body)->not->toContain("'", '$', '#', '{', '}', '\\')
                ->toContain(
                    '<title>This site is restarting</title>',
                    'This site is restarting. Please try again in a moment. If this keeps happening, the site owner should check the application&rsquo;s logs.',
                );
        }

        expect(substr_count($config, 'location @restarting {'))->toBe(substr_count($config, 'proxy_pass '));
    }

    // Forced HTTPS: the HTTPS file proxies, port 80 only redirects. Otherwise
    // every file that holds the site proxies.
    expect($proxied)->toBe($tls === 'none' ? 1 : ($tls === 'forced' ? 1 : 2));
})->with(['node', 'docker'])
    ->with(['http only' => 'none', 'https' => 'https', 'https forced' => 'forced'])
    ->with(['ipv6' => true, 'ipv4 only' => false])
    ->with(['own name' => false, 'with a redirect domain' => true]);

it('leaves the page on the proxy only, never on the rest of the server', function () {
    $config = implode("\n", restartingFiles(restartingSite('node')));

    // Inside `location /`, after proxy_pass: a 503 from anything else nginx
    // does for this site is not dressed as a restart.
    expect($config)->toMatch('/location \/ \{\n\s+proxy_pass [^}]*error_page 502 503 504 @restarting;\n\s+\}/');
});

it('still reads as one site to the panel\'s own vhost reader', function () {
    $config = implode("\n", restartingFiles(restartingSite('node', 'https')));
    $parser = app(VhostParser::class);

    expect($parser->parse($config))->toMatchArray(['domains' => ['app.example.com'], 'port' => 3300])
        ->and($parser->structure($config, 'nginx')['stray'])->toBe([]);
});

it('leaves the sites that proxy nothing alone', function (string $profile, string $tls) {
    foreach (restartingFiles(restartingSite($profile, $tls)) as $config) {
        expect($config)->not->toContain('@restarting', 'error_page 502', 'Retry-After 10', 'proxy_intercept_errors');
    }
})->with(['php', 'static'])->with(['none', 'https']);

it('gives an existing proxied site the page on sites:resync', function () {
    $site = restartingSite('node');
    // What the site was written with before: everything but the page.
    $onDisk = (string) preg_replace(
        '/\n\s*error_page 502 503 504 @restarting;|\n    location @restarting \{.*?\n    \}/s',
        '',
        implode("\n", restartingFiles($site)),
    );
    expect($onDisk)->not->toContain('@restarting');

    $written = [];
    Process::fake(function ($process) use ($onDisk, &$written) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        if (($args[0] ?? '') === 'cat') {
            return str_ends_with((string) end($args), '/node.conf')
                ? Process::result(output: $onDisk)
                : Process::result(exitCode: 1, errorOutput: 'No such file or directory');
        }
        if (($args[0] ?? '') === 'tee' && str_ends_with((string) end($args), '/node.conf')) {
            $written[] = (string) $process->input;
        }

        return Process::result();
    });

    $result = app(SiteConfigResyncer::class)->run();

    expect($result['updated'])->toBe(1)
        ->and($result['failed'])->toBe([])
        ->and($written)->toHaveCount(1)
        ->and($written[0])->toContain('error_page 502 503 504 @restarting;', 'location @restarting {');
});

it('puts an existing site back as it was when nginx refuses the new config', function () {
    restartingSite('node');
    $onDisk = "server {\n    listen 80;\n    server_name app.example.com;\n    location / { proxy_pass http://127.0.0.1:3300; }\n}\n";

    $written = [];
    Process::fake(function ($process) use ($onDisk, &$written) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        if (($args[0] ?? '') === 'cat') {
            return str_ends_with((string) end($args), '/node.conf')
                ? Process::result(output: $onDisk)
                : Process::result(exitCode: 1, errorOutput: 'No such file or directory');
        }
        if (($args[0] ?? '') === 'tee' && str_ends_with((string) end($args), '/node.conf')) {
            $written[] = (string) $process->input;
        }
        if ($args === ['nginx', '-t']) {
            return Process::result(exitCode: 1, errorOutput: 'nginx: [emerg] invalid number of arguments in "return"');
        }

        return Process::result();
    });

    $result = app(SiteConfigResyncer::class)->run();

    expect($result['updated'])->toBe(0)
        ->and($result['failed'])->toHaveCount(1)
        // Written, refused, then the old file back.
        ->and($written)->toHaveCount(2)
        ->and($written[0])->toContain('location @restarting {')
        ->and($written[1])->toBe($onDisk);
});
