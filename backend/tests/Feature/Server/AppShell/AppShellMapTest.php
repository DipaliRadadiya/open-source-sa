<?php

use App\Models\Application;
use App\Models\SystemUser;
use App\Services\Server\AppShell\AppShellMap;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

/**
 * The container login map (RC-28): the root-only file that says which
 * containers each system user may log in to. Built from the database and each
 * app's stored compose, rebuilt whenever an app or its owner changes, and
 * written through a temporary file and a rename.
 */
beforeEach(function () {
    config()->set('server.app_shell.sync', true);

    $this->writes = [];
    $this->ran = [];

    Process::fake(function ($process) {
        $args = $process->command;
        $this->ran[] = $args;

        if (($args[0] ?? null) === 'tee' && str_ends_with((string) ($args[1] ?? ''), 'map.json.new')) {
            $this->writes[] = json_decode((string) $process->input, true);
        }

        return Process::result(exitCode: 0);
    });

    $this->owner = SystemUser::create(['username' => 'blogger', 'home_path' => '/home/blogger', 'ssh_access' => false]);
    $this->other = SystemUser::create(['username' => 'adarsh-oc', 'home_path' => '/home/adarsh-oc', 'ssh_access' => false]);
});

function shellLastMap(): array
{
    return test()->writes[array_key_last(test()->writes)] ?? [];
}

function shellDockerApp(SystemUser $owner, array $attributes): Application
{
    return Application::forceCreate([
        'system_user_id' => $owner->id,
        'web_root' => 'public_html', 'serving_profile' => 'docker', 'status' => 'active',
        ...$attributes,
    ]);
}

function shellGhostApp(SystemUser $owner): Application
{
    return shellDockerApp($owner, [
        'name' => 'Ghost', 'slug' => 'ghost', 'domain' => 'ghost.test', 'site_type' => 'ghost',
        'app_port' => 20002, 'container_port' => 2368,
        'compose' => <<<'YAML'
            services:
              db:
                image: mysql:8.0
              ghost:
                image: ghost:5-alpine
                ports:
                  - "127.0.0.1:20002:2368"
            YAML,
    ]);
}

it('maps a recipe app, an image app and a pasted compose file', function () {
    $ghost = shellGhostApp($this->owner);

    // Container tab, Simple mode: no stored file, the panel renders one.
    $image = shellDockerApp($this->owner, [
        'name' => 'Tools', 'slug' => 'tools', 'domain' => 'tools.test', 'site_type' => 'docker',
        'image' => 'nginx:1.27-alpine', 'container_port' => 80, 'app_port' => 20003,
    ]);

    // Container tab, pasted compose with its own container names.
    $pasted = shellDockerApp($this->other, [
        'name' => 'Pasted', 'slug' => 'pasted', 'domain' => 'pasted.test', 'site_type' => 'docker',
        'app_port' => 20004, 'container_port' => 80,
        'compose' => <<<'YAML'
            services:
              web:
                image: nginx:1.27-alpine
                container_name: pasted-web
                ports: ["20004:80"]
              worker:
                image: busybox
                container_name: pasted-worker
            YAML,
    ]);

    // Not a Docker app: never in the map.
    Application::forceCreate([
        'system_user_id' => $this->owner->id, 'name' => 'Blog', 'slug' => 'blog', 'domain' => 'blog.test',
        'site_type' => 'wordpress', 'serving_profile' => 'php', 'web_root' => 'public_html', 'status' => 'active',
    ]);

    expect(app(AppShellMap::class)->entries())->toBe([
        ['app' => 'ghost', 'id' => $ghost->id, 'owner' => 'blogger', 'project' => "sv-app-{$ghost->id}", 'containers' => [
            ['service' => 'ghost', 'name' => "sv-app-{$ghost->id}-ghost-1", 'main' => true],
            ['service' => 'db', 'name' => "sv-app-{$ghost->id}-db-1", 'main' => false],
        ]],
        ['app' => 'tools', 'id' => $image->id, 'owner' => 'blogger', 'project' => "sv-app-{$image->id}", 'containers' => [
            ['service' => 'app', 'name' => "sv-app-{$image->id}-app-1", 'main' => true],
        ]],
        ['app' => 'pasted', 'id' => $pasted->id, 'owner' => 'adarsh-oc', 'project' => "sv-app-{$pasted->id}", 'containers' => [
            ['service' => 'web', 'name' => 'pasted-web', 'main' => true],
            ['service' => 'worker', 'name' => 'pasted-worker', 'main' => false],
        ]],
    ]);
});

it('makes the first service the main one when nothing publishes the app port', function () {
    $app = shellDockerApp($this->owner, [
        'name' => 'Odd', 'slug' => 'odd', 'domain' => 'odd.test', 'site_type' => 'docker', 'app_port' => 20009,
        'compose' => "services:\n  one:\n    image: busybox\n  two:\n    image: busybox\n",
    ]);

    expect(app(AppShellMap::class)->entries()[0]['containers'])->toBe([
        ['service' => 'one', 'name' => "sv-app-{$app->id}-one-1", 'main' => true],
        ['service' => 'two', 'name' => "sv-app-{$app->id}-two-1", 'main' => false],
    ]);
});

