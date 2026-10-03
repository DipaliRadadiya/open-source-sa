<?php

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Models\Registry;
use App\Models\Role;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\Applications\ContainerSupervisor;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/**
 * The credential's life on disk, which is the whole security argument.
 *
 * A classifier nothing calls and a credential nothing cleans up both look exactly
 * like working code, so this file asserts the wiring rather than the units: what
 * reached argv, what reached stdin, where the file was written, and that it was
 * gone afterwards — including after a failure, which is when it matters.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();

    ServerCapability::query()->delete();
    ServerCapability::create([
        'stack' => 'docker', 'web_server' => 'nginx',
        'capabilities' => ['php' => true, 'node' => false, 'serving_profiles' => ['docker']],
        'source' => 'installer', 'verified_at' => now(),
    ]);

    $this->systemUser = SystemUser::create(['username' => 'priv', 'home_path' => '/home/priv']);

    $this->registry = Registry::forceCreate([
        'name' => 'GHCR', 'registry' => 'ghcr.io', 'username' => 'octocat',
        'config' => ['token' => 'ghp_SUPERSECRETVALUE'],
    ]);

    $this->application = Application::forceCreate([
        'system_user_id' => $this->systemUser->id,
        'name' => 'Private', 'slug' => 'priv', 'domain' => 'priv.test',
        'site_type' => 'docker', 'serving_profile' => 'docker', 'web_root' => 'public_html',
        'image' => 'ghcr.io/acme/private:1', 'container_port' => 3000, 'app_port' => 20001,
        'registry_id' => $this->registry->id,
        'status' => 'active',
    ]);
});

/**
 * Every command that ran, plus whatever was piped into each.
 *
 * Faked per command rather than blanket: `compose ps -q` deciding the container
 * came up has to answer something, and an empty answer reads as "exited" and
 * throws — asserting against a box the fake broke.
 *
 * @param  callable|null  $onUp  return a failure to make `compose up` fail.
 */
function recordPull(array &$ran, ?callable $onUp = null, string $failVerb = 'up'): void
{
    Process::fake(function ($process) use (&$ran, $onUp, $failVerb) {
        $args = $process->command;

        // `sudo -n` and `sudo -n env X=y` wrappers are ServerOps' business, not
        // this test's — strip them so assertions are about the real command.
        while (in_array($args[0] ?? '', ['sudo', '-n', 'env'], true) || str_contains($args[0] ?? '', '=')) {
            array_shift($args);
        }

        $ran[] = ['args' => $args, 'input' => $process->input];

        if (($args[0] ?? '') === 'docker' && in_array($failVerb, $args, true) && $onUp !== null) {
            return $onUp();
        }

        if (($args[0] ?? '') === 'docker' && in_array('ps', $args, true)) {
            return Process::result(output: "abc123\n");
        }

        return Process::result(exitCode: 0);
    });
}

/** The recorded command that ran `docker compose <verb>`, if any. */
function composeCall(array $ran, string $verb): ?array
{
    foreach ($ran as $call) {
        if (($call['args'][0] ?? '') === 'docker' && in_array('compose', $call['args'], true) && in_array($verb, $call['args'], true)) {
            return $call;
        }
    }

    return null;
}

/*
 * Where the secret goes, and where it does not.
 */

it('sends the token through stdin and the directory through argv', function () {
    $ran = [];
    recordPull($ran);

    app(ContainerSupervisor::class)->apply($this->application, '/home/priv/priv.test');

    // `tee` writes the compose file as well, so match the credential write by its
    // path — the first `tee` is the site's own file.
    $write = collect($ran)->first(fn (array $c): bool => ($c['args'][0] ?? '') === 'tee'
        && str_ends_with($c['args'][1] ?? '', '/config.json'));

    // Docker's own format: `auths.<key>.auth` is base64 of `user:password`. That
    // is an encoding, not an encryption — which is precisely why the file has to
    // live somewhere only root can read.
    $encoded = base64_encode('octocat:ghp_SUPERSECRETVALUE');

    expect($write)->not->toBeNull()
        ->and($write['input'])->toContain($encoded)
        ->and($write['args'][1])->toEndWith('/config.json');

    // And in no argument of anything that ran, in either form. argv is
    // world-readable in `ps` for the life of the process.
    $argv = json_encode(array_column($ran, 'args'));

    expect($argv)->not->toContain('ghp_SUPERSECRETVALUE')
        ->and($argv)->not->toContain($encoded);
});

