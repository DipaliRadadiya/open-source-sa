<?php

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Models\SystemUser;
use App\Services\Applications\SiteTypeManager;
use App\Services\Server\Applications\ComposeValidator;
use App\Services\Server\Applications\ContainerSupervisor;
use App\Services\Server\ManagedFile;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use App\Services\Server\WebServers\NginxDriver;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Step one of the Docker stack: run one container from an image, behind the
 * nginx vhost a Node application already gets.
 *
 * Two invariants in the generated compose file are not conveniences, and both
 * have tests here because a rule that lives only in a template is a rule the
 * next code path forgets:
 *
 *  - **Ports publish to 127.0.0.1 only.** Docker writes its own rules into the
 *    DOCKER chain ahead of the ones ufw manages, so `"8080:80"` — the form
 *    everyone writes — is reachable from the internet while the panel's
 *    Firewall page says the port is closed.
 *  - **Bind mounts stay inside the application's own directory.** `- /:/host`
 *    hands over the machine.
 */

function containerApp(): Application
{
    $user = SystemUser::create(['username' => 'shop', 'home_path' => '/home/shop']);

    return Application::forceCreate([
        'system_user_id' => $user->id,
        'name' => 'Shop',
        'slug' => 'shop',
        'domain' => 'shop.test',
        'web_root' => 'public_html',
        'site_type' => 'docker',
        'serving_profile' => 'docker',
        'php_version' => '8.4',
        'app_port' => 20001,
        'image' => 'nginx:1.27-alpine',
        'container_port' => 80,
    ]);
}

/** Records every compose invocation and the file written. */
function containerDeps(array $handlers = [], array &$ran = [], ?string &$written = null): array
{
    $ops = Mockery::mock(ServerOps::class);
    $ops->shouldReceive('run')->andReturnUsing(
        function (array $command, array $context = []) use ($handlers, &$ran) {
            $op = $context['op'] ?? '';
            $ran[] = ['op' => $op, 'command' => $command];

            return isset($handlers[$op])
                ? $handlers[$op]()
                : new ServerOpsResult(ok: true, reference: 'r', answered: true);
        }
    );

    $files = Mockery::mock(ManagedFile::class);
    $files->shouldReceive('put')->andReturnUsing(function (string $path, string $contents) use (&$written) {
        $written = $contents;

        return new ServerOpsResult(ok: true, reference: 'r', answered: true);
    });

    return [$ops, $files];
}

function processResult(string $stdout): ProcessResult
{
    $p = Mockery::mock(ProcessResult::class);
    $p->shouldReceive('output')->andReturn($stdout);
    $p->shouldReceive('errorOutput')->andReturn('');

    return $p;
}

it('publishes only to loopback, never to every address', function () {
    // The single most important line in the generated file.
    $ran = [];
    $written = null;
    [$ops, $files] = containerDeps([
        'compose_ps' => fn () => new ServerOpsResult(ok: true, reference: 'r', result: processResult("abc123\n"), answered: true),
    ], $ran, $written);

    (new ContainerSupervisor($ops, $files, new ComposeValidator($ops)))->apply(containerApp(), '/home/shop/shop/public_html');

    expect($written)->toContain('"127.0.0.1:20001:80"')
        // The naive form, which binds 0.0.0.0 and is reachable past ufw.
        ->and($written)->not->toContain('"20001:80"')
        ->and($written)->not->toContain('0.0.0.0');
});

it('mounts the application directory and nothing above it', function () {
    $ran = [];
    $written = null;
    [$ops, $files] = containerDeps([
        'compose_ps' => fn () => new ServerOpsResult(ok: true, reference: 'r', result: processResult("abc\n"), answered: true),
    ], $ran, $written);

    (new ContainerSupervisor($ops, $files, new ComposeValidator($ops)))->apply(containerApp(), '/home/shop/shop/public_html');

    expect($written)->toContain('/home/shop/shop/public_html:/app')
        ->and($written)->not->toContain('- /:/');
});

