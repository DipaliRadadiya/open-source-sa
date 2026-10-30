<?php

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Models\SystemUser;
use App\Rules\ContainerMountPath;
use App\Services\Applications\SiteTypeManager;
use App\Services\Recipes\RecipeRegistry;
use App\Services\Server\Applications\ComposeValidator;
use App\Services\Server\Applications\ContainerSupervisor;
use App\Services\Server\Docker\RegistryAuth;
use App\Services\Server\ManagedFile;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use App\Services\Server\WebServers\NginxDriver;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Yaml\Yaml;

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

/**
 * The panel override's contents, captured across a run.
 *
 * A function rather than another by-reference parameter, because every existing
 * caller of `containerDeps()` would otherwise need its signature changed.
 */
function overrideWritten(?string $contents = null): ?string
{
    static $last = null;

    if ($contents !== null) {
        $last = $contents;
    }

    return $last;
}

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
    // `$written` means the COMPOSE FILE, not "the last thing written". The panel
    // also writes `compose.panel.yml` now, and capturing indiscriminately made every
    // assertion about the compose file silently assert about the override instead.
    $files->shouldReceive('put')->andReturnUsing(function (string $path, string $contents) use (&$written) {
        if (str_ends_with($path, '/compose.panel.yml')) {
            overrideWritten($contents);

            return new ServerOpsResult(ok: true, reference: 'r', answered: true);
        }

        $written = $contents;

        return new ServerOpsResult(ok: true, reference: 'r', answered: true);
    });

    // The override is removed when it is not wanted, so a site that never needed
    // one — or stopped needing one — does not keep a file on disk stating limits
    // its containers do not have. Recorded, so a test can assert the removal
    // rather than only the absence of a write.
    $files->shouldReceive('delete')->andReturnUsing(function (string $path) {
        if (str_ends_with($path, '/compose.panel.yml')) {
            overrideDeleted(true);
        }

        return new ServerOpsResult(ok: true, reference: 'r', answered: true);
    });

    return [$ops, $files];
}

/** Was the override file removed during a run? */
function overrideDeleted(?bool $deleted = null): bool
{
    static $last = false;

    if ($deleted !== null) {
        $last = $deleted;
    }

    return $last;
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

    (new ContainerSupervisor($ops, $files, new ComposeValidator($ops), new RegistryAuth($ops)))->apply(containerApp(), '/home/shop/shop/public_html');

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

    (new ContainerSupervisor($ops, $files, new ComposeValidator($ops), new RegistryAuth($ops)))->apply(containerApp(), '/home/shop/shop/public_html');

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

    (new ContainerSupervisor($ops, $files, new ComposeValidator($ops), new RegistryAuth($ops)))->apply(containerApp(), '/home/shop/shop/public_html');

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

    expect(fn () => (new ContainerSupervisor($ops, $files, new ComposeValidator($ops), new RegistryAuth($ops)))->apply(containerApp(), '/home/shop/shop/public_html'))
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
    (new ContainerSupervisor($ops, $files, new ComposeValidator($ops), new RegistryAuth($ops)))->apply($app, '/home/shop/shop/public_html');

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

    (new ContainerSupervisor($ops, $files, new ComposeValidator($ops), new RegistryAuth($ops)))->remove(containerApp(), '/home/shop/shop/public_html');

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

    (new ContainerSupervisor($ops, $files, new ComposeValidator($ops), new RegistryAuth($ops)))->logs(containerApp(), '/home/shop/shop/public_html');

    expect(collect($ran)->firstWhere('op', 'compose_logs')['command'])->toContain('--tail');
});

