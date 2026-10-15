<?php

use App\Jobs\ProvisionApplication;
use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\ApplicationProvisioner;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

/**
 * A pasted compose file that publishes a host port another application holds
 * (RC-43).
 *
 * `applications.app_port` is UNIQUE, because two sites on one port means the
 * proxy sends one site's traffic to the other. The form accepted such a file with
 * a 201, and the provisioning job then died on that index when it recorded the
 * port the file publishes — so the site showed `failed` and said nothing about
 * why. Measured on 2026-10-15: `cmp2-docker` publishing 127.0.0.1:18080:80 while
 * `cmp-docker` already had 18080.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->systemUser = SystemUser::create(['username' => 'portowner', 'home_path' => '/home/portowner']);
    ServerCapability::create([
        'stack' => 'docker', 'web_server' => 'nginx',
        'capabilities' => ['php' => true, 'node' => false, 'serving_profiles' => ['docker']],
        'source' => 'installer', 'verified_at' => now(),
    ]);
    Queue::fake();
});

/**
 * `docker compose config` answering with these published ports, each
 * `[host, container]` on loopback. `$running` is a box the provisioning job can
 * get all the way through: the account exists and the container comes up.
 *
 * @param  list<array{0: int|string, 1: int}>  $ports
 */
function rc43Box(array $ports, bool $running = false): void
{
    Process::fake(function ($process) use ($ports, $running) {
        $line = implode(' ', (array) $process->command);

        return match (true) {
            str_contains($line, 'config --format json') => Process::result(json_encode(['services' => ['web' => [
                'image' => 'nginx:alpine',
                'ports' => array_map(fn (array $port): array => [
                    'host_ip' => '127.0.0.1', 'published' => (string) $port[0], 'target' => $port[1],
                ], $ports),
            ]]])),
            $running && str_contains($line, '--status running') => Process::result("abc\n"),
            $running && str_contains($line, 'curl') => Process::result('200'),
            ! $running && str_contains($line, 'getent') => Process::result(exitCode: 2),
            ! $running && str_contains($line, 'cat ') => Process::result(exitCode: 1),
            default => Process::result(),
        };
    });
}

function rc43Holder(int $port, string $name = 'cmp-docker'): Application
{
    return Application::forceCreate([
        'system_user_id' => test()->systemUser->id,
        'name' => $name, 'slug' => $name, 'domain' => $name.'.test',
        'site_type' => 'docker', 'serving_profile' => 'docker', 'web_root' => 'public_html',
        'image' => 'nginx:alpine', 'container_port' => 80, 'app_port' => $port,
        'status' => 'active',
    ]);
}

function rc43Store(string $compose, array $extra = [])
{
    return test()->actingAs(test()->admin)->postJson('/api/applications', $extra + [
        'name' => 'cmp2-docker', 'domain' => 'cmp2.example.test',
        'system_user_id' => test()->systemUser->id,
        'site_type' => 'docker',
        'compose' => $compose,
    ]);
}

it('refuses a pasted compose whose port another application already uses', function () {
    rc43Holder(18080);
    rc43Box([[18080, 80]]);

    $response = rc43Store("services:\n  web:\n    image: nginx:alpine\n    ports:\n      - \"127.0.0.1:18080:80\"\n")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('compose');

    expect($response->json('errors.compose'))->toContain(
        __('errors/application.compose_port_taken', ['port' => 18080, 'application' => 'cmp-docker']),
    )
        ->and(Application::count())->toBe(1);
    Queue::assertNothingPushed();
});

it('refuses a clash on a port the file publishes besides the proxied one', function () {
    // nginx proxies to 18081 here, but the file also binds 18080 — and the other
    // application is listening there, so this container could never start.
    rc43Holder(18080);
    rc43Box([[18081, 80], [18080, 9090]]);

    $response = rc43Store("services:\n  web:\n    image: nginx:alpine\n", ['container_port' => 80])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('compose');

    expect($response->json('errors.compose'))->toContain(
        __('errors/application.compose_port_taken', ['port' => 18080, 'application' => 'cmp-docker']),
    );
});

it('names the clash on the proxied port first when there are several', function () {
    rc43Holder(18080, 'first-app');
    rc43Holder(18081, 'second-app');
    rc43Box([[18080, 9090], [18081, 80]]);

    $response = rc43Store("services:\n  web:\n    image: nginx:alpine\n", ['container_port' => 80])
        ->assertUnprocessable();

    expect($response->json('errors.compose'))->toBe([
        __('errors/application.compose_port_taken', ['port' => 18081, 'application' => 'second-app']),
    ]);
});

it('sees a clash inside a published port range', function () {
    rc43Holder(18085);
    rc43Box([['18080-18090', 80]]);

    rc43Store("services:\n  web:\n    image: nginx:alpine\n")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('compose');
});

it('still creates the same file when nothing else uses its port', function () {
    rc43Holder(18090);
    rc43Box([[18080, 80]]);

    rc43Store("services:\n  web:\n    image: nginx:alpine\n    ports:\n      - \"127.0.0.1:18080:80\"\n")
        ->assertCreated();

    expect(Application::count())->toBe(2);
});

it('fails the job with a reason, not on the unique index, when a row bypassed the form', function () {
    // Written directly, so no form saw it: a restore, an import, an edit by hand.
    rc43Holder(18080);
    rc43Box([[18080, 80]], running: true);

    $app = Application::forceCreate([
        'system_user_id' => $this->systemUser->id,
        'name' => 'cmp2-docker', 'slug' => 'cmp2-docker', 'domain' => 'cmp2.test',
        'site_type' => 'docker', 'serving_profile' => 'docker', 'web_root' => 'public_html',
        'app_port' => 20001, 'status' => 'pending',
        'compose' => "services:\n  web:\n    image: nginx:alpine\n    ports:\n      - \"127.0.0.1:18080:80\"\n",
    ]);

    // Would throw a QueryException out of handle() before the fix.
    (new ProvisionApplication($app->id))->handle(
        app(ApplicationProvisioner::class),
        app(ActivityLogger::class),
    );

    $app->refresh();

    expect($app->status->value)->toBe('failed')
        ->and($app->failed_step)->toBe('compose_port_taken')
        ->and($app->failed_reason)->toBe('compose_port_taken')
        ->and($app->app_port)->toBe(20001);

    // Titled for the app page in the viewer's language, like every other reason.
    $this->actingAs($this->admin)
        ->getJson('/api/applications/'.$app->id)
        ->assertOk()
        ->assertJsonPath('application.failed_reason_title', __('application.failure_reason.compose_port_taken'));
});

it('translates the refusal in every locale, with both placeholders', function () {
    foreach (['en', 'es', 'de', 'fr', 'pt', 'ja', 'ru', 'hi'] as $locale) {
        $line = trans('errors/application.compose_port_taken', [], $locale);

        expect($line)->not->toBe('errors/application.compose_port_taken', "missing in {$locale}")
            ->and($line)->toContain(':port', ':application');

        $reason = trans('application.failure_reason.compose_port_taken', [], $locale);

        expect($reason)->not->toBe('application.failure_reason.compose_port_taken', "reason missing in {$locale}");
    }

    // A translator's fallback to English is not a translation.
    expect(trans('errors/application.compose_port_taken', [], 'de'))
        ->not->toBe(trans('errors/application.compose_port_taken', [], 'en'));
});
