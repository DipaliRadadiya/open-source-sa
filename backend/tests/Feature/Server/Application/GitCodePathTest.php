<?php

use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Services\Server\Applications\ApplicationEnvironment;
use App\Services\Server\Applications\GitDeployer;
use Illuminate\Support\Facades\Process;

/**
 * Where a git checkout lands, and where its `.env` goes.
 *
 * A checkout always arrives at `public_html`; `web_root` selects what the web
 * server serves *inside* it. Those are two questions and the code answered
 * them with one value, so a repository whose front controller lives in
 * `public/` — every Laravel application — had no working configuration:
 *
 *  - `web_root` empty: served directory has no index, and the whole source
 *    tree including `.env` is published.
 *  - `web_root` `/public`: the checkout moves down too, so the application's
 *    own `public/` lands one level deeper than the served directory, which now
 *    contains `artisan` and `app/`.
 *
 * Both 403. Confirmed against the real path arithmetic before this was
 * written, not inferred.
 */
beforeEach(function () {
    ServerCapability::query()->delete();
    ServerCapability::create([
        'stack' => 'lemp', 'web_server' => 'nginx',
        'capabilities' => ['php' => true, 'node' => true],
        'source' => 'installer', 'verified_at' => now(),
    ]);

    $this->owner = SystemUser::create([
        'username' => 'deployer', 'home_path' => '/home/deployer',
        'shell' => '/bin/bash', 'sudo' => false,
    ]);
});

function gitSite(string $webRoot): Application
{
    return Application::forceCreate([
        'system_user_id' => test()->owner->id,
        'name' => 'Laravel App '.$webRoot, 'slug' => 'lara'.md5($webRoot),
        'domain' => 'lara'.md5($webRoot).'.test',
        'site_type' => 'git', 'serving_profile' => 'php', 'php_version' => '8.4',
        'status' => 'active', 'web_root' => $webRoot,
        'repository_url' => 'https://github.com/laravel/laravel.git', 'branch' => 'master',
    ]);
}

it('lands the checkout at public_html whatever the web root says', function () {
    // The heart of it. Both sites clone to the same place; only what nginx
    // serves differs.
    $root = gitSite('/');
    $public = gitSite('/public');

    expect($root->codePath())->toBe('/home/deployer/'.$root->slug.'/public_html')
        ->and($public->codePath())->toBe('/home/deployer/'.$public->slug.'/public_html');
});

it('serves inside the checkout, not instead of it', function () {
    $site = gitSite('/public');

    // The Laravel layout, finally expressible: code at public_html, nginx
    // pointed at the application's own public/ directory inside it.
    expect($site->codePath())->toBe('/home/deployer/'.$site->slug.'/public_html')
        ->and($site->documentRoot())->toBe('/home/deployer/'.$site->slug.'/public_html/public')
        ->and($site->documentRoot())->toStartWith($site->codePath().'/');
});

it('puts .env where the application reads it, still out of reach over HTTP', function () {
    $site = gitSite('/public');

    $env = app(ApplicationEnvironment::class)->path($site);

    // Laravel loads `.env` from its base directory — the checkout root.
    expect($env)->toBe($site->codePath().'/.env')
        // And that is above the served directory, so it is not a URL.
        ->and($env)->not->toStartWith($site->documentRoot());
});

it('deploys into the code root, not the document root', function () {
    // The bug was one argument at one call site: passing the document root
    // made the checkout follow the web root down.
    $site = gitSite('/public');
    $commands = collect();

    Process::fake(function ($process) use ($commands) {
        $commands->push(implode(' ', (array) $process->command));

        return str_contains(implode(' ', (array) $process->command), 'rev-parse')
            ? Process::result(output: "abc123\n")
            : Process::result(exitCode: 0);
    });

    // The later verify step needs a live site, which a fake cannot give it.
    // Irrelevant here: `git init` is recorded long before, and it is the whole
    // question — one argument at one call site.
    try {
        app(GitDeployer::class)->deploy($site, $site->codePath());
    } catch (Throwable) {
        // expected
    }

    $init = $commands->first(fn (string $c) => str_contains($c, 'git') && str_contains($c, 'init'));

    expect($init)->toContain($site->codePath())
        ->and($init)->not->toContain('/public_html/public');
});

it('leaves a site with no web root exactly where it was', function () {
    // The migration argument: `public_html` *is* the document root for these,
    // so nothing about them moves. Only sites with a non-root web root change,
    // and those cannot currently work at all.
    $site = gitSite('/');

    expect($site->codePath())->toBe($site->documentRoot());
});

it('still anchors a marketplace application to its served directory', function () {
    // WordPress unpacks *into* what is served, so its code root and document
    // root coincide even with a web root set — the git rule must not leak
    // into types whose installer works differently.
    $wp = Application::forceCreate([
        'system_user_id' => $this->owner->id,
        'name' => 'Blog', 'slug' => 'blog', 'domain' => 'blog.test',
        'site_type' => 'wordpress', 'serving_profile' => 'php', 'php_version' => '8.4',
        'status' => 'active', 'web_root' => '/blog',
    ]);

    expect($wp->codePath())->toBe($wp->documentRoot())
        ->and($wp->codePath())->toBe('/home/deployer/blog/public_html/blog');
});

it('moves an existing .env beside the code, once', function () {
    // Sites deployed before this have `.env` at the app root. Without the
    // move the panel would open a different, empty file and offer to create
    // one — stranding the real settings a directory up.
    $site = gitSite('/public');
    $commands = collect();

    Process::fake(function ($process) use ($site, $commands) {
        $command = implode(' ', (array) $process->command);
        $commands->push($command);

        // The old file exists; the new one does not.
        if (str_contains($command, 'test -f') && str_contains($command, $site->rootPath().'/.env')) {
            return Process::result(exitCode: 0);
        }

        return Process::result(exitCode: str_contains($command, 'test -f') ? 1 : 0);
    });

    app(ApplicationEnvironment::class)->exists($site);

    expect($commands->contains(fn (string $c) => str_contains($c, 'mv ')
        && str_contains($c, $site->rootPath().'/.env')
        && str_contains($c, $site->codePath().'/.env')))->toBeTrue();
});