it('writes the credential outside the site tree, where the site user cannot read it', function () {
    $ran = [];
    recordPull($ran);

    app(ContainerSupervisor::class)->apply($this->application, '/home/priv/priv.test');

    $write = collect($ran)->first(fn (array $c): bool => ($c['args'][0] ?? '') === 'tee'
        && str_ends_with($c['args'][1] ?? '', '/config.json'));

    // The site user owns their document root. A token there is a token handed to
    // the tenant — which is exactly what keeping them out of the `docker` group
    // was for.
    expect($write['args'][1])->not->toStartWith('/home/priv/');
});

it('creates the directory private in one step, not readable then tightened', function () {
    $ran = [];
    recordPull($ran);

    app(ContainerSupervisor::class)->apply($this->application, '/home/priv/priv.test');

    $mkdir = collect($ran)->first(fn (array $c): bool => ($c['args'][0] ?? '') === 'mkdir');

    // `mkdir -m 0700`, because `mkdir` then `chmod` is world-readable in between.
    expect($mkdir['args'])->toContain('-m')->toContain('0700');

    $chmod = collect($ran)->first(fn (array $c): bool => ($c['args'][0] ?? '') === 'chmod');

    expect($chmod['args'])->toContain('0600');
});

it('passes --config before the compose subcommand, not after', function () {
    $ran = [];
    recordPull($ran);

    app(ContainerSupervisor::class)->apply($this->application, '/home/priv/priv.test');

    $up = composeCall($ran, 'up');

    // `--config` belongs to the docker CLI. After `compose` the plugin reads it as
    // one of its own and the command fails.
    expect($up['args'][1])->toBe('--config')
        ->and($up['args'][3])->toBe('compose');
});

/*
 * Cleanup. The failure path is the one that matters.
 */

it('removes the credential after a successful pull', function () {
    $ran = [];
    recordPull($ran);

    app(ContainerSupervisor::class)->apply($this->application, '/home/priv/priv.test');

    // Matched on the DIRECTORY, not on "the first `rm`". The supervisor also
    // removes a stale `compose.panel.yml` with `rm -f`, and it runs first — so the
    // loose version of this assertion started reporting that command and failing on
    // `-rf`. Two unrelated cleanups sharing an argv prefix is a coincidence; the
    // path is what says which one this is.
    $removed = collect($ran)->first(fn (array $c): bool => ($c['args'][0] ?? '') === 'rm'
        && str_contains((string) ($c['args'][2] ?? ''), '/registry-'));

    expect($removed)->not->toBeNull()->and($removed['args'])->toContain('-rf');
});

it('removes the credential after a FAILED pull', function () {
    // The case a tidy-up at the end of the happy path would miss, and the likelier
    // outcome the first time somebody configures a registry.
    $ran = [];
    recordPull($ran, fn () => Process::result(
        output: '',
        errorOutput: 'Error response from daemon: error from registry: denied',
        exitCode: 18,
    ));

    try {
        app(ContainerSupervisor::class)->apply($this->application, '/home/priv/priv.test');
    } catch (ProvisioningFailedException) {
        // Expected — the assertion is about what happened on the way out.
    }

    expect(collect($ran)->first(fn (array $c): bool => ($c['args'][0] ?? '') === 'rm'))->not->toBeNull();
});