it('does not offer clone, which would appear to work — and offers backup, which now does', function () {
    // Clone arrives in the default feature set and would succeed while doing
    // the wrong thing, which is worse than its absence: a missing screen
    // prompts a question, one that succeeds without doing the job does not.
    // It copies the served files — a container's are in its image and its
    // state is in its volumes.
    //
    // Backup was refused for the same reason until c3913c27 taught the
    // pipeline to archive a container site's volumes and compose file; this
    // test still said it was missing (DS-12).
    $docker = collect(app(SiteTypeManager::class)->all())
        ->first(fn ($type) => $type->name() === 'docker');

    expect($docker)->not->toBeNull()
        ->and($docker->features())->not->toContain('app_clone')
        ->and($docker->features())->toContain('app_backup')
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
    expect(fn () => (new ContainerSupervisor($ops, $files, new ComposeValidator($ops), new RegistryAuth($ops)))
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

    (new ContainerSupervisor($ops, $files, new ComposeValidator($ops), new RegistryAuth($ops)))
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

it('runs the post-start hook for a container application too', function () {
    // The same gap, one method along. The docker branch of `startProcess()`
    // returns early — so the fix for "the supervisor was never called from here"
    // left `afterStart()` unreachable for every container app, and the hook that
    // claims Chatwoot's first-run page was dead code for the one case it was
    // written for. Found on a real box: the site reached Active with
    // `/installation/onboarding` still answering 200 to anyone.
    //
    // Reads the wiring, like the test above, because the alternative is a full
    // provision and this is the fact that actually broke.
    $source = file_get_contents(base_path('app/Services/Server/Applications/ApplicationProvisioner.php'));

    // From `startProcess()` specifically — `serving_profile === 'docker'` appears
    // earlier in the file too, and anchoring on the first match read most of the
    // class instead of the branch in question.
    $method = strpos($source, 'private function startProcess(');
    $branch = substr($source, $method, strpos($source, 'return;', $method) - $method);

    expect($branch)
        ->toContain("serving_profile === 'docker'")
        // The CALL, not the word. Asserting on `afterStart` alone passed with the
        // call deleted, because the comment above it says the word -- a source
        // assertion that a comment can satisfy is not an assertion about code.
        ->toContain('$this->installers->afterStart(');
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

    // A third category exists now: the create form's "make a new one" fields are
    // neither columns nor settings ON PURPOSE. `docker_network_new` resolves into
    // `docker_network`, and `volume_new` + `volume_path` into `volume_mounts`, so
    // that ONE column answers "what network is this site on" — two columns for
    // one fact is a compose renderer that has to know about both.
    //
    // They are allowed here only if `containerWiring()` actually names them. That
    // keeps the guard's teeth: a new field that is neither a column nor wired
    // still fails, which is the case that originally dropped a pasted compose
    // file.
    $wiring = $create;
    $start = strpos($wiring, 'private function containerWiring');
    expect($start)->not->toBeFalse('containerWiring() is gone; the transient fields have no consumer');
    $body = substr($wiring, $start);

    // And it is CALLED, not merely written — the failure mode this whole file
    // exists to catch.
    expect($create)->toContain('...$this->containerWiring($data, $slug, $takenVolumes)');

    foreach ($docker->fields() as $field) {
        $name = $field['name'];

        expect(in_array($name, $columns, true) || str_contains($body, "'{$name}'"))->toBeTrue(
            "docker field {$name} is neither a column, a setting, nor resolved by containerWiring()",
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

    $out = (new ContainerSupervisor($ops, $files, new ComposeValidator($ops), new RegistryAuth($ops)))
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

    $out = (new ContainerSupervisor($ops, $files, new ComposeValidator($ops), new RegistryAuth($ops)))
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

    (new ContainerSupervisor($ops, $files, new ComposeValidator($ops), new RegistryAuth($ops)))
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

    (new ContainerSupervisor($ops, $files, new ComposeValidator($ops), new RegistryAuth($ops)))
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

    (new ContainerSupervisor($ops, $files, new ComposeValidator($ops), new RegistryAuth($ops)))
        ->apply($application, '/home/shop/shop/public_html');

    expect($written)->toContain('their-own-net')
        ->and($written)->not->toContain('ghost-net');
});

/*
 * The volumes a site mounts.
 *
 * `external: true` matters even more here than it does for the network. Without
 * it, a name Compose cannot find is a name Compose CREATES — an empty volume —
 * so the site comes up with none of its data while the real volume sits
 * unreferenced. That reads as data loss and invites a restore over a volume that
 * was fine all along.
 */

it('mounts the volumes it is given, and declares them external', function () {
    $ran = [];
    $written = null;
    [$ops, $files] = containerDeps([
        'compose_ps' => fn () => new ServerOpsResult(ok: true, reference: 'r', result: processResult("abc\n"), answered: true),
    ], $ran, $written);

    $application = containerApp();
    $application->forceFill(['volume_mounts' => [
        ['volume' => 'shop-db', 'path' => '/var/lib/mysql'],
        ['volume' => 'shop-uploads', 'path' => '/srv/uploads'],
    ]])->save();

    (new ContainerSupervisor($ops, $files, new ComposeValidator($ops), new RegistryAuth($ops)))
        ->apply($application, '/home/shop/shop/public_html');

    expect($written)->toContain('- shop-db:/var/lib/mysql')
        ->and($written)->toContain('- shop-uploads:/srv/uploads')
        // The site's own directory is still mounted; a volume is added beside it.
        ->and($written)->toContain(':/app')
        ->and($written)->toContain('external: true');
});

it('writes the same file it always did when no volume is mounted', function () {
    $ran = [];
    $written = null;
    [$ops, $files] = containerDeps([
        'compose_ps' => fn () => new ServerOpsResult(ok: true, reference: 'r', result: processResult("abc\n"), answered: true),
    ], $ran, $written);

    (new ContainerSupervisor($ops, $files, new ComposeValidator($ops), new RegistryAuth($ops)))
        ->apply(containerApp(), '/home/shop/shop/public_html');

    expect($written)->not->toContain('external:')
        // The bind mount, and nothing beside it. Named explicitly rather than
        // counting `- ` lines, which also counts the port and the env file.
        ->and($written)->toContain('- /home/shop/shop/public_html:/app')
        ->and(substr_count($written, ':/app'))->toBe(1);
});

it('declares a volume once even when it is mounted twice', function () {
    // Two paths, one volume — legal, and a duplicate top-level key is not.
    $ran = [];
    $written = null;
    [$ops, $files] = containerDeps([
        'compose_ps' => fn () => new ServerOpsResult(ok: true, reference: 'r', result: processResult("abc\n"), answered: true),
    ], $ran, $written);

    $application = containerApp();
    $application->forceFill(['volume_mounts' => [
        ['volume' => 'shared', 'path' => '/one'],
        ['volume' => 'shared', 'path' => '/two'],
    ]])->save();

    (new ContainerSupervisor($ops, $files, new ComposeValidator($ops), new RegistryAuth($ops)))
        ->apply($application, '/home/shop/shop/public_html');

    // The DECLARATION once, at the top level. `substr_count('shared:')` also
    // counts the two mount lines, which is how this assertion first read 3 and
    // said nothing useful.
    expect(substr_count($written, "\nvolumes:\n  shared:\n"))->toBe(1)
        ->and(substr_count($written, 'external: true'))->toBe(1)
        ->and($written)->toContain('- shared:/one')
        ->and($written)->toContain('- shared:/two');
});

it('skips a half-written mount rather than rendering a broken line', function () {
    // A row with no path would render `- name:` — valid YAML, and a mount Docker
    // refuses. The column is JSON, so a partial row can arrive from anywhere.
    $ran = [];
    $written = null;
    [$ops, $files] = containerDeps([
        'compose_ps' => fn () => new ServerOpsResult(ok: true, reference: 'r', result: processResult("abc\n"), answered: true),
    ], $ran, $written);

    $application = containerApp();
    $application->forceFill(['volume_mounts' => [
        ['volume' => 'good', 'path' => '/data'],
        ['volume' => 'orphan', 'path' => ''],
        ['path' => '/nameless'],
    ]])->save();

    (new ContainerSupervisor($ops, $files, new ComposeValidator($ops), new RegistryAuth($ops)))
        ->apply($application, '/home/shop/shop/public_html');

    expect($written)->toContain('- good:/data')
        ->and($written)->not->toContain('orphan')
        ->and($written)->not->toContain('/nameless');
});

/*
 * The panel's compose override, for a PASTED file.
 *
 * `ComposeValidator` rewrites published ports to loopback and refuses the keys that
 * hand over the host — and adds nothing. Measured: zero references to `mem_limit` or
 * `logging` in that class. So a hand-written compose file was a site with no memory
 * ceiling and unbounded logs, which are the two failures that take a box down.
 */

it('keeps the limits when the image is pulled, not only when the site is applied', function () {
    // "Pull and redeploy" on a pasted-compose site used to DELETE the override and
    // recreate the container with no `mem_limit`, no `cpus` and unbounded logs.
    // `writeOverride()` reads `$pastedServices`, which only `contents()` fills, and
    // `pull()` called it without rendering first. `start()` three methods away
    // carries a comment saying exactly why it re-renders.
    $pasted = <<<'YAML'
    services:
      web:
        image: nginx:1.27-alpine
        ports:
          - "127.0.0.1:20001:80"
    YAML;

    $ran = [];
    $written = null;
    [$ops, $files] = containerDeps([
        'compose_validate' => fn () => new ServerOpsResult(
            ok: true, reference: 'r', result: processResult(json_encode([
                'services' => [
                    'web' => ['image' => 'nginx:1.27-alpine', 'ports' => [['published' => '20001', 'target' => 80, 'host_ip' => '127.0.0.1']]],
                ],
            ])), answered: true,
        ),
        'compose_ps' => fn () => new ServerOpsResult(ok: true, reference: 'r', result: processResult("abc\n"), answered: true),
    ], $ran, $written);

    $application = containerApp();
    $application->forceFill(['compose' => $pasted])->save();

    (new ContainerSupervisor($ops, $files, new ComposeValidator($ops), new RegistryAuth($ops)))
        ->pull($application, '/home/shop/shop/public_html');

    $override = overrideWritten();

    expect($override)->not->toBeNull('pull wrote no override, so the limits were dropped')
        ->and($override)->toContain('  web:')
        ->and($override)->toContain('mem_limit')
        ->and($override)->toContain('max-size');
});

it('does not carry a pasted file\'s service names into a generated one', function () {
    // The rollback path. `UpdateContainerCompose` renders the pasted file, fails,
    // then renders the GENERATED one on the same instance — and `$pastedServices`
    // was only ever set, never reset, so the second override named services the
    // generated file does not have. `compose up` is then handed an override
    // referring to nothing, and the rollback fails with the site already down.
    $pasted = <<<'YAML'
    services:
      invented:
        image: nginx:1.27-alpine
        ports:
          - "127.0.0.1:20001:80"
    YAML;

    $ran = [];
    $written = null;
    [$ops, $files] = containerDeps([
        'compose_validate' => fn () => new ServerOpsResult(
            ok: true, reference: 'r', result: processResult(json_encode([
                'services' => [
                    'invented' => ['image' => 'nginx:1.27-alpine', 'ports' => [['published' => '20001', 'target' => 80, 'host_ip' => '127.0.0.1']]],
                ],
            ])), answered: true,
        ),
        'compose_ps' => fn () => new ServerOpsResult(ok: true, reference: 'r', result: processResult("abc\n"), answered: true),
    ], $ran, $written);

    $supervisor = new ContainerSupervisor($ops, $files, new ComposeValidator($ops), new RegistryAuth($ops));

    $application = containerApp();
    $application->forceFill(['compose' => $pasted])->save();
    $supervisor->apply($application, '/home/shop/shop/public_html');

    expect(overrideWritten())->toContain('  invented:');

    // `overrideWritten()` is a memo of the last WRITE, and the correct behaviour
    // here is a DELETE — so without clearing it first this asserts against the
    // previous render and passes whatever happens. Reset, then the memo is only
    // non-empty if the second render wrote an override of its own.
    overrideWritten('');

    // Now the generated file, same instance — as a rollback does.
    $application->forceFill(['compose' => null])->save();
    $supervisor->apply($application->fresh(), '/home/shop/shop/public_html');

    expect(overrideWritten())->not->toContain('invented',
        'the generated render wrote an override naming the pasted file\'s services')
        ->and(overrideWritten())->toBe('', 'a generated file needs no override at all');
});

it('gives a pasted compose file a memory ceiling and bounded logs', function () {
    $pasted = <<<'YAML'
    services:
      web:
        image: nginx:1.27-alpine
        ports:
          - "127.0.0.1:20001:80"
      worker:
        image: nginx:1.27-alpine
    YAML;

    $ran = [];
    $written = null;
    [$ops, $files] = containerDeps([
        'compose_validate' => fn () => new ServerOpsResult(
            ok: true, reference: 'r', result: processResult(json_encode([
                'services' => [
                    'web' => ['image' => 'nginx:1.27-alpine', 'ports' => [['published' => '20001', 'target' => 80, 'host_ip' => '127.0.0.1']]],
                    'worker' => ['image' => 'nginx:1.27-alpine'],
                ],
            ])), answered: true,
        ),
        'compose_ps' => fn () => new ServerOpsResult(ok: true, reference: 'r', result: processResult("abc\n"), answered: true),
    ], $ran, $written);

    $application = containerApp();
    $application->forceFill(['compose' => $pasted])->save();

    (new ContainerSupervisor($ops, $files, new ComposeValidator($ops), new RegistryAuth($ops)))
        ->apply($application, '/home/shop/shop/public_html');

    $override = overrideWritten();

    expect($override)->toContain('services:')
        // Every service named, from the RESOLVED document — compose accepts
        // `extends` and anchors, so reading the user's text would miss some.
        ->and($override)->toContain('  web:')
        ->and($override)->toContain('  worker:')
        ->and(substr_count($override, 'mem_limit'))->toBe(2)
        ->and(substr_count($override, 'max-size'))->toBe(2);
});

it('passes the override to up, and to nothing else', function () {
    // Limits are read when a container is CREATED. `down`, `logs` and the rest do
    // not need them — and passing `-f` for a file that is not on disk, which is the
    // case for any site provisioned before this existed, fails the whole command.
    $pasted = "services:\n  web:\n    image: nginx:1.27-alpine\n    ports:\n      - \"127.0.0.1:20001:80\"\n";

    $ran = [];
    $written = null;
    [$ops, $files] = containerDeps([
        'compose_validate' => fn () => new ServerOpsResult(
            ok: true, reference: 'r', result: processResult(json_encode([
                'services' => ['web' => ['image' => 'nginx:1.27-alpine', 'ports' => [['published' => '20001', 'target' => 80, 'host_ip' => '127.0.0.1']]]],
            ])), answered: true,
        ),
        'compose_ps' => fn () => new ServerOpsResult(ok: true, reference: 'r', result: processResult("abc\n"), answered: true),
    ], $ran, $written);

    $application = containerApp();
    $application->forceFill(['compose' => $pasted])->save();

    $supervisor = new ContainerSupervisor($ops, $files, new ComposeValidator($ops), new RegistryAuth($ops));
    $supervisor->apply($application, '/home/shop/shop/public_html');
    $supervisor->stop($application, '/home/shop/shop/public_html');

    // `contains('compose.panel.yml')` is an EQUALITY check and the command holds a
    // full path, so it matched nothing — a predicate is required, and without one
    // this test passed vacuously in both directions.
    $overrides = fn (string $op): int => collect($ran)
        ->where('op', $op)
        ->filter(fn (array $row): bool => collect($row['command'])
            ->contains(fn ($argument): bool => str_contains((string) $argument, 'compose.panel.yml')))
        ->count();

    expect($overrides('compose_up'))->toBeGreaterThan(0)
        ->and($overrides('compose_stop'))->toBe(0);
});

it('writes no override for a generated file, which carries its own', function () {
    // Duplicating the limits would be two sources for one value.
    $ran = [];
    $written = null;
    [$ops, $files] = containerDeps([
        'compose_ps' => fn () => new ServerOpsResult(ok: true, reference: 'r', result: processResult("abc\n"), answered: true),
    ], $ran, $written);

    (new ContainerSupervisor($ops, $files, new ComposeValidator($ops), new RegistryAuth($ops)))
        ->apply(containerApp(), '/home/shop/shop/public_html');

    expect(collect($ran)->contains(
        fn (array $row): bool => collect($row['command'])
            ->contains(fn ($argument): bool => str_contains((string) $argument, 'compose.panel.yml'))
    ))->toBeFalse()
        // And the generated file still has them itself.
        ->and($written)->toContain('mem_limit');
});

it('honours a per-site memory limit in the override', function () {
    $pasted = "services:\n  web:\n    image: nginx:1.27-alpine\n    ports:\n      - \"127.0.0.1:20001:80\"\n";

    $ran = [];
    $written = null;
    [$ops, $files] = containerDeps([
        'compose_validate' => fn () => new ServerOpsResult(
            ok: true, reference: 'r', result: processResult(json_encode([
                'services' => ['web' => ['image' => 'nginx:1.27-alpine', 'ports' => [['published' => '20001', 'target' => 80, 'host_ip' => '127.0.0.1']]]],
            ])), answered: true,
        ),
        'compose_ps' => fn () => new ServerOpsResult(ok: true, reference: 'r', result: processResult("abc\n"), answered: true),
    ], $ran, $written);

    $application = containerApp();
    $application->forceFill(['compose' => $pasted, 'memory_limit' => '3g'])->save();

    (new ContainerSupervisor($ops, $files, new ComposeValidator($ops), new RegistryAuth($ops)))
        ->apply($application, '/home/shop/shop/public_html');

    expect(overrideWritten())->toContain('mem_limit: 3g');
});

/*
 * Per-container CPU quotas.
 *
 * The invariant worth a test is the ABSENCE, not the presence. `cpus` is the one
 * limit with no default: memory falls back to the configured 512m, and CPU falls
 * back to nothing at all, because a default would have capped every container site
 * already on the box the next time it deployed. So a site without the field has to
 * render the file it rendered before the column existed — and the failure mode of
 * getting that wrong is not an error, it is a diff on every site's compose file
 * that nobody can tell from a real change.
 *
 * The other half is the empty key. Compose reads `cpus: ` as `0`, and `0` means no
 * limit, so a key rendered with a null value is the one spelling that silently
 * removes the limit it appears to set.
 */
it('writes a cpu quota only when the site has one', function () {
    $ran = [];
    $written = null;
    [$ops, $files] = containerDeps([
        'compose_ps' => fn () => new ServerOpsResult(ok: true, reference: 'r', result: processResult("abc\n"), answered: true),
    ], $ran, $written);

    $application = containerApp();
    $application->forceFill(['cpu_limit' => '1.5'])->save();

    (new ContainerSupervisor($ops, $files, new ComposeValidator($ops), new RegistryAuth($ops)))
        ->apply($application, '/home/shop/shop/public_html');

    expect($written)->toContain('cpus: 1.5');
});

it('omits the cpus key entirely for a site with no quota, and renders the same file as before', function () {
    $ran = [];
    $withoutColumn = null;
    [$ops, $files] = containerDeps([
        'compose_ps' => fn () => new ServerOpsResult(ok: true, reference: 'r', result: processResult("abc\n"), answered: true),
    ], $ran, $withoutColumn);

    $supervisor = new ContainerSupervisor($ops, $files, new ComposeValidator($ops), new RegistryAuth($ops));
    $application = containerApp();

    $none = $supervisor->generated($application, '/home/shop/shop/public_html');

    // Not just "no value" — no key. An empty `cpus:` is `0` to Compose, which
    // means unlimited, so the wrong spelling of "no limit" is also the wrong
    // spelling of "no limit set anywhere else either".
    expect($none)->not->toContain('cpus');

    // And setting one changes exactly that: the rest of the file is untouched, so
    // a limit arriving does not read as a rewrite of the site's configuration.
    $application->forceFill(['cpu_limit' => '2'])->save();
    $with = $supervisor->generated($application->fresh(), '/home/shop/shop/public_html');

    expect(str_replace("    cpus: 2\n", '', $with))->toBe($none);
});

it('refuses to treat a zero cpu limit as a limit', function () {
    // `cpus: 0` is Docker's own spelling of "no quota". A site holding '0' would
    // therefore render a key that reads as a limit and does the opposite, so the
    // model's falsy check has to swallow it the way it swallows an empty string.
    $ran = [];
    $written = null;
    [$ops, $files] = containerDeps([
        'compose_ps' => fn () => new ServerOpsResult(ok: true, reference: 'r', result: processResult("abc\n"), answered: true),
    ], $ran, $written);

    $application = containerApp();
    $application->forceFill(['cpu_limit' => '0'])->save();

    $generated = (new ContainerSupervisor($ops, $files, new ComposeValidator($ops), new RegistryAuth($ops)))
        ->generated($application, '/home/shop/shop/public_html');

    expect($generated)->not->toContain('cpus');
});

it('gives every service in a pasted file the same cpu quota', function () {
    // Per container, not a budget shared out. There is no way to tell which
    // service in a hand-written file is "the app", and limiting none of them would
    // make the field do nothing for exactly the sites most likely to need it.
    $pasted = <<<'YAML'
    services:
      web:
        image: nginx:1.27-alpine
        ports:
          - "127.0.0.1:20001:80"
      worker:
        image: nginx:1.27-alpine
    YAML;

    $ran = [];
    $written = null;
    [$ops, $files] = containerDeps([
        'compose_validate' => fn () => new ServerOpsResult(
            ok: true, reference: 'r', result: processResult(json_encode([
                'services' => [
                    'web' => ['image' => 'nginx:1.27-alpine', 'ports' => [['published' => '20001', 'target' => 80, 'host_ip' => '127.0.0.1']]],
                    'worker' => ['image' => 'nginx:1.27-alpine'],
                ],
            ])), answered: true,
        ),
        'compose_ps' => fn () => new ServerOpsResult(ok: true, reference: 'r', result: processResult("abc\n"), answered: true),
    ], $ran, $written);

    $application = containerApp();
    $application->forceFill(['compose' => $pasted, 'cpu_limit' => '0.5'])->save();

    (new ContainerSupervisor($ops, $files, new ComposeValidator($ops), new RegistryAuth($ops)))
        ->apply($application, '/home/shop/shop/public_html');

    expect(substr_count((string) overrideWritten(), 'cpus: 0.5'))->toBe(2);
});

it('writes no cpus line into the override for a pasted site with no quota', function () {
    // The override MERGES over the user's file. A `cpus: ` with nothing after it
    // would be read as `0` — no limit — so the panel's own override would be the
    // thing that removed a limit the user had written themselves.
    $pasted = "services:\n  web:\n    image: nginx:1.27-alpine\n    cpus: 2\n    ports:\n      - \"127.0.0.1:20001:80\"\n";

    $ran = [];
    $written = null;
    [$ops, $files] = containerDeps([
        'compose_validate' => fn () => new ServerOpsResult(
            ok: true, reference: 'r', result: processResult(json_encode([
                'services' => ['web' => ['image' => 'nginx:1.27-alpine', 'ports' => [['published' => '20001', 'target' => 80, 'host_ip' => '127.0.0.1']]]],
            ])), answered: true,
        ),
        'compose_ps' => fn () => new ServerOpsResult(ok: true, reference: 'r', result: processResult("abc\n"), answered: true),
    ], $ran, $written);

    $application = containerApp();
    $application->forceFill(['compose' => $pasted])->save();

    (new ContainerSupervisor($ops, $files, new ComposeValidator($ops), new RegistryAuth($ops)))
        ->apply($application, '/home/shop/shop/public_html');

    expect((string) overrideWritten())->not->toContain('cpus');
});

it('writes no override for a file the panel rendered itself', function () {
    // 🔴 The bug this guard exists for, found on a real box. `contents()` takes the
    // pasted-file branch whenever `compose` is non-empty — and a ONE-CLICK app's
    // compose column holds a file the panel rendered from that app's own template,
    // which already carries per-service limits. So the override applied the app's
    // ceiling to every service in it, and a Ghost installed at 640m gave its MySQL
    // 640m while the file on disk plainly said 512m. `docker inspect` was the only
    // place the two disagreed.
    //
    // The override is for a HAND-WRITTEN file, which has no limits of its own.
    // A generated one needs nothing added and must not have its numbers replaced.
    //
    // Keyed on the recipe registry — the same source the installer renders
    // from — so a new one-click cannot be added without being covered.
    $pasted = <<<'YAML'
    services:
      ghost:
        image: ghost:5-alpine
        mem_limit: 640m
        cpus: 0.5
        ports:
          - "127.0.0.1:20001:2368"
      db:
        image: mysql:8.4
        mem_limit: 512m
    YAML;

    $ran = [];
    $written = null;
    [$ops, $files] = containerDeps([
        'compose_validate' => fn () => new ServerOpsResult(
            ok: true, reference: 'r', result: processResult(json_encode([
                // What `docker compose config` really resolves the file above to:
                // sizes in bytes, the CPU quota as a number. A recipe override is
                // decided by comparing the site's limits with these.
                'services' => [
                    'ghost' => ['image' => 'ghost:5-alpine', 'mem_limit' => '671088640', 'cpus' => 0.5, 'ports' => [['published' => '20001', 'target' => 2368, 'host_ip' => '127.0.0.1']]],
                    'db' => ['image' => 'mysql:8.4', 'mem_limit' => '536870912'],
                ],
            ])), answered: true,
        ),
        'compose_ps' => fn () => new ServerOpsResult(ok: true, reference: 'r', result: processResult("abc\n"), answered: true),
    ], $ran, $written);

    overrideWritten('');
    overrideDeleted(false);

    $application = containerApp();
    $application->forceFill([
        // A registered one-click type, not the generic `docker` card.
        'site_type' => 'ghost',
        'compose' => $pasted,
        'memory_limit' => '640m',
        'cpu_limit' => '0.5',
    ])->save();

    (new ContainerSupervisor($ops, $files, new ComposeValidator($ops), new RegistryAuth($ops)))
        ->apply($application, '/home/shop/shop/public_html');

    expect(overrideWritten())->toBe('')
        // And the stale one from before this guard existed is taken away, rather
        // than left in the site's directory saying the database is capped at the
        // app's ceiling — which is the first thing anybody debugging would read.
        ->and(overrideDeleted())->toBeTrue();

    // And `-f compose.panel.yml` is not passed to `up` either, which is the half
    // that would fail loudly rather than quietly: the file is not on disk.
    expect(collect($ran)->contains(
        fn (array $entry): bool => in_array('compose.panel.yml', array_map(
            fn ($a): string => basename((string) $a), $entry['command']
        ), true),
    ))->toBeFalse();
});

it('still writes an override for a genuinely hand-written file', function () {
    // The other side of the same guard. A `docker` site's compose column IS the
    // user's, carries no limits, and losing the override would mean a site with no
    // memory ceiling and unbounded logs — the two failures the generated template
    // has a paragraph each about.
    $pasted = "services:\n  web:\n    image: nginx:1.27-alpine\n    ports:\n      - \"127.0.0.1:20001:80\"\n";

    $ran = [];
    $written = null;
    [$ops, $files] = containerDeps([
        'compose_validate' => fn () => new ServerOpsResult(
            ok: true, reference: 'r', result: processResult(json_encode([
                'services' => ['web' => ['image' => 'nginx:1.27-alpine', 'ports' => [['published' => '20001', 'target' => 80, 'host_ip' => '127.0.0.1']]]],
            ])), answered: true,
        ),
        'compose_ps' => fn () => new ServerOpsResult(ok: true, reference: 'r', result: processResult("abc\n"), answered: true),
    ], $ran, $written);

    overrideWritten('');

    $application = containerApp();
    $application->forceFill(['compose' => $pasted, 'memory_limit' => '256m'])->save();

    (new ContainerSupervisor($ops, $files, new ComposeValidator($ops), new RegistryAuth($ops)))
        ->apply($application, '/home/shop/shop/public_html');

    expect(overrideWritten())->toContain('mem_limit: 256m');
});

/*
 * A pasted file that sets its OWN resource limits (RC-42).
 *
 * Compose refuses two spellings of one limit with different values — "can't set
 * distinct values on 'mem_limit' and 'deploy.resources.limits.memory'" — so an
 * override that always wrote `mem_limit` made every pasted file using `deploy:`
 * a site that never started. The override now writes the key the user's service
 * already uses, and keeps the lower of the two numbers: the panel's value is a
 * ceiling, not a replacement.
 */

/**
 * Apply a pasted file whose RESOLVED services are `$services`, and answer the
 * override parsed back into an array.
 *
 * @param  array<string, array<string, mixed>>  $services
 * @param  array<string, mixed>  $fill
 * @return array<string, mixed>
 */
function pastedOverride(array $services, array $fill = []): array
{
    $ran = [];
    $written = null;
    [$ops, $files] = containerDeps([
        'compose_validate' => fn () => new ServerOpsResult(
            ok: true, reference: 'r', result: processResult(json_encode(['services' => $services])), answered: true,
        ),
        'compose_ps' => fn () => new ServerOpsResult(ok: true, reference: 'r', result: processResult("abc\n"), answered: true),
    ], $ran, $written);

    overrideWritten('');

    $application = containerApp();
    $application->forceFill(['compose' => "services:\n  web:\n    image: nginx:1.27-alpine\n", ...$fill])->save();

    (new ContainerSupervisor($ops, $files, new ComposeValidator($ops), new RegistryAuth($ops)))
        ->apply($application, '/home/shop/shop/public_html');

    return Yaml::parse((string) overrideWritten());
}

/** A resolved service publishing the site's port, plus whatever else it sets. */
function pastedWeb(array $extra = []): array
{
    return ['image' => 'nginx:1.27-alpine', 'ports' => [['published' => '20001', 'target' => 80, 'host_ip' => '127.0.0.1']], ...$extra];
}

it('writes the memory ceiling under deploy when the pasted service sets it there', function () {
    // The live repro: `deploy.resources.limits.memory: 128m`, which
    // `docker compose config` resolves to bytes.
    $override = pastedOverride([
        'web' => pastedWeb(['deploy' => ['resources' => ['limits' => ['memory' => '134217728']]]]),
    ]);

    $web = $override['services']['web'];

    // The user's 128 MiB is below the default 512m ceiling, so it stays.
    expect($web['deploy']['resources']['limits']['memory'])->toBe('134217728')
        ->and($web)->not->toHaveKey('mem_limit')
        ->and($web['logging']['options']['max-size'])->toBe('10m');
});

it('lowers a pasted service\'s own deploy memory to the site ceiling', function () {
    $override = pastedOverride(
        ['web' => pastedWeb(['deploy' => ['resources' => ['limits' => ['memory' => '134217728']]]])],
        ['memory_limit' => '64m'],
    );

    expect($override['services']['web']['deploy']['resources']['limits']['memory'])->toBe('64m')
        ->and($override['services']['web'])->not->toHaveKey('mem_limit');
});

it('writes the cpu quota under deploy when the pasted service sets it there', function () {
    $override = pastedOverride(
        ['web' => pastedWeb(['deploy' => ['resources' => ['limits' => ['cpus' => '2']]]])],
        ['cpu_limit' => '0.5'],
    );

    $web = $override['services']['web'];

    expect($web['deploy']['resources']['limits']['cpus'])->toBe(0.5)
        ->and($web)->not->toHaveKey('cpus')
        // No memory under deploy in the user's file, so memory keeps its usual key.
        ->and($web['mem_limit'])->toBe('512m')
        ->and($web['deploy']['resources']['limits'])->not->toHaveKey('memory');
});

it('keeps a pasted service\'s own lower cpu quota under deploy', function () {
    $override = pastedOverride(
        ['web' => pastedWeb(['deploy' => ['resources' => ['limits' => ['cpus' => 0.25]]]])],
        ['cpu_limit' => '1.5'],
    );

    expect($override['services']['web']['deploy']['resources']['limits']['cpus'])->toBe(0.25);
});

it('adds no cpu key under deploy for a site with no cpu quota', function () {
    // Unchanged from before: no site quota, no CPU key in the override at all, so
    // the user's own `deploy` quota is the one that applies.
    $override = pastedOverride([
        'web' => pastedWeb(['deploy' => ['resources' => ['limits' => ['cpus' => '2', 'memory' => '134217728']]]]),
    ]);

    expect($override['services']['web']['deploy']['resources']['limits'])->toBe(['memory' => '134217728']);
});

it('keeps a pasted service\'s own mem_limit when it is below the ceiling', function () {
    $override = pastedOverride(['web' => pastedWeb(['mem_limit' => '134217728'])], ['memory_limit' => '1g']);

    expect($override['services']['web']['mem_limit'])->toBe(134217728)
        ->and($override['services']['web'])->not->toHaveKey('deploy');
});

it('lowers a pasted service\'s own mem_limit to the ceiling', function () {
    $override = pastedOverride(['web' => pastedWeb(['mem_limit' => '2147483648'])], ['memory_limit' => '1g']);

    expect($override['services']['web']['mem_limit'])->toBe('1g');
});

it('leaves a pasted service with no limits of its own exactly as before', function () {
    $override = pastedOverride(['web' => pastedWeb()], ['cpu_limit' => '0.5']);

    expect($override['services']['web'])->toBe([
        'mem_limit' => '512m',
        'cpus' => 0.5,
        'logging' => ['driver' => 'json-file', 'options' => ['max-size' => '10m', 'max-file' => '3']],
    ]);
});

it('gives each pasted service the limit form it uses itself', function () {
    $override = pastedOverride([
        'web' => pastedWeb(['deploy' => ['resources' => ['limits' => ['memory' => '134217728', 'cpus' => '2']]]]),
        'worker' => ['image' => 'nginx:1.27-alpine'],
    ], ['cpu_limit' => '0.5']);

    $web = $override['services']['web'];
    $worker = $override['services']['worker'];

    expect($web['deploy']['resources']['limits'])->toBe(['memory' => '134217728', 'cpus' => 0.5])
        ->and($web)->not->toHaveKey('mem_limit')
        ->and($web)->not->toHaveKey('cpus')
        ->and($worker['mem_limit'])->toBe('512m')
        ->and($worker['cpus'])->toBe(0.5)
        ->and($worker)->not->toHaveKey('deploy');
});

it('quotes the deploy memory limit, which Compose only accepts as a string', function (string $own, ?string $siteLimit, string $expected) {
    // RC-42-FIX, found live: `deploy.resources.limits.memory: 134217728` fails
    // `docker compose config` with "must be a string", so the site never starts.
    // `mem_limit` takes a number; this key does not. Both the service's own
    // value and the ceiling must reach Compose as a string.
    $override = pastedOverride(
        ['web' => pastedWeb(['deploy' => ['resources' => ['limits' => ['memory' => $own]]]])],
        $siteLimit === null ? [] : ['memory_limit' => $siteLimit],
    );

    expect($override['services']['web']['deploy']['resources']['limits']['memory'])->toBe($expected)
        ->and((string) overrideWritten())->toContain("          memory: \"{$expected}\"\n");
})->with([
    'own 128m, below the default ceiling' => ['134217728', null, '134217728'],
    'own 2g, above the ceiling' => ['2147483648', '1g', '1g'],
]);

/*
 * Where the site's own directory is mounted (DS-01).
 *
 * Every simple-mode container used to get `public_html` bound over `/app`, which
 * is where changedetection.io, Gotify, Actual, Umami and every linuxserver.io
 * image keep their program. The empty directory hid it, the container
 * crash-looped, and the site showed "Setup failed".
 *
 * The goldens under tests/Fixtures/docker were rendered by the code BEFORE this
 * change. A site created before it has no `site_mount_path`, and its file must
 * stay byte-for-byte what it was — a "changed" compose file on the next deploy
 * is a diff nobody can tell from a real one.
 */

it('renders an existing site byte-for-byte as before the fix', function () {
    [$ops, $files] = containerDeps();
    $supervisor = new ContainerSupervisor($ops, $files, new ComposeValidator($ops), new RegistryAuth($ops));
    $application = containerApp();

    expect($application->site_mount_path)->toBeNull()
        ->and($supervisor->generated($application, '/home/shop/shop/public_html'))
        ->toBe(file_get_contents(base_path('tests/Fixtures/docker/legacy-compose.yml')));

    // Every optional block switched on, so the guard is not only about the
    // smallest file.
    $application->forceFill([
        'volume_mounts' => [['volume' => 'shop-db', 'path' => '/var/lib/mysql']],
        'docker_network' => 'shop-net',
        'cpu_limit' => '1.5',
        'memory_limit' => '1g',
    ])->save();

    expect($supervisor->generated($application->fresh(), '/home/shop/shop/public_html'))
        ->toBe(file_get_contents(base_path('tests/Fixtures/docker/legacy-compose-wired.yml')));
});

it('never mounts a new site over /app', function () {
    [$ops, $files] = containerDeps();
    $supervisor = new ContainerSupervisor($ops, $files, new ComposeValidator($ops), new RegistryAuth($ops));

    $application = containerApp();
    $application->forceFill([
        'site_mount_path' => ContainerMountPath::SITE_MOUNT,
        // Gotify keeps its data here. Refused before the fix; a volume now.
        'volume_mounts' => [['volume' => 'gotify-data', 'path' => '/app/data']],
    ])->save();

    $written = $supervisor->generated($application->fresh(), '/home/shop/shop/public_html');

    expect($written)->toContain('- /home/shop/shop/public_html:/panel-site')
        ->and($written)->not->toContain('public_html:/app')
        // The only `/app` left is the user's own volume, under it.
        ->and(substr_count($written, ':/app'))->toBe(1)
        ->and($written)->toContain('- gotify-data:/app/data');
});

it('leaves a pasted compose file alone, whatever the site mount', function () {
    // Pasted-compose mode never had the site mount, and must not gain one.
    $ran = [];
    $written = null;
    [$ops, $files] = containerDeps([
        'compose_validate' => fn () => new ServerOpsResult(
            ok: true, reference: 'r', result: processResult(json_encode([
                'services' => ['web' => [
                    'image' => 'nginx:1.27-alpine',
                    'ports' => [['published' => '20001', 'target' => 80, 'host_ip' => '127.0.0.1']],
                ]],
            ])), answered: true,
        ),
        'compose_ps' => fn () => new ServerOpsResult(ok: true, reference: 'r', result: processResult("abc\n"), answered: true),
    ], $ran, $written);

    $application = containerApp();
    $application->forceFill([
        'site_mount_path' => ContainerMountPath::SITE_MOUNT,
        'compose' => "services:\n  web:\n    image: nginx:1.27-alpine\n    ports:\n      - \"20001:80\"\n",
    ])->save();

    (new ContainerSupervisor($ops, $files, new ComposeValidator($ops), new RegistryAuth($ops)))
        ->apply($application->fresh(), '/home/shop/shop/public_html');

    expect($written)->not->toContain('/panel-site')
        ->and($written)->not->toContain(':/app');
});

it('refuses to render a compose file from a value with a line break in it (DS-08)', function (array $attributes) {
    // Every field is validated where it is accepted; this is the check every
    // field passes through on the way into the file, so a field added later —
    // or a row written by something other than a request — cannot reopen it.
    $ran = [];
    $written = null;
    [$ops, $files] = containerDeps([], $ran, $written);

    $application = containerApp();
    $application->forceFill($attributes)->save();

    expect(fn () => (new ContainerSupervisor($ops, $files, new ComposeValidator($ops), new RegistryAuth($ops)))
        ->generated($application->fresh(), '/home/shop/shop/public_html'))
        ->toThrow(ProvisioningFailedException::class);
})->with([
    'mount path' => [['volume_mounts' => [['volume' => 'shop-db', 'path' => "/data\n      - /:/hostfs:rw\n    privileged: true"]]]],
    'second mount' => [['volume_mounts' => [['volume' => 'a', 'path' => '/a'], ['volume' => "b\n    privileged: true", 'path' => '/b']]]],
    'image' => [['image' => "nginx\n    privileged: true"]],
    'memory limit' => [['memory_limit' => "512m\n    privileged: true"]],
]);

describe('a one-click app\'s limits changed after install (F1)', function () {
    // Found in Docker QA on a real box: NocoDB saved at 768m kept running at 1 GiB.
    // The recipe's file carries the limit of install day, and the broad override
    // is switched off for one-click apps (it capped their database at the app's
    // number). So a narrow one names the app service alone.

    /**
     * Apply a Ghost one-click whose installed file says ghost 640m / 0.5 CPU and
     * db 512m, with the site's limits set to $memory / $cpus. Returns the
     * override written ('' for none) and the commands run.
     *
     * @param  array<string, mixed>  $ghost  extra resolved keys for the ghost service.
     * @return array{0: string, 1: array<int, mixed>}
     */
    function f1Apply(?string $memory, ?string $cpus, array $ghost = ['mem_limit' => '671088640', 'cpus' => 0.5]): array
    {
        $ran = [];
        $written = null;
        [$ops, $files] = containerDeps([
            'compose_validate' => fn () => new ServerOpsResult(
                ok: true, reference: 'r', result: processResult(json_encode([
                    'services' => [
                        'ghost' => ['image' => 'ghost:5-alpine', 'ports' => [['published' => '20001', 'target' => 2368, 'host_ip' => '127.0.0.1']]] + $ghost,
                        'db' => ['image' => 'mysql:8.4', 'mem_limit' => '536870912'],
                    ],
                ])), answered: true,
            ),
            'compose_ps' => fn () => new ServerOpsResult(ok: true, reference: 'r', result: processResult("abc\n"), answered: true),
        ], $ran, $written);

        overrideWritten('');
        overrideDeleted(false);

        $application = containerApp();
        $application->forceFill([
            'site_type' => 'ghost',
            'compose' => "services:\n  ghost:\n    image: ghost:5-alpine\n  db:\n    image: mysql:8.4\n",
            'memory_limit' => $memory,
            'cpu_limit' => $cpus,
        ])->save();

        (new ContainerSupervisor($ops, $files, new ComposeValidator($ops), new RegistryAuth($ops)))
            ->apply($application, '/home/shop/shop/public_html');

        return [overrideWritten(), $ran];
    }

    it('raises the app service to the new limit, and touches nothing else', function () {
        [$override, $ran] = f1Apply('1g', '0.5');

        // Replaced, not capped: a pasted file's ceiling would have kept 640m here.
        expect($override)->toBe(implode("\n", [
            '# Written by the panel. Merged over compose.yml by `docker compose -f`.',
            "# The app service only: the site's own limits, changed after install.",
            'services:',
            '  ghost:',
            '    mem_limit: 1g',
        ])."\n")
            ->and($override)->not->toContain('db:')
            ->and(collect($ran)->contains(fn (array $call) => $call['op'] === 'compose_up'
                && in_array('/home/shop/shop/public_html/compose.panel.yml', $call['command'], true)))->toBeTrue();
    });

    it('lowers it too', function () {
        [$override] = f1Apply('384m', '0.5');

        expect($override)->toContain("  ghost:\n    mem_limit: 384m\n")->not->toContain('db:');
    });

    it('applies a changed CPU quota on its own', function () {
        [$override] = f1Apply('640m', '1.5');

        expect($override)->toContain("  ghost:\n    cpus: 1.5\n")->not->toContain('mem_limit');
    });

    it('uses the deploy spelling when the recipe\'s app service does', function () {
        [$override] = f1Apply('1g', null, ['deploy' => ['resources' => ['limits' => ['memory' => '671088640']]]]);

        expect($override)->toContain("          memory: \"1g\"\n")->not->toContain('mem_limit');
    });

    it('writes both spellings, one value, when the app service uses both', function () {
        // Compose refuses two different values for the pair (RC-42); one value in
        // both places is accepted.
        [$override] = f1Apply('1g', null, ['mem_limit' => '671088640', 'deploy' => ['resources' => ['limits' => ['memory' => '671088640']]]]);

        expect($override)->toContain("    mem_limit: 1g\n")->toContain("          memory: \"1g\"\n");
    });

    it('leaves a hand-edited recipe file as the user wrote it', function () {
        // The override is a second `-f`; the user's compose.yml is not rewritten.
        $ran = [];
        $written = null;
        [$ops, $files] = containerDeps([
            'compose_validate' => fn () => new ServerOpsResult(ok: true, reference: 'r', result: processResult(json_encode([
                'services' => [
                    'ghost' => ['image' => 'ghost:5-alpine', 'mem_limit' => '671088640', 'environment' => ['EXTRA' => 'mine'], 'ports' => [['published' => '20001', 'target' => 2368, 'host_ip' => '127.0.0.1']]],
                    'db' => ['image' => 'mysql:8.4', 'mem_limit' => '536870912'],
                ],
            ])), answered: true),
            'compose_ps' => fn () => new ServerOpsResult(ok: true, reference: 'r', result: processResult("abc\n"), answered: true),
        ], $ran, $written);
        overrideWritten('');

        $edited = "services:\n  ghost:\n    image: ghost:5-alpine\n    environment:\n      EXTRA: mine\n  db:\n    image: mysql:8.4\n";
        $application = containerApp();
        $application->forceFill(['site_type' => 'ghost', 'compose' => $edited, 'memory_limit' => '1g'])->save();

        (new ContainerSupervisor($ops, $files, new ComposeValidator($ops), new RegistryAuth($ops)))
            ->apply($application, '/home/shop/shop/public_html');

        expect($written)->toContain('EXTRA: mine')
            ->and(overrideWritten())->toContain("  ghost:\n    mem_limit: 1g\n")->not->toContain('EXTRA');
    });

    it('writes nothing when the limits are what the file already says', function () {
        expect(f1Apply('640m', '0.5')[0])->toBe('')
            ->and(overrideDeleted())->toBeTrue();
    });

    it('writes nothing when the site has no limit of its own', function () {
        expect(f1Apply(null, null)[0])->toBe('');
    });
});

it('ships every recipe with exactly one app service, which a limit change targets', function () {
    foreach (app(RecipeRegistry::class)->all() as $recipe) {
        $apps = array_filter($recipe->services, fn (array $s): bool => ($s['role'] ?? null) === 'app');

        expect($apps)->toHaveCount(1, "{$recipe->slug} must mark exactly one service as role: app");
    }
});

describe('a container left on no network after a failed start', function () {
    // Found in Docker QA on a real box: a port clash made `compose up` fail while
    // Docker wired the container's network, and Docker left Ghost attached to
    // nothing. Every later `up` just started that same container — no port, no
    // route to its database — while MySQL kept running, so a resume said 200 and
    // the site was 502. Pull and redeploy refuses a stopped container.

    /**
     * @param  string  $networks  `docker inspect` lines: "<service> <network count>".
     * @return array{0: list<array{op: string, command: list<string>}>, 1: ?Throwable}
     */
    function detachedRun(string $networks, bool $idsAnswered = true, ?Closure $recreate = null): array
    {
        $ran = [];
        $written = null;
        [$ops, $files] = containerDeps([
            'container_ids' => fn () => new ServerOpsResult(ok: $idsAnswered, reference: 'r', result: processResult("4f1c2d3e4b5a\n9a8b7c6d5e4f\n"), answered: $idsAnswered),
            'container_networks' => fn () => new ServerOpsResult(ok: true, reference: 'r', result: processResult($networks), answered: true),
            'compose_recreate_detached' => $recreate ?? fn () => new ServerOpsResult(ok: true, reference: 'r', answered: true),
            'compose_ps' => fn () => new ServerOpsResult(ok: true, reference: 'r', result: processResult("abc\n"), answered: true),
        ], $ran, $written);

        $error = null;

        try {
            (new ContainerSupervisor($ops, $files, new ComposeValidator($ops), new RegistryAuth($ops)))
                ->apply(containerApp(), '/home/shop/shop/public_html');
        } catch (Throwable $e) {
            $error = $e;
        }

        return [$ran, $error];
    }

    function opsRun(array $ran): array
    {
        return array_column($ran, 'op');
    }

    it('recreates only the detached service, before the normal up', function () {
        [$ran] = detachedRun("ghost 0\ndb 1\n");

        $recreate = collect($ran)->firstWhere('op', 'compose_recreate_detached');
        $ops = opsRun($ran);

        expect($recreate)->not->toBeNull()
            ->and(array_slice($recreate['command'], -5))->toBe(['up', '-d', '--no-deps', '--force-recreate', 'ghost'])
            ->and(array_search('compose_recreate_detached', $ops, true))->toBeLessThan(array_search('compose_up', $ops, true));
    });

    it('recreates nothing in a healthy project', function () {
        [$ran] = detachedRun("ghost 1\ndb 1\n");

        expect(opsRun($ran))->not->toContain('compose_recreate_detached')->toContain('compose_up');
    });

    it('recreates nothing when Docker could not be asked', function () {
        // No evidence is not evidence of breakage.
        [$ran] = detachedRun("ghost 0\n", idsAnswered: false);

        expect(opsRun($ran))->not->toContain('container_networks')->not->toContain('compose_recreate_detached');
    });

    it('stops with a named reason when the repair itself cannot start', function () {
        // Still taken: the repair fails exactly the way the start would, and says so.
        [$ran, $error] = detachedRun("ghost 0\ndb 1\n", recreate: fn () => new ServerOpsResult(
            ok: false, reference: 'rec-ref',
            result: Process::result(output: '', errorOutput: 'Error response from daemon: failed to set up container networking: driver failed programming external connectivity on endpoint sv-app-1-ghost-1 (x): failed to bind host port 127.0.0.1:3000/tcp: address already in use', exitCode: 1),
            answered: true,
        ));

        expect($error)->toBeInstanceOf(ProvisioningFailedException::class)
            ->and($error->step)->toBe('container_start')
            ->and($error->reason)->toBe('container_port_in_use')
            ->and(opsRun($ran))->not->toContain('compose_up');
    });

    it('never passes something that is not a service name to compose', function () {
        [$ran] = detachedRun("ghost 0\n--rm 0\n\$(id) 0\n");

        $recreate = collect($ran)->firstWhere('op', 'compose_recreate_detached');

        expect(array_slice($recreate['command'], -5))->toBe(['up', '-d', '--no-deps', '--force-recreate', 'ghost']);
    });
});