it('names an app by its slug, or app-<id> when the slug is too long to type', function () {
    $short = shellGhostApp($this->owner);
    $long = shellDockerApp($this->owner, [
        'name' => 'Long', 'slug' => str_repeat('a', 41), 'domain' => 'long.test', 'site_type' => 'docker',
        'image' => 'nginx:1.27-alpine', 'container_port' => 80, 'app_port' => 20010,
    ]);

    expect(app(AppShellMap::class)->names())->toBe([$short->id => 'ghost', $long->id => 'app-'.$long->id]);
});

it('writes it root-only through a temporary file and a rename', function () {
    shellGhostApp($this->owner);
    $this->ran = [];

    expect(app(AppShellMap::class)->write(force: true))->toBeTrue()
        ->and($this->ran)->toBe([
            ['install', '-d', '-m', '0700', '-o', 'root', '-g', 'root', '/etc/panel-app-shell'],
            ['tee', '/etc/panel-app-shell/map.json.new'],
            ['chmod', '0600', '/etc/panel-app-shell/map.json.new'],
            ['mv', '-f', '/etc/panel-app-shell/map.json.new', '/etc/panel-app-shell/map.json'],
        ]);
});

it('does not rewrite a map that has not changed', function () {
    $ghost = shellGhostApp($this->owner);
    $count = count($this->writes);

    // A status save during provisioning changes nothing the map says.
    $ghost->forceFill(['status' => 'provisioning'])->save();
    app(AppShellMap::class)->refresh();

    expect($this->writes)->toHaveCount($count);
});

it('follows a new app, an owner change, a clone, a rename and a delete', function () {
    $ghost = shellGhostApp($this->owner);
    expect(collect(shellLastMap()['apps'])->pluck('owner', 'app')->all())->toBe(['ghost' => 'blogger']);

    $ghost->forceFill(['system_user_id' => $this->other->id])->save();
    expect(collect(shellLastMap()['apps'])->pluck('owner', 'app')->all())->toBe(['ghost' => 'adarsh-oc']);

    // A clone or staging copy is a new Application row like any other.
    $clone = shellDockerApp($this->other, [
        'name' => 'Ghost staging', 'slug' => 'ghost-staging', 'domain' => 'staging.ghost.test', 'site_type' => 'ghost',
        'cloned_from_application_id' => $ghost->id, 'app_port' => 20005, 'container_port' => 2368,
        'compose' => "services:\n  ghost:\n    image: ghost:5-alpine\n    ports:\n      - \"127.0.0.1:20005:2368\"\n",
    ]);
    expect(collect(shellLastMap()['apps'])->pluck('app')->all())->toBe(['ghost', 'ghost-staging']);

    $this->other->update(['username' => 'renamed']);
    expect(collect(shellLastMap()['apps'])->pluck('owner')->unique()->values()->all())->toBe(['renamed']);

    $clone->delete();
    expect(collect(shellLastMap()['apps'])->pluck('app')->all())->toBe(['ghost']);

    $ghost->delete();
    expect(shellLastMap()['apps'])->toBe([]);
});

it('does not touch the map for an app that is not a container', function () {
    Application::forceCreate([
        'system_user_id' => $this->owner->id, 'name' => 'Blog', 'slug' => 'blog', 'domain' => 'blog.test',
        'site_type' => 'wordpress', 'serving_profile' => 'php', 'web_root' => 'public_html', 'status' => 'active',
    ]);

    expect($this->writes)->toBe([]);
});

it('stays out of the way when sync is off, and never fails the save that triggered it', function () {
    config()->set('server.app_shell.sync', false);
    shellGhostApp($this->owner);
    expect($this->writes)->toBe([]);

    config()->set('server.app_shell.sync', true);
    Process::fake(fn () => Process::result(exitCode: 1, errorOutput: 'tee: Permission denied'));

    expect(fn () => shellGhostApp($this->other)->forceFill(['slug' => 'ghost-two'])->save())->not->toThrow(Throwable::class);
});

it('is rebuilt from the database by containers:sync-shell-access --map-only', function () {
    config()->set('server.app_shell.sync', false);
    shellGhostApp($this->owner);
    expect($this->writes)->toBe([]);

    expect(Artisan::call('containers:sync-shell-access', ['--map-only' => true]))->toBe(0)
        ->and(collect(shellLastMap()['apps'])->pluck('owner', 'app')->all())->toBe(['ghost' => 'blogger']);
});

it('refuses to write anywhere when the paths are not configured', function () {
    config()->set('server.app_shell.map', '');
    $this->ran = [];

    expect(app(AppShellMap::class)->write(force: true))->toBeNull()
        ->and($this->ran)->toBe([]);
});