it('does not write a credential at all for a site with no registry', function () {
    // Every public image and all fifteen one-click apps take this branch, so it is
    // the regression that matters most: a `--config` flag pointing at a directory
    // that was never created would fail every existing site.
    $this->application->forceFill(['registry_id' => null])->save();

    $ran = [];
    recordPull($ran);

    app(ContainerSupervisor::class)->apply($this->application->fresh(), '/home/priv/priv.test');

    expect(collect($ran)->first(fn (array $c): bool => ($c['args'][0] ?? '') === 'tee' && str_ends_with($c['args'][1] ?? '', 'config.json')))->toBeNull()
        ->and(composeCall($ran, 'up')['args'])->not->toContain('--config');
});

it('does not write a credential for a registry row whose token is missing', function () {
    // A row that predates the token, or one restored under a different APP_KEY.
    // Writing an empty basic-auth header would turn an anonymous pull that works
    // into an authenticated one that is refused.
    $this->registry->forceFill(['config' => []])->save();

    $ran = [];
    recordPull($ran);

    app(ContainerSupervisor::class)->apply($this->application->fresh(), '/home/priv/priv.test');

    expect(composeCall($ran, 'up')['args'])->not->toContain('--config');
});

/*
 * Pull and redeploy: the update story, and the reason it cannot just be `up`.
 */

it('pulls before bringing it up, inside one credential window', function () {
    $ran = [];
    recordPull($ran);

    app(ContainerSupervisor::class)->pull($this->application, '/home/priv/priv.test');

    expect(composeCall($ran, 'pull'))->not->toBeNull()
        ->and(composeCall($ran, 'up'))->not->toBeNull()
        // Both authenticated.
        ->and(composeCall($ran, 'pull')['args'])->toContain('--config')
        ->and(composeCall($ran, 'up')['args'])->toContain('--config');

    // One window, not two: written once, removed once.
    $writes = collect($ran)->filter(fn (array $c): bool => ($c['args'][0] ?? '') === 'tee' && str_ends_with($c['args'][1] ?? '', 'config.json'));

    expect($writes)->toHaveCount(1);
});

it('names a rejected credential differently from a missing one', function () {
    // Measured wording, Docker Hub refusing a token that exists.
    $ran = [];
    recordPull($ran, fn () => Process::result(
        output: '',
        errorOutput: 'Error response from daemon: authentication required - incorrect username or password',
        exitCode: 18,
    ));

    try {
        app(ContainerSupervisor::class)->apply($this->application, '/home/priv/priv.test');
        $this->fail('apply() should have thrown');
    } catch (ProvisioningFailedException $e) {
        // Not `registry_auth`: that reason sends somebody to the Docker page to
        // add a credential they already added.
        expect($e->reason)->toBe('registry_credentials_rejected');
    }
});

it('has the rejected-credential reason translated in every locale', function () {
    foreach (['en', 'es', 'de', 'fr', 'pt', 'ja', 'ru', 'hi'] as $locale) {
        $line = __('application.failure_reason.registry_credentials_rejected', [], $locale);

        expect($line)->not->toBe('application.failure_reason.registry_credentials_rejected')
            ->and($line)->not->toBeEmpty();
    }
});

/*
 * The endpoint.
 */

it('refuses to pull for a site that is not a container', function () {
    $this->application->forceFill(['serving_profile' => 'php'])->save();

    $this->withHeaders(['Authorization' => 'Bearer '.$this->admin->createToken('t')->plainTextToken])
        ->postJson('/api/applications/'.$this->application->id.'/container/pull')
        ->assertStatus(422);
});

it('refuses to pull for a site that is not running', function () {
    // Otherwise pressing Update on a disabled site puts it back online, and on a
    // pending one provisions it as a side effect of a different button.
    $this->application->forceFill(['status' => 'pending'])->save();

    $ran = [];
    recordPull($ran);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->admin->createToken('t')->plainTextToken])
        ->postJson('/api/applications/'.$this->application->id.'/container/pull')
        ->assertStatus(422);
});

it('refuses to pull without application manage', function () {
    $viewer = User::factory()->create();
    $viewer->roles()->attach(Role::create(['name' => 'Viewer', 'slug' => 'viewer']));

    $this->withHeaders(['Authorization' => 'Bearer '.$viewer->createToken('t')->plainTextToken])
        ->postJson('/api/applications/'.$this->application->id.'/container/pull')
        ->assertForbidden();
});