it('gives every container a memory ceiling and bounded logs', function () {
    // Two ways one container takes the whole box down: exhausting memory, and
    // filling the disk. Docker's default json-file driver has no max size.
    $ran = [];
    $written = null;
    [$ops, $files] = containerDeps([
        'compose_ps' => fn () => new ServerOpsResult(ok: true, reference: 'r', result: processResult("abc\n"), answered: true),
    ], $ran, $written);

    (new ContainerSupervisor($ops, $files, new ComposeValidator($ops)))->apply(containerApp(), '/home/shop/shop/public_html');

    expect($written)->toContain('mem_limit:')
        ->and($written)->toContain('max-size:');
});

it('refuses to report a container that started and died as running', function () {
    // `docker compose up -d` exits 0 for a container that starts and
    // immediately exits — a bad image, a missing entrypoint, a command that
    // returns. Without the second check the panel shows a healthy green row
    // and the user gets a 502.
    $ran = [];
    $written = null;
    [$ops, $files] = containerDeps([
        // `up` succeeds...
        'compose_up' => fn () => new ServerOpsResult(ok: true, reference: 'r', answered: true),
        // ...and nothing is running.
        'compose_ps' => fn () => new ServerOpsResult(ok: true, reference: 'r', result: processResult(''), answered: true),
    ], $ran, $written);

    expect(fn () => (new ContainerSupervisor($ops, $files, new ComposeValidator($ops)))->apply(containerApp(), '/home/shop/shop/public_html'))
        ->toThrow(ProvisioningFailedException::class);
});

it('names the project explicitly, so two apps cannot collide', function () {
    // Compose infers a project name from the directory it runs in. Two
    // applications whose directories share a basename would infer the same
    // project and tear down each other's containers.
    $ran = [];
    $written = null;
    [$ops, $files] = containerDeps([
        'compose_ps' => fn () => new ServerOpsResult(ok: true, reference: 'r', result: processResult("abc\n"), answered: true),
    ], $ran, $written);

    $app = containerApp();
    (new ContainerSupervisor($ops, $files, new ComposeValidator($ops)))->apply($app, '/home/shop/shop/public_html');

    $up = collect($ran)->firstWhere('op', 'compose_up');

    expect($up['command'])->toContain('-p')
        ->and($up['command'])->toContain('sv-app-'.$app->id)
        // And an explicit file, not the working directory.
        ->and($up['command'])->toContain('-f');
});

it('does not delete data when the application is removed', function () {
    // `down --volumes` would take the database inside the container with it.
    // A volume outliving its container is recoverable; the reverse is not.
    $ran = [];
    $written = null;
    [$ops, $files] = containerDeps([], $ran, $written);

    (new ContainerSupervisor($ops, $files, new ComposeValidator($ops)))->remove(containerApp(), '/home/shop/shop/public_html');

    $down = collect($ran)->firstWhere('op', 'compose_down');

    expect($down['command'])->toContain('down')
        ->and($down['command'])->not->toContain('--volumes')
        ->and($down['command'])->not->toContain('-v');
});

it('bounds the log read', function () {
    // A container up for a month has more log than anything should read into
    // memory at once.
    $ran = [];
    $written = null;
    [$ops, $files] = containerDeps([], $ran, $written);

    (new ContainerSupervisor($ops, $files, new ComposeValidator($ops)))->logs(containerApp(), '/home/shop/shop/public_html');

    expect(collect($ran)->firstWhere('op', 'compose_logs')['command'])->toContain('--tail');
});

it('does not offer clone or backup, which would appear to work', function () {
    // Both arrive in the default feature set and both would succeed while
    // doing the wrong thing, which is worse than their absence: a missing
    // screen prompts a question, one that succeeds without doing the job
    // does not.
    //
    // Clone copies the served files — a container's are in its image and its
    // state is in its volumes. Backup takes the document root and a database —
    // a container has a compose file and volumes the backup never sees.
    $docker = collect(app(SiteTypeManager::class)->all())
        ->first(fn ($type) => $type->name() === 'docker');

    expect($docker)->not->toBeNull()
        ->and($docker->features())->not->toContain('app_clone')
        ->and($docker->features())->not->toContain('app_backup')
        // The ones it does keep, so this is a statement about two features
        // rather than an empty set.
        ->and($docker->features())->toContain('app_log')
        ->and($docker->features())->toContain('app_domain');
});

