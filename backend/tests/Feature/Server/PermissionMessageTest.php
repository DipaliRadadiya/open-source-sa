<?php

use App\Models\User;
use App\Services\Server\Databases\DatabaseManager;
use App\Services\Server\Metrics\ServerMetrics;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;

/*
 * Two reports from the frontend team, verified and fixed together because both
 * are the same shape: the server knew something specific and said something
 * useless.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
});

it('names the permission a refusal was about, instead of an empty message', function () {
    // `abort(403)` sends `{"message": ""}`. The panel then had to invent
    // wording for every refusal, without being told what had been refused.
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
    // 115 FormRequests return false from authorize(), which produces an empty
    // 403 the middleware never sees. A generic sentence is not as good as a
    // specific one, but it beats the empty string they were getting.
    //
    // Driven through a real route rather than asserted against the translation
    // file: the first version of this test only checked the string existed,
    // which passed with the render hook deleted — it was testing the lang file,
    // not the behaviour.
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
    // The dashboard read `mysql --version` under a hardcoded `mysql` key. On a
    // MariaDB box that is the *client* reporting its own version, so a server
    // with no MySQL showed "MySQL 15.2" while /databases/engines said MariaDB
    // 11.8.6 on the same server.
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