it('pulls and reports the site back', function () {
    $ran = [];
    recordPull($ran);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->admin->createToken('t')->plainTextToken])
        ->postJson('/api/applications/'.$this->application->id.'/container/pull')
        ->assertOk()
        ->assertJsonPath('application.registry_id', $this->registry->id);

    expect(composeCall($ran, 'pull'))->not->toBeNull();
});

/*
 * The endpoint's failure shape. Found on a real box, not here: the classifier was
 * asserted from the supervisor and nothing checked that the ENDPOINT surfaced it,
 * so a rejected credential answered a bare 500 "Server Error" and left the row
 * claiming the site was fine — which is the uninformative failure this whole
 * feature exists to replace.
 */

it('answers 422 with the reason, not 500 with nothing', function () {
    $ran = [];
    recordPull($ran, fn () => Process::result(
        output: '',
        errorOutput: 'Error response from daemon: authentication required - incorrect username or password',
        exitCode: 18,
    ));

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$this->admin->createToken('t')->plainTextToken])
        ->postJson('/api/applications/'.$this->application->id.'/container/pull')
        ->assertStatus(422)
        ->assertJsonPath('reason', 'registry_credentials_rejected');

    // The titled reason, so the person who pressed the button is told what to fix
    // rather than being handed a log reference and a shrug.
    expect($response->json('message'))->toBe(__('application.failure_reason.registry_credentials_rejected'))
        ->and($response->json('reference'))->not->toBeEmpty();
});

it('records the failure on the row, so the card does not claim the site is fine', function () {
    // Failing `pull` rather than `up`, so the recorded STEP is the pull's own —
    // `container_start` would be the honest answer for a failure after the image
    // arrived, and the two are not interchangeable in a support conversation.
    $ran = [];
    recordPull($ran, fn () => Process::result(
        output: '',
        errorOutput: 'Error response from daemon: pull access denied for x/y, repository does not exist or may require \'docker login\'',
        exitCode: 18,
    ), failVerb: 'pull');

    $this->withHeaders(['Authorization' => 'Bearer '.$this->admin->createToken('t')->plainTextToken])
        ->postJson('/api/applications/'.$this->application->id.'/container/pull')
        ->assertStatus(422);

    expect($this->application->fresh()->failed_reason)->toBe('registry_auth')
        ->and($this->application->fresh()->failed_step)->toBe('image_pull');
});

it('clears a previous failure when a pull succeeds', function () {
    // Otherwise the row keeps showing yesterday's reason beside today's success.
    $this->application->forceFill(['failed_step' => 'image_pull', 'failed_reason' => 'registry_auth'])->save();

    $ran = [];
    recordPull($ran);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->admin->createToken('t')->plainTextToken])
        ->postJson('/api/applications/'.$this->application->id.'/container/pull')
        ->assertOk();

    expect($this->application->fresh()->failed_reason)->toBeNull()
        ->and($this->application->fresh()->failed_step)->toBeNull();
});

/*
 * The settings endpoint has the same failure shape, and my feature is what made it
 * reachable: the registry became editable there, so changing it re-pulls, and a
 * wrong credential fails the apply. It answered 500 before this.
 */

it('answers 422 when the settings save cannot bring the container up', function () {
    $ran = [];
    recordPull($ran, fn () => Process::result(
        output: '',
        errorOutput: 'Error response from daemon: authentication required - incorrect username or password',
        exitCode: 18,
    ));

    $this->withHeaders(['Authorization' => 'Bearer '.$this->admin->createToken('t')->plainTextToken])
        ->putJson('/api/applications/'.$this->application->id.'/container', ['container_port' => 8080])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'registry_credentials_rejected')
        // Saved, even though the apply failed. Reverting would leave the compose
        // file on disk disagreeing with what the panel shows.
        ->assertJsonPath('saved', true);

    expect($this->application->fresh()->container_port)->toBe(8080)
        ->and($this->application->fresh()->failed_reason)->toBe('registry_credentials_rejected');
});
