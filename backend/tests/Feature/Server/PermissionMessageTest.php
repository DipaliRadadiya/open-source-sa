<?php

use App\Models\Application;
use App\Models\User;
use App\Services\Server\Databases\DatabaseManager;
use App\Services\Server\Metrics\ServerMetrics;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;

/*
 * Both cases share one shape: the server knew something specific and said something useless.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
});

it('names the permission a refusal was about, instead of an empty message', function () {
    // `abort(403)` sends `{"message": ""}`, so the panel has to invent wording without being told
    // what was refused.
    $stranger = User::factory()->create();
    $token = $stranger->createToken('t')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/php')
        ->assertForbidden();

    expect($response->json('message'))->not->toBe('')
        // The permission's own title, not its key — a sentence a user reads.
        ->and($response->json('message'))->toContain('PHP');
});

it('says something for a refusal raised without a message at all', function () {
    // FormRequests returning false from authorize() produce an empty 403 the middleware never
    // sees. A generic sentence beats the empty string.
    //
    // Driven through a real route: asserting the translation file would pass with the render hook
    // deleted.
    Route::middleware('api')->get('/api/_test_bare_forbidden', fn () => abort(403));

    $this->getJson('/api/_test_bare_forbidden')
        ->assertForbidden()
        ->assertJsonPath('message', __('errors/http.forbidden'));
});

it('keeps a specific refusal instead of overwriting it with the generic one', function () {
    // The fallback fills only an absent message. A guard that explains itself
    // must not have that explanation replaced by a safe generic line.
    $viewer = User::factory()->create();
    grantPermission($viewer, 'php');
    $token = $viewer->createToken('t')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/php/versions', ['version' => '8.4'])
        ->assertForbidden();

    expect($response->json('message'))->toContain('PHP');
});

it('reports the database engine that is actually running, not a client binary', function () {
    // `mysql --version` on a MariaDB box is the *client* reporting its own version, so a server
    // with no MySQL would show "MySQL 15.2" while /databases/engines says MariaDB.
    Process::fake(function ($process) {
        $command = $process->command;
        if (($command[0] ?? null) === 'sudo') {
            $command = array_slice($command, 2);
        }

        return in_array('mariadb', $command, true)
            ? Process::result(output: "11.8.6-MariaDB\n")
            : Process::result(exitCode: 1);
    });

    $runtimes = (fn () => $this->runtimes())->call(app(ServerMetrics::class));

    expect($runtimes)->toHaveKey('mariadb')
        ->and($runtimes['mariadb'])->toContain('11.8.6')
        // The key names the engine that answered. A box with no MySQL must not
        // carry a `mysql` row at all — omitting it is honest, inventing is not.
        ->and($runtimes)->not->toHaveKey('mysql');
});

it('omits the database entirely when no engine answers', function () {
    Process::fake(fn () => Process::result(exitCode: 1));

    $runtimes = (fn () => $this->runtimes())->call(app(ServerMetrics::class));

    foreach (app(DatabaseManager::class)->engineNames() as $engine) {
        expect($runtimes)->not->toHaveKey($engine);
    }
});

it('reports the PHP the panel is running, without shelling out for it', function () {
    // The web process does not have the CLI's PATH: on OpenLiteSpeed, lshttpd runs with
    // PATH=/bin:/usr/bin while the interpreter sits at /usr/local/bin/php, so resolving `php`
    // from PATH shows PHP as blank.
    Process::fake(fn () => Process::result(exitCode: 1));

    $runtimes = (fn () => $this->runtimes())->call(app(ServerMetrics::class));

    // Correct even with every subprocess failing: the answer does not depend on a command.
    expect($runtimes['php'])->toBe(PHP_VERSION);

    Process::assertNotRan(fn ($p) => in_array('php', $p->command, true));
});

it('reports every database engine that is running, not just the first', function () {
    // A server can run MariaDB and PostgreSQL at once, so every engine is listed, not only the
    // first (one-only is right for the web server).
    Process::fake(function ($process) {
        $command = $process->command;
        if (($command[0] ?? null) === 'sudo') {
            $command = array_slice($command, 2);
        }

        foreach (['mariadb' => '11.8.6-MariaDB', 'psql' => '18.6'] as $binary => $version) {
            if (in_array($binary, $command, true)) {
                return Process::result(output: $version."\n");
            }
        }

        return Process::result(exitCode: 1);
    });

    $runtimes = (fn () => $this->runtimes())->call(app(ServerMetrics::class));

    expect($runtimes)->toHaveKey('mariadb')
        ->and($runtimes)->toHaveKey('postgresql')
        ->and($runtimes)->not->toHaveKey('mysql');
});

it('refuses a name long enough to break the config filename', function () {
    // A 255-character slug plus `.conf` cannot be created, so the site would provision and then
    // fail when the vhost was written.
    $this->seed(PermissionSeeder::class);
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/applications', [
            'name' => str_repeat('a', Application::MAX_NAME_LENGTH + 1),
            'domain' => 'long-name-test.example',
            'site_type' => 'static',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');
});

it('keeps every name the filesystem can actually hold', function () {
    // The counterweight: a cap that refuses ordinary names is worse than the overflow.
    expect(Application::MAX_NAME_LENGTH)->toBeGreaterThan(200);

    $slug = Application::uniqueSlug(str_repeat('a', Application::MAX_NAME_LENGTH));

    // Longest suffix the panel appends, plus room for a collision suffix.
    expect(strlen($slug.'-99999-tls.conf'))->toBeLessThanOrEqual(255);
});
