<?php

use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\Role;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\HostCpus;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/**
 * The container settings endpoint — and specifically, whether saving one
 * changes the box.
 *
 * These values exist only as inputs to the compose file. A save that writes the
 * row and stops leaves the panel showing a network the container is not on,
 * until some unrelated deploy happens to rewrite the file. That is the failure
 * these cover: not "did the column change" but "did the compose file change and
 * did compose run".
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();

    ServerCapability::create([
        'stack' => 'docker',
        'web_server' => 'nginx',
        'capabilities' => ['php' => true, 'node' => false, 'serving_profiles' => ['docker']],
        'source' => 'installer',
        'verified_at' => now(),
    ]);

    $this->systemUser = SystemUser::create(['username' => 'ghost', 'home_path' => '/home/ghost']);

    $this->application = Application::forceCreate([
        'system_user_id' => $this->systemUser->id,
        'name' => 'Ghost',
        'slug' => 'ghost',
        'domain' => 'ghost.test',
        'site_type' => 'docker',
        'serving_profile' => 'docker',
        'status' => 'active',
        'web_root' => 'public_html',
        'image' => 'ghost:5',
        'container_port' => 2368,
        'app_port' => 20001,
    ]);
});

function containerRecorder(): ArrayObject
{
    static $bag = null;

    return $bag ??= new ArrayObject;
}

/**
 * Faked per command, and statefully for `network ls`.
 *
 * A blanket `Process::fake()` would answer `docker network ls` with empty
 * output, so the validation rule would report every network missing and the
 * test would be asserting against a box the fake broke.
 *
 * @param  list<string>  $networks  what `docker network ls` reports.
 */
function fakeDockerBox(array $networks = ['ghost-net']): void
{
    containerRecorder()->exchangeArray([]);

    Process::fake(function ($process) use ($networks) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        containerRecorder()->append($args);

        if (($args[1] ?? '') === 'network' && ($args[2] ?? '') === 'ls') {
            return Process::result(output: implode("\n", array_map(
                fn (string $name): string => json_encode([
                    'ID' => substr(md5($name), 0, 12), 'Name' => $name,
                    'Driver' => 'bridge', 'Scope' => 'local', 'Internal' => 'false',
                ]),
                $networks,
            )));
        }

        // `compose ps -q` is how the supervisor decides the container came up.
        // Empty output there reads as "exited" and throws.
        if (($args[1] ?? '') === 'compose') {
            return Process::result(output: "abc123\n");
        }

        return Process::result(exitCode: 0);
    });
}

function containerUrl(): string
{
    return '/api/applications/'.test()->application->id.'/container';
}

function containerHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->admin->createToken('t')->plainTextToken];
}

/** Did a command run with these arguments, ignoring any `sudo` wrapper? */
function dockerRan(callable $matches): bool
{
    foreach (containerRecorder() as $args) {
        if ($matches($args)) {
            return true;
        }
    }

    return false;
}

it('saves the network and brings the container up on it', function () {
    fakeDockerBox();

    $this->withHeaders(containerHeaders())
        ->putJson(containerUrl(), ['docker_network' => 'ghost-net'])
        ->assertOk()
        ->assertJsonPath('application.docker_network', 'ghost-net');

    expect($this->application->fresh()->docker_network)->toBe('ghost-net');

    // The half that a column assertion cannot see: a save that did not run
    // compose is a panel showing a network the container is not on.
    expect(dockerRan(fn (array $args): bool => ($args[1] ?? '') === 'compose' && in_array('up', $args, true)))
        ->toBeTrue();
});

