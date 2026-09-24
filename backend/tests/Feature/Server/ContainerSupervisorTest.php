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

it('is actually reached by provisioning, not merely callable', function () {
    // The gap that produced a 502 on a live server. `ContainerSupervisor`
    // existed, had seven passing tests, and was never called from
    // `ApplicationProvisioner` — so a Docker application provisioned to
    // "active" with no container anywhere and nginx proxying to a port
    // nothing listened on.
    //
    // Every other test here drives the supervisor directly, which proves it
    // works and nothing about whether it is reachable. This one reads the
    // wiring.
    $provisioner = file_get_contents(base_path('app/Services/Server/Applications/ApplicationProvisioner.php'));

    expect($provisioner)->toContain('ContainerSupervisor')
        ->and($provisioner)->toContain("serving_profile === 'docker'")
        // And on the way out too: a container left running holds its port and
        // serves traffic for a site the panel has stopped listing.
        ->and(substr_count($provisioner, "serving_profile === 'docker'"))->toBeGreaterThanOrEqual(2);
});

it('persists the type fields that are columns', function () {
    // `typeSettings()` skips fields that are real columns — correctly, a
    // column should not live in a JSON blob — but the create action's own
    // attribute list sets only the fields every type shares. A column-backed
    // type field was dropped by both, so a pasted compose file was accepted,
    // validated, and then simply did not exist.
    $create = file_get_contents(base_path('app/Actions/Server/Application/CreateApplication.php'));

    expect($create)->toContain('typeColumns');

    // The real assertion: every field the docker type declares is either a
    // column or a setting, and nothing declared falls between them.
    $columns = (new Application)->getFillable();
    $docker = collect(app(SiteTypeManager::class)->all())
        ->first(fn ($type) => $type->name() === 'docker');

    foreach ($docker->fields() as $field) {
        expect(in_array($field['name'], $columns, true))->toBeTrue(
            "docker field {$field['name']} is neither a column nor covered by a setting",
        );
    }
});

it('strips the escape codes the application itself prints', function () {
    // `--no-color` controls compose's OWN colouring — the service-name prefix
    // — and has no say over what the process inside the container prints. It
    // is easy to assume otherwise, and the panel showed Uptime Kuma's coloured
    // log lines as literal `[36m` text around every timestamp.
    $ansi = "uptime-kuma-1  | \e[36m2026-09-24T10:42:27Z\e[0m [\e[32mSERVER\e[0m] \e[36mINFO:\e[0m Env: production\n";

    $ran = [];
    $written = null;
    [$ops, $files] = containerDeps([
        'compose_logs' => fn () => new ServerOpsResult(
            ok: true, reference: 'r', result: processResult($ansi), answered: true,
        ),
    ], $ran, $written);

    $out = (new ContainerSupervisor($ops, $files, new ComposeValidator($ops)))
        ->logs(containerApp(), '/home/shop/shop/public_html');

    expect($out)->not->toContain("\e")
        ->and($out)->not->toContain('[36m')
        // The words survive — the information the colour carried is in the
        // text, which is why stripping is enough and a renderer is not needed.
        ->and($out)->toContain('SERVER')
        ->and($out)->toContain('INFO:')
        ->and($out)->toContain('Env: production')
        // And the service prefix stays: it is what `docker compose logs`
        // shows in a terminal too, and it is the only thing distinguishing
        // services once a compose file has more than one.
        ->and($out)->toContain('uptime-kuma-1');
});

it('strips OSC sequences, which would swallow the rest of a line', function () {
    // A window-title escape runs until BEL. Removing only CSI would leave it,
    // and everything up to the terminator would vanish into it.
    $ansi = "\e]0;some title\x07visible text\n";

    $ran = [];
    $written = null;
    [$ops, $files] = containerDeps([
        'compose_logs' => fn () => new ServerOpsResult(
            ok: true, reference: 'r', result: processResult($ansi), answered: true,
        ),
    ], $ran, $written);

    $out = (new ContainerSupervisor($ops, $files, new ComposeValidator($ops)))
        ->logs(containerApp(), '/home/shop/shop/public_html');

    expect(trim($out))->toBe('visible text');
});

/*
 * The network a site joins.
 *
 * `external: true` is the invariant under test, and it is not cosmetic: without
 * it Compose creates `<project>_<name>` and joins THAT, so the site comes up
 * healthy and cannot reach the container it was deliberately put beside.
 */

it('joins the chosen network, and declares it external', function () {
    $ran = [];
    $written = null;
    [$ops, $files] = containerDeps([
        'compose_ps' => fn () => new ServerOpsResult(ok: true, reference: 'r', result: processResult("abc\n"), answered: true),
    ], $ran, $written);

    $application = containerApp();
    $application->forceFill(['docker_network' => 'ghost-net'])->save();

    (new ContainerSupervisor($ops, $files, new ComposeValidator($ops)))
        ->apply($application, '/home/shop/shop/public_html');

    expect($written)->toContain('networks:')
        ->and($written)->toContain('ghost-net:')
        // The whole point. An `external: false` — or an absent declaration —
        // is a second, differently-named network that resolves nothing.
        ->and($written)->toContain('external: true')
        // And the site's own unique name on it. Compose registers the SERVICE
        // name too, and every generated file calls its service `app` — so two
        // panel sites on one network both answer to `app` and Docker's DNS
        // picks one at random. Measured on a real box before this was added.
        ->and($written)->toContain('aliases:')
        ->and($written)->toContain('- shop');
});

it('writes the same file it always did when no network was chosen', function () {
    // The regression that matters for every container site that already exists.
    // A stray blank line would be harmless YAML and would still make every
    // site's compose file "changed" on its next deploy — a diff nobody can tell
    // from a real one.
    $ran = [];
    $written = null;
    [$ops, $files] = containerDeps([
        'compose_ps' => fn () => new ServerOpsResult(ok: true, reference: 'r', result: processResult("abc\n"), answered: true),
    ], $ran, $written);

    (new ContainerSupervisor($ops, $files, new ComposeValidator($ops)))
        ->apply(containerApp(), '/home/shop/shop/public_html');

    expect($written)->not->toContain('networks:')
        ->and($written)->not->toContain('external:');
});

it('leaves a pasted compose file alone, network or not', function () {
    // A file that names its own networks must not have one appended: the user's
    // file is the file. The field is for the GENERATED path only.
    $pasted = <<<'YAML'
    services:
      app:
        image: nginx:1.27-alpine
        ports:
          - "127.0.0.1:20001:80"
        networks:
          - their-own-net
    networks:
      their-own-net:
        external: true
    YAML;

    $ran = [];
    $written = null;
    [$ops, $files] = containerDeps([
        'compose_validate' => fn () => new ServerOpsResult(
            ok: true, reference: 'r', result: processResult(json_encode([
                'services' => ['app' => [
                    'image' => 'nginx:1.27-alpine',
                    'ports' => [['published' => '20001', 'target' => 80, 'host_ip' => '127.0.0.1']],
                    'networks' => ['their-own-net' => null],
                ]],
                'networks' => ['their-own-net' => ['external' => true]],
            ])), answered: true,
        ),
        'compose_ps' => fn () => new ServerOpsResult(ok: true, reference: 'r', result: processResult("abc\n"), answered: true),
    ], $ran, $written);

    $application = containerApp();
    $application->forceFill(['compose' => $pasted, 'docker_network' => 'ghost-net'])->save();

    (new ContainerSupervisor($ops, $files, new ComposeValidator($ops)))
        ->apply($application, '/home/shop/shop/public_html');

    expect($written)->toContain('their-own-net')
        ->and($written)->not->toContain('ghost-net');
});
