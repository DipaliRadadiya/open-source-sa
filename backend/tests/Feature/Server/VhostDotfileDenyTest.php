<?php

use App\Models\Application;
use App\Models\SystemUser;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Php\PoolManager;
use App\Services\Server\WebServers\WebServerManager;
use Illuminate\Support\Facades\Process;

/**
 * No vhost may serve the panel's own bookkeeping directory.
 *
 * `.panel/` holds the Basic-Auth credential file inside the served directory, so only the
 * vhost's dotfile deny keeps the password hash off the internet. nginx denies every dotfile;
 * OpenLiteSpeed cannot use a lookahead and denies an explicit list, which must include `.panel`.
 *
 * Apache needs two rules: `DirectoryMatch` only matches directories, so `.git/` is refused while
 * a `.env` beside it would be served as plain text. This rule is the last line of defence; the
 * environment file also stays above the document root where the layout allows.
 *
 * Rendered templates, not a live server: the question is what the config says.
 */
beforeEach(function () {
    $systemUser = SystemUser::create(['username' => 'siteowner', 'home_path' => '/home/siteowner']);

    $this->application = Application::forceCreate([
        'system_user_id' => $systemUser->id,
        'name' => 'Shop', 'slug' => 'shop', 'domain' => 'shop.example.com',
        'site_type' => 'wordpress', 'serving_profile' => 'php',
        'status' => 'active', 'web_root' => '/', 'php_version' => '8.4',
    ]);

    $this->application->load('systemUser');
});

/**
 * @return array<int, string>
 */
function vhostTemplates(): array
{
    return array_map(
        fn (string $path): string => str_replace(
            [base_path('resources/views/'), '.blade.php', '/'],
            ['', '', '.'],
            $path,
        ),
        array_filter(
            glob(base_path('resources/views/server/vhosts/*/*.blade.php')) ?: [],
            // Partials are rendered through the file that owns them.
            fn (string $path): bool => ! str_starts_with(basename($path), '_'),
        ),
    );
}

it('finds the templates it is meant to be checking', function () {
    // Without this, an empty glob makes every assertion below vacuous.
    expect(vhostTemplates())->not->toBeEmpty()
        ->and(implode(' ', vhostTemplates()))
        ->toContain('openlitespeed')
        ->toContain('nginx')
        ->toContain('apache');
});

it('denies the panel directory in every OpenLiteSpeed template that filters dotfiles', function () {
    $templates = array_filter(vhostTemplates(), fn (string $t): bool => str_contains($t, 'openlitespeed'));

    $withFilter = 0;

    foreach ($templates as $template) {
        $source = file_get_contents(
            base_path('resources/views/'.str_replace('.', '/', $template).'.blade.php')
        );

        if (! str_contains($source, 'context exp:/\\.')) {
            continue;
        }

        $withFilter++;

        // The credential file is `.panel/.htpasswd`, so the directory segment must be refused;
        // asserted on the deny context itself so a stray mention elsewhere cannot satisfy it.
        preg_match('/context exp:\/\\\\\.\(([^)]+)\)/', $source, $matches);

        expect($matches[1] ?? '')->toContain('panel');
    }

    expect($withFilter)->toBeGreaterThan(0);
});

it('denies dotfile files in Apache, not just dotfile directories', function () {
    // `<DirectoryMatch "/\.">` refuses `.git/` but says nothing about `.env`, which the panel
    // writes beside an application's code whenever the code root is served.
    $bodies = array_filter(
        glob(base_path('resources/views/server/vhosts/apache/*.blade.php')) ?: [],
        fn (string $path): bool => str_contains((string) file_get_contents($path), '<DirectoryMatch'),
    );

    expect($bodies)->not->toBeEmpty();

    foreach ($bodies as $path) {
        $source = (string) file_get_contents($path);

        expect($source)->toContain('<FilesMatch "^\.">');

        // Filenames only: an ACME challenge token is not a dotfile, so no lookahead is needed.
        expect($source)->not->toContain('<FilesMatch "^\.(?!well-known)">');
    }
});

it('keeps the ACME challenge path reachable while denying dotfiles', function () {
    // A rule that catches `/.well-known/acme-challenge/` stops every Let's Encrypt renewal.
    foreach (vhostTemplates() as $template) {
        $source = file_get_contents(
            base_path('resources/views/'.str_replace('.', '/', $template).'.blade.php')
        );

        if (str_contains($source, 'location ~ /\\.')) {
            expect($source)->toContain('(?!well-known)');
        }
    }
});

it('keeps everything the panel writes outside the served directory', function () {
    // The Basic Auth credential, PHP sessions, PHP error log, WAF detect log and pre-push
    // database dump: inside the document root each is one per-web-server deny rule from public.
    $documentRoot = app(ApplicationProvisioner::class)->documentRoot($this->application);

    expect($this->application->panelPath())->not->toStartWith($documentRoot)
        ->and($this->application->basicAuthPath())->not->toStartWith($documentRoot)
        // `conf/{web server}/`, a sibling of the served directory (the v7 layout).
        ->and($this->application->basicAuthPath())->toStartWith($this->application->rootPath().'/conf/')
        // Above the webroot, still inside the site's own directory.
        ->and($this->application->panelPath())->toStartWith($this->application->rootPath());
});

it('creates the bookkeeping directory before a config that names it goes live', function () {
    // nginx refuses to start when a log directory is missing, so a vhost naming the WAF detect
    // log must be preceded by the mkdir.
    $ran = [];

    Process::fake(function ($process) use (&$ran) {
        $ran[] = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        return Process::result(exitCode: 0);
    });

    app(WebServerManager::class)
        ->driver()
        ->apply($this->application, app(ApplicationProvisioner::class)->documentRoot($this->application));

    $mkdirs = collect($ran)->filter(fn (array $c): bool => ($c[0] ?? '') === 'mkdir')->flatten();

    expect($mkdirs)->toContain($this->application->panelPath());
});

it('keeps PHP sessions and the error log outside the served directory', function () {
    // Must NOT be under the document root: no deny rule should stand between a session file and
    // anyone who guesses its name.
    $documentRoot = app(ApplicationProvisioner::class)->documentRoot($this->application);
    $pools = app(PoolManager::class);

    expect($pools->sessionPath($this->application))->not->toStartWith($documentRoot)
        ->and($pools->errorLogPath($this->application))->not->toStartWith($documentRoot)
        // Both still inside the site's own directory, just above the webroot.
        ->and($pools->sessionPath($this->application))->toStartWith($this->application->rootPath())
        ->and($pools->errorLogPath($this->application))->toStartWith($this->application->rootPath());
});