it('refuses a network that is not on this server', function () {
    // `external: true` makes Compose look the name up, so a name that is not
    // there is a container that will not start — and the refusal has to arrive
    // on the field, not on the next deploy.
    fakeDockerBox(['ghost-net']);

    $this->withHeaders(containerHeaders())
        ->putJson(containerUrl(), ['docker_network' => 'not-there'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('docker_network');

    expect($this->application->fresh()->docker_network)->toBeNull();
    expect(dockerRan(fn (array $args): bool => ($args[1] ?? '') === 'compose'))->toBeFalse();
});

it('refuses a network name that could not be one', function () {
    fakeDockerBox();

    $this->withHeaders(containerHeaders())
        ->putJson(containerUrl(), ['docker_network' => '-rm --volumes'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('docker_network');

    // Refused on shape alone: a hostile value must not reach `docker`.
    expect(dockerRan(fn (array $args): bool => ($args[1] ?? '') === 'network'))->toBeFalse();
});

it('clears the network when asked, and rewrites the file', function () {
    // Null is a real answer — Docker's default bridge — not a missing one.
    fakeDockerBox();
    $this->application->forceFill(['docker_network' => 'ghost-net'])->save();

    $this->withHeaders(containerHeaders())
        ->putJson(containerUrl(), ['docker_network' => null])
        ->assertOk();

    expect($this->application->fresh()->docker_network)->toBeNull()
        ->and(dockerRan(fn (array $args): bool => ($args[1] ?? '') === 'compose' && in_array('up', $args, true)))
        ->toBeTrue();
});

it('is refused for a site that is not a container', function () {
    // The fields have no meaning for a PHP site, and storing them would look
    // exactly like a feature that works.
    //
    // **404, not 422, since these routes moved onto `app_container`.**
    // `CheckPermission` refuses an `app_*` permission the site's type does not
    // support, and its own comment gives the reason: for this site the screen does
    // not exist at all, which is a different statement from "you may not". That is
    // a better answer than the controller's 422 and it arrives earlier, so the
    // controller's own check is now belt-and-braces rather than the gate.
    fakeDockerBox();
    $this->application->forceFill(['site_type' => 'php', 'serving_profile' => 'php'])->save();

    $this->withHeaders(containerHeaders())
        ->putJson(containerUrl(), ['docker_network' => 'ghost-net'])
        ->assertStatus(404);

    expect($this->application->fresh()->docker_network)->toBeNull();
});

it('stores without touching the box when the site is not provisioned', function () {
    // Nothing on disk to rewrite for a pending site; running compose against
    // one would be provisioning it as a side effect of saving a form.
    fakeDockerBox();
    $this->application->forceFill(['status' => 'pending'])->save();

    $this->withHeaders(containerHeaders())
        ->putJson(containerUrl(), ['docker_network' => 'ghost-net'])
        ->assertOk();

    expect($this->application->fresh()->docker_network)->toBe('ghost-net')
        ->and(dockerRan(fn (array $args): bool => ($args[1] ?? '') === 'compose'))->toBeFalse();
});

it('does not resurrect a disabled site', function () {
    // A disabled site's vhost deliberately points at the disabled page.
    // Bringing its container up here would put it back online.
    fakeDockerBox();
    $this->application->forceFill(['disabled_at' => now()])->save();

    $this->withHeaders(containerHeaders())
        ->putJson(containerUrl(), ['docker_network' => 'ghost-net'])
        ->assertOk();

    expect(dockerRan(fn (array $args): bool => ($args[1] ?? '') === 'compose'))->toBeFalse();
});

it('is refused without the manage permission', function () {
    fakeDockerBox();

    $viewer = User::factory()->create();
    $viewer->roles()->attach(Role::create(['name' => 'Viewer', 'slug' => 'viewer']));

    $this->withHeaders(['Authorization' => 'Bearer '.$viewer->createToken('t')->plainTextToken])
        ->putJson(containerUrl(), ['docker_network' => 'ghost-net'])
        ->assertForbidden();

    expect($this->application->fresh()->docker_network)->toBeNull();
});

/*
 * Mounting a volume into the site.
 *
 * The validation here is not paperwork. `/app` is where the generated compose
 * bind-mounts the site's own directory, and a volume over it hides those files
 * from the container while leaving them on disk and in the backup — which looks
 * exactly like deletion and invites a restore that changes nothing.
 */

/** Extends the box fake with a volume list. */
function fakeDockerBoxWithVolumes(array $volumes = ['shop-db']): void
{
    containerRecorder()->exchangeArray([]);

    Process::fake(function ($process) use ($volumes) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        containerRecorder()->append($args);

        if (($args[1] ?? '') === 'system' && ($args[2] ?? '') === 'df') {
            return Process::result(output: json_encode(array_map(
                fn (string $name): array => [
                    'Name' => $name, 'Driver' => 'local',
                    'Mountpoint' => "/var/lib/docker/volumes/{$name}/_data",
                    'Size' => '10MB', 'Links' => '0',
                ],
                $volumes,
            )));
        }

        if (($args[1] ?? '') === 'ps' && in_array('-aq', $args, true)) {
            return Process::result(output: '');
        }

        if (($args[1] ?? '') === 'compose') {
            return Process::result(output: "abc123\n");
        }

        return Process::result(exitCode: 0);
    });
}

it('mounts a volume and recreates the container', function () {
    fakeDockerBoxWithVolumes();

    $this->withHeaders(containerHeaders())
        ->putJson(containerUrl(), ['volume_mounts' => [
            ['volume' => 'shop-db', 'path' => '/var/lib/mysql'],
        ]])
        ->assertOk()
        ->assertJsonPath('application.volume_mounts.0.volume', 'shop-db');

    expect($this->application->fresh()->volume_mounts)
        ->toBe([['volume' => 'shop-db', 'path' => '/var/lib/mysql']])
        ->and(dockerRan(fn (array $args): bool => ($args[1] ?? '') === 'compose' && in_array('up', $args, true)))
        ->toBeTrue();
});

it('refuses a mount over the site\'s own files', function () {
    // The dangerous one. The files stay on the server and in the backup, and the
    // container serves an empty volume — indistinguishable from deletion.
    fakeDockerBoxWithVolumes();

    foreach (['/app', '/app/', '/app/public', '/app/storage/uploads'] as $path) {
        $this->withHeaders(containerHeaders())
            ->putJson(containerUrl(), ['volume_mounts' => [['volume' => 'shop-db', 'path' => $path]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('volume_mounts.0.path');
    }

    expect($this->application->fresh()->volume_mounts)->toBeNull();
});

it('lets a site created since the fix keep data under /app', function () {
    // Gotify's `/app/data`. `/app` is no longer where a new site's own files are,
    // so refusing it would refuse a real image's only data directory.
    fakeDockerBoxWithVolumes();
    $this->application->forceFill(['site_mount_path' => '/panel-site'])->save();

    foreach (['/app', '/app/data'] as $path) {
        $this->withHeaders(containerHeaders())
            ->putJson(containerUrl(), ['volume_mounts' => [['volume' => 'shop-db', 'path' => $path]]])
            ->assertOk()
            ->assertJsonPath('application.site_mount_path', '/panel-site');
    }
});

it('refuses a mount over a new site\'s own files, at their new path', function () {
    fakeDockerBoxWithVolumes();
    $this->application->forceFill(['site_mount_path' => '/panel-site'])->save();

    foreach (['/panel-site', '/panel-site/', '/panel-site/uploads', '/etc', '/'] as $path) {
        $this->withHeaders(containerHeaders())
            ->putJson(containerUrl(), ['volume_mounts' => [['volume' => 'shop-db', 'path' => $path]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('volume_mounts.0.path');
    }

    expect($this->application->fresh()->volume_mounts)->toBeNull();
});

it('names the site mount of an existing site as /app', function () {
    // Null in the column, `/app` in the answer — what its compose file says.
    $this->withHeaders(containerHeaders())
        ->getJson('/api/applications/'.$this->application->id)
        ->assertOk()
        ->assertJsonPath('application.site_mount_path', '/app');
});

it('refuses a mount over the image itself', function () {
    fakeDockerBoxWithVolumes();

    foreach (['/', '/etc', '/usr', '/bin', '/lib'] as $path) {
        $this->withHeaders(containerHeaders())
            ->putJson(containerUrl(), ['volume_mounts' => [['volume' => 'shop-db', 'path' => $path]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('volume_mounts.0.path');
    }
});

it('refuses a relative path and a traversal', function () {
    fakeDockerBoxWithVolumes();

    foreach (['var/lib/mysql', '/var/../etc', '/var/lib/..'] as $path) {
        $this->withHeaders(containerHeaders())
            ->putJson(containerUrl(), ['volume_mounts' => [['volume' => 'shop-db', 'path' => $path]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('volume_mounts.0.path');
    }
});

it('refuses two volumes at the same path', function () {
    // Docker keeps one and discards the other without saying which, so the site
    // would be missing a volume it is configured to have.
    fakeDockerBoxWithVolumes(['shop-db', 'shop-other']);

    $this->withHeaders(containerHeaders())
        ->putJson(containerUrl(), ['volume_mounts' => [
            ['volume' => 'shop-db', 'path' => '/data'],
            ['volume' => 'shop-other', 'path' => '/data/'],
        ]])
        ->assertStatus(422)
        ->assertJsonValidationErrors('volume_mounts.1.path');
});

it('allows one volume at two different paths', function () {
    // Legal Docker, and the top-level declaration is keyed so it appears once.
    fakeDockerBoxWithVolumes(['shared']);

    $this->withHeaders(containerHeaders())
        ->putJson(containerUrl(), ['volume_mounts' => [
            ['volume' => 'shared', 'path' => '/one'],
            ['volume' => 'shared', 'path' => '/two'],
        ]])
        ->assertOk();

    expect($this->application->fresh()->volume_mounts)->toHaveCount(2);
});

it('refuses a volume that is not on this server', function () {
    fakeDockerBoxWithVolumes(['shop-db']);

    $this->withHeaders(containerHeaders())
        ->putJson(containerUrl(), ['volume_mounts' => [
            ['volume' => 'not-there', 'path' => '/data'],
        ]])
        ->assertStatus(422)
        ->assertJsonValidationErrors('volume_mounts.0.volume');
});

it('clears every mount when given an empty list', function () {
    fakeDockerBoxWithVolumes();
    $this->application->forceFill(['volume_mounts' => [['volume' => 'shop-db', 'path' => '/data']]])->save();

    $this->withHeaders(containerHeaders())
        ->putJson(containerUrl(), ['volume_mounts' => []])
        ->assertOk();

    expect($this->application->fresh()->volume_mounts)->toBe([]);
});

/*
 * The generated credentials.
 *
 * These are live database passwords. The shape of the feature is the security
 * argument: they are not on the application payload, asking for them is recorded,
 * and the endpoint is gated on `manage` rather than `view` because reading a
 * password is not a read-only act in any sense that matters.
 */

it('does not put the credentials in the application payload', function () {
    // The whole reason for a separate endpoint. On the resource they would ride in
    // every application response, every list, and every cache in between.
    fakeDockerBox();
    $this->application->forceFill(['docker_secrets' => ['MYSQL_ROOT_PASSWORD' => 'supersecretvalue']])->save();

    // The APPLICATION payload is what must not carry it. The secrets endpoint
    // obviously does — that is its job, and asserting otherwise was this test
    // being wrong rather than the code.
    $body = $this->withHeaders(containerHeaders())
        ->getJson('/api/applications/'.$this->application->id)
        ->assertOk()
        ->content();

    expect($body)->not->toContain('supersecretvalue')
        // But the NAMES are there, so the UI knows what to offer without asking.
        ->and($body)->toContain('MYSQL_ROOT_PASSWORD');
});

it('returns the credentials from their own endpoint', function () {
    fakeDockerBox();
    $this->application->forceFill(['docker_secrets' => [
        'MYSQL_ROOT_PASSWORD' => 'rootvalue', 'GHOST_DB_PASSWORD' => 'appvalue',
    ]])->save();

    $this->withHeaders(containerHeaders())
        ->getJson(containerUrl2())
        ->assertOk()
        ->assertJsonPath('secrets.MYSQL_ROOT_PASSWORD', 'rootvalue')
        ->assertJsonPath('secrets.GHOST_DB_PASSWORD', 'appvalue');
});

it('records who looked', function () {
    // An audit trail is most of the value: a password that can be read without a
    // trace is a password nobody can reason about after an incident.
    fakeDockerBox();
    $this->application->forceFill(['docker_secrets' => ['MYSQL_ROOT_PASSWORD' => 'v']])->save();

    $this->withHeaders(containerHeaders())->getJson(containerUrl2())->assertOk();

    expect(ActivityLog::where('action', 'container_secrets_viewed')->exists())
        ->toBeTrue('nothing recorded the read');
});

it('refuses a user without manage', function () {
    // `manage`, not `view`. Somebody who may look at a site must not thereby be
    // able to read its database password.
    fakeDockerBox();
    $this->application->forceFill(['docker_secrets' => ['MYSQL_ROOT_PASSWORD' => 'v']])->save();

    $viewer = User::factory()->create();
    $viewer->roles()->attach(Role::create(['name' => 'Viewer', 'slug' => 'viewer']));

    $this->withHeaders(['Authorization' => 'Bearer '.$viewer->createToken('t')->plainTextToken])
        ->getJson(containerUrl2())
        ->assertForbidden();
});

it('refuses a site that is not a container', function () {
    // 404 rather than 422 — see the note on the settings endpoint above.
    fakeDockerBox();
    $this->application->forceFill(['site_type' => 'php', 'serving_profile' => 'php'])->save();

    $this->withHeaders(containerHeaders())->getJson(containerUrl2())->assertStatus(404);
});

it('answers with an empty set rather than failing when there are none', function () {
    // A plain Docker site has no generated credentials, and that is not an error.
    fakeDockerBox();

    $this->withHeaders(containerHeaders())
        ->getJson(containerUrl2())
        ->assertOk()
        ->assertExactJson(['secrets' => []]);
});

function containerUrl2(): string
{
    return '/api/applications/'.test()->application->id.'/container/secrets';
}

/*
 * The CPU quota, and the one thing it has to get right: refusing at the form.
 *
 * Docker does refuse an over-provisioned quota — measured on the test box, both
 * `docker run --cpus 16` and `cpus: 16` in a compose file answer "range of CPUs is
 * from 0.01 to 4.00, as there are only 4 CPUs available". It refuses at `up`,
 * though, which means without the rule this field saves the row, fails the apply,
 * and leaves the panel displaying a limit the container does not have. The site
 * itself survives — compose refuses before removing the running container — so the
 * only trace is a failure card.
 */

/** A box with a known number of CPUs, so the bound is not the runner's. */
function withCpus(int $cores): void
{
    app()->instance(HostCpus::class, new class($cores) extends HostCpus
    {
        public function __construct(private int $cores) {}

        public function count(): int
        {
            return $this->cores;
        }
    });
}

it('saves a cpu quota and applies it to the container', function () {
    fakeDockerBox();
    withCpus(4);

    $this->withHeaders(containerHeaders())
        ->putJson(containerUrl(), ['cpu_limit' => '1.5'])
        ->assertOk()
        ->assertJsonPath('application.cpu_limit', '1.5');

    expect($this->application->fresh()->cpu_limit)->toBe('1.5')
        ->and(dockerRan(fn (array $args): bool => ($args[1] ?? '') === 'compose' && in_array('up', $args, true)))
        ->toBeTrue();
});

it('refuses more CPUs than the server has, and saves nothing', function () {
    fakeDockerBox();
    withCpus(2);

    $this->withHeaders(containerHeaders())
        ->putJson(containerUrl(), ['cpu_limit' => '8'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('cpu_limit');

    expect($this->application->fresh()->cpu_limit)->toBeNull();
});

it('names what the server has rather than saying invalid', function () {
    // The whole reason this is a rule and not a `numeric|max:` — "must not be
    // greater than 2" does not tell somebody what their own box is.
    fakeDockerBox();
    withCpus(2);

    $message = $this->withHeaders(containerHeaders())
        ->putJson(containerUrl(), ['cpu_limit' => '8'])
        ->assertStatus(422)
        ->json('errors.cpu_limit.0');

    expect($message)->toContain('2 CPUs');
});

it('refuses a quota below the one Docker accepts', function () {
    // `cpus: 0` is Docker's own spelling of "no limit", so a zero typed into the
    // field would be a limit that removes limits. Its floor is 0.01.
    fakeDockerBox();
    withCpus(4);

    foreach (['0', '0.001', '-1', 'one', '1.555'] as $bad) {
        $this->withHeaders(containerHeaders())
            ->putJson(containerUrl(), ['cpu_limit' => $bad])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cpu_limit');
    }

    expect($this->application->fresh()->cpu_limit)->toBeNull();
});

it('clears the quota when the field is emptied', function () {
    // Null is the answer that means "no limit", and it has to be reachable —
    // otherwise a limit once set can never be removed except by deleting the site.
    fakeDockerBox();
    withCpus(4);
    $this->application->forceFill(['cpu_limit' => '2'])->save();

    $this->withHeaders(containerHeaders())
        ->putJson(containerUrl(), ['cpu_limit' => null])
        ->assertOk();

    expect($this->application->fresh()->cpu_limit)->toBeNull();
});

it('refuses a memory ceiling Docker would not start the container with', function () {
    // 6MB is Docker's floor, measured: "Minimum memory limit allowed is 6MB". And
    // a bare number is bytes, not megabytes — the mistake that used to save fine
    // and produce a container that would not start.
    fakeDockerBox();

    foreach (['2m', '512', '512MB'] as $bad) {
        $this->withHeaders(containerHeaders())
            ->putJson(containerUrl(), ['memory_limit' => $bad])
            ->assertStatus(422)
            ->assertJsonValidationErrors('memory_limit');
    }
});

it('records the size in the activity log', function () {
    // A site that was always slow and a site that was made smaller are different
    // events, and the log is where somebody will look to tell them apart.
    fakeDockerBox();
    withCpus(4);

    $this->withHeaders(containerHeaders())
        ->putJson(containerUrl(), ['cpu_limit' => '1', 'memory_limit' => '1g'])
        ->assertOk();

    $log = ActivityLog::where('type', 'application')->where('action', 'container_updated')->sole();

    expect($log->properties['cpu_limit'])->toBe('1')
        ->and($log->properties['memory_limit'])->toBe('1g');
});

it('tells the form how big the server is', function () {
    // So the field can state the ceiling instead of describing the rule. Its own
    // endpoint because `/basic-info` is unauthenticated, and the size of the box
    // is not for anonymous visitors.
    fakeDockerBox();
    withCpus(6);

    $this->withHeaders(containerHeaders())
        ->getJson('/api/docker/limits')
        ->assertOk()
        ->assertJsonPath('limits.cpus', 6)
        ->assertJsonPath('limits.default_memory_limit', (string) config('server.docker.default_memory_limit'));
});

/*
 * "I have saved these."
 *
 * A one-click app's admin password is generated by the panel, and until the
 * first-run card existed the only route to it was a Reveal button two clicks into
 * the site. A password generated and never read is an account nobody can sign into.
 *
 * The acknowledgement is its own endpoint rather than a side effect of reading the
 * values, and that is the whole design: these cannot be rotated from the panel —
 * changing one means rewriting the compose file AND the credential inside the
 * running database — so a card that vanished the moment it rendered would lose an
 * unrecoverable password to a stray page refresh.
 */

it('records that somebody saved the credentials', function () {
    fakeDockerBox();

    expect($this->application->credentials_seen_at)->toBeNull();

    $this->withHeaders(containerHeaders())
        ->postJson(containerUrl().'/secrets/acknowledge')
        ->assertOk()
        ->assertJsonPath('application.credentials_acknowledged', true);

    expect($this->application->fresh()->credentials_seen_at)->not->toBeNull();
});

it('does not move the date when acknowledged a second time', function () {
    // The interesting fact is when these stopped being unseen. Overwriting it on
    // every later click would erase the only answer worth having.
    fakeDockerBox();

    $this->withHeaders(containerHeaders())->postJson(containerUrl().'/secrets/acknowledge')->assertOk();
    $first = $this->application->fresh()->credentials_seen_at;

    $this->travel(2)->minutes();

    $this->withHeaders(containerHeaders())->postJson(containerUrl().'/secrets/acknowledge')->assertOk();

    expect($this->application->fresh()->credentials_seen_at->timestamp)->toBe($first->timestamp);
});

it('does not acknowledge as a side effect of reading the credentials', function () {
    // The load-bearing assertion of the whole feature. If rendering the card marked
    // it seen, a refresh mid-copy would hide an unrecoverable password for good.
    fakeDockerBox();
    $this->application->forceFill(['docker_secrets' => ['ADMIN_PASSWORD' => 'hunter2']])->save();

    $this->withHeaders(containerHeaders())->getJson(containerUrl().'/secrets')->assertOk();

    expect($this->application->fresh()->credentials_seen_at)->toBeNull();
});

it('refuses to acknowledge without the manage permission', function () {
    // Same gate as reading them: acknowledging is a statement about values you were
    // only allowed to see under `manage`.
    fakeDockerBox();

    $viewer = User::factory()->create();
    $viewer->roles()->attach(Role::create(['name' => 'Viewer', 'slug' => 'ack-viewer']));

    $this->withHeaders(['Authorization' => 'Bearer '.$viewer->createToken('t')->plainTextToken])
        ->postJson(containerUrl().'/secrets/acknowledge')
        ->assertForbidden();

    expect($this->application->fresh()->credentials_seen_at)->toBeNull();
});

it('refuses to acknowledge for a site that is not a container', function () {
    fakeDockerBox();
    $this->application->forceFill(['serving_profile' => 'php', 'site_type' => 'wordpress'])->save();

    $this->withHeaders(containerHeaders())
        ->postJson(containerUrl().'/secrets/acknowledge')
        ->assertStatus(404);
});

it('says a site has unacknowledged credentials in its payload', function () {
    // What puts the card on the dashboard. A flag about a PERSON, not about the
    // data: a site whose credentials were rendered and never confirmed still
    // answers false.
    fakeDockerBox();
    $this->application->forceFill(['docker_secrets' => ['ADMIN_PASSWORD' => 'hunter2']])->save();

    $payload = $this->withHeaders(containerHeaders())
        ->getJson('/api/applications/'.$this->application->id)
        ->assertOk();

    expect($payload->json('application.credentials_acknowledged'))->toBeFalse()
        ->and($payload->json('application.container_secret_keys'))->toBe(['ADMIN_PASSWORD'])
        // And never the value itself.
        ->and(json_encode($payload->json()))->not->toContain('hunter2');
});