it('reuses the node vhost rather than needing one of its own', function () {
    // The template is selected by serving-profile NAME, so a container aborted
    // with a bare 500 from `View::exists` until `docker` was aliased to it —
    // even though the template itself is entirely runtime-agnostic and proxies
    // to a loopback port without asking what is behind it.
    expect(view()->exists('server.vhosts.nginx.node'))->toBeTrue()
        ->and(view()->exists('server.vhosts.nginx.docker'))->toBeFalse();

    // Asserted by rendering, not by reflecting a private constant: what
    // matters is that a container gets a working proxy vhost, not how the
    // template was chosen.
    $config = app(NginxDriver::class)->renderConfig(containerApp(), '/home/shop/shop/public_html');

    expect($config)->toContain('proxy_pass http://127.0.0.1:20001')
        // The upgrade pair, without which a WebSocket connection hangs waiting
        // for a handshake that never comes.
        ->and($config)->toContain('proxy_set_header Upgrade');
});

it('validates a user-supplied compose file again at deploy time', function () {
    // The form is not the only way a row changes — a restore, an import or a
    // direct edit all reach `apply()`. A rule enforced once at the boundary
    // holds only until something else writes the row.
    $app = containerApp();
    $app->compose = "services:\n  web:\n    image: nginx\n    privileged: true\n";
    $app->save();

    $ran = [];
    $written = null;
    [$ops, $files] = containerDeps([
        // The validator's own parse, answering with a resolved document that
        // contains the forbidden key.
        'compose_validate' => fn () => new ServerOpsResult(
            ok: true,
            reference: 'r',
            result: processResult(json_encode(['services' => ['web' => ['privileged' => true]]])),
            answered: true,
        ),
    ], $ran, $written);

    // The validator shares the mocked ServerOps deliberately. Resolving it
    // from the container gives it the real one, which cannot reach docker in
    // a test — every verdict comes back "unparsable" and the refusal tests
    // pass for the wrong reason.
    expect(fn () => (new ContainerSupervisor($ops, $files, new ComposeValidator($ops)))
        ->apply($app, '/home/shop/shop/public_html'))
        ->toThrow(ProvisioningFailedException::class);

    // And nothing was written: a file that fails validation must not reach
    // disk, or the next deploy picks it up without being asked.
    expect($written)->toBeNull();
});

it('writes the user file verbatim when it passes', function () {
    // Stored and written as given, not as the resolved document `config`
    // produces. The resolved form is normalised, expanded and reordered —
    // handing it back would give someone a file they did not write.
    $app = containerApp();
    $app->compose = "services:\n  web:\n    image: nginx:alpine\n    ports:\n      - \"127.0.0.1:20001:80\"\n";
    $app->save();

    $ran = [];
    $written = null;
    [$ops, $files] = containerDeps([
        'compose_validate' => fn () => new ServerOpsResult(
            ok: true,
            reference: 'r',
            result: processResult(json_encode(['services' => ['web' => [
                'image' => 'nginx:alpine',
                'ports' => [['host_ip' => '127.0.0.1', 'published' => '20001', 'target' => 80]],
            ]]])),
            answered: true,
        ),
        'compose_ps' => fn () => new ServerOpsResult(ok: true, reference: 'r', result: processResult("abc\n"), answered: true),
    ], $ran, $written);

    (new ContainerSupervisor($ops, $files, new ComposeValidator($ops)))
        ->apply($app, '/home/shop/shop/public_html');

    expect($written)->toBe($app->compose)
        // Not the generated template.
        ->and($written)->not->toContain('Managed by the panel');
});
