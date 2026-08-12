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
 * `.panel/` holds the Basic-Auth credential file, and it lives *inside* the
 * served directory — so the only thing keeping the password hash off the
 * public internet is the vhost denying dotfile paths. nginx and Apache deny
 * every dotfile; OpenLiteSpeed cannot use a lookahead and denies an explicit
 * list, which named `.git`, `.svn`, `.hg`, `.bzr` and `.env` but not
 * `.panel` — so on OLS the file was downloadable.
 *
 * Rendered templates rather than a live server, because the question is what
 * the config says, and that is answerable without the daemon.
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
            // Partials are included by the file that owns them; rendering one
            // alone proves nothing about the config that ships.
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

        if (! str_contains($source, 'context exp:^/\\.')) {
            continue;
        }

        $withFilter++;

        // The credential file is at `.panel/.htpasswd`, so the directory
        // segment is what has to be refused. Asserted on the deny context
        // itself, not the file, so a stray mention of the word elsewhere
        // cannot satisfy it.
        preg_match('/context exp:\^\/\\\\\.\(([^)]+)\)/', $source, $matches);

        expect($matches[1] ?? '')->toContain('panel');
    }

    expect($withFilter)->toBeGreaterThan(0);
});

it('keeps the ACME challenge path reachable while denying dotfiles', function () {
    // The deny rule and certificate issuance both live in dotfile territory.
    // A rule that catches `/.well-known/acme-challenge/` stops every
    // Let's Encrypt renewal on the server.
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
    // One rule, all of it: the Basic Auth credential, PHP sessions, the PHP
    // error log, the WAF detect log and the pre-push database dump. Inside
    // the document root each of these is one vhost deny rule away from being
    // downloadable, and that rule is per-web-server — OpenLiteSpeed's did not
    // cover `.panel` at all.
    $documentRoot = app(ApplicationProvisioner::class)->documentRoot($this->application);

    expect($this->application->panelPath())->not->toStartWith($documentRoot)
        ->and($this->application->basicAuthPath())->not->toStartWith($documentRoot)
        ->and($this->application->basicAuthPath())->toStartWith($this->application->panelPath())
        // Still the site's own directory — above the webroot, not outside
        // the site.
        ->and($this->application->panelPath())->toStartWith($this->application->rootPath());
});

it('creates the bookkeeping directory before a config that names it goes live', function () {
    // nginx refuses to start when a log directory is missing, so a vhost
    // naming the WAF detect log has to be preceded by the mkdir. Nothing
    // created this at provision time, which made WAF detect mode unturn-on-able
    // on a site whose web root had never been moved.
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
    // The other half of the same problem: these must NOT be under the
    // document root, because no deny rule is what stands between a session
    // file and anyone who guesses its name.
    $documentRoot = app(ApplicationProvisioner::class)->documentRoot($this->application);
    $pools = app(PoolManager::class);

    expect($pools->sessionPath($this->application))->not->toStartWith($documentRoot)
        ->and($pools->errorLogPath($this->application))->not->toStartWith($documentRoot)
        // Both still inside the site's own directory, just above the webroot.
        ->and($pools->sessionPath($this->application))->toStartWith($this->application->rootPath())
        ->and($pools->errorLogPath($this->application))->toStartWith($this->application->rootPath());
});
