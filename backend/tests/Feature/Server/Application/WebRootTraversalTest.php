<?php

use App\Models\Application;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\Applications\ApplicationProvisioner;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * `web_root` becomes a filesystem path that the panel hands to `mkdir -p`,
 * `chown -R` and `tee` while running as root.
 *
 * It was validated only as `string|max:255`, so `../../../../etc` walked
 * straight out of the site: the panel chowned /etc to an unprivileged site
 * user and wrote a placeholder into it. Any user who could create an
 * application could do it.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;

    $this->su = SystemUser::create([
        'username' => 'bob', 'home_path' => '/home/bob',
        'shell' => '/bin/bash', 'sudo' => false,
    ]);
});

function createApp(array $overrides = []): TestResponse
{
    return test()->withHeaders(['Authorization' => 'Bearer '.test()->token])
        ->postJson('/api/applications', array_merge([
            'system_user_id' => test()->su->id,
            'name' => 'Site',
            'domain' => 'site.test',
            'site_type' => 'php',
        ], $overrides));
}

it('refuses a web_root that climbs out of the site', function (string $webRoot) {
    Process::fake();

    createApp(['web_root' => $webRoot])->assertJsonValidationErrors('web_root');

    // Nothing may reach the filesystem when validation refuses.
    Process::assertNotRan(fn ($p) => in_array($p->command[0] ?? '', ['mkdir', 'chown', 'tee'], true));
})->with([
    '../../../../etc',
    'public/../../../../etc',
    '..',
    'a/../../b',
    '../',
]);

it('refuses a web_root containing anything but a plain relative path', function (string $webRoot) {
    Process::fake();

    createApp(['web_root' => $webRoot])->assertJsonValidationErrors('web_root');
})->with([
    '/etc/passwd; rm -rf /',
    "public\nnewline",
    'pub lic',
    '$(whoami)',
    '~root',
]);

it('still accepts the web roots real applications use', function (string $webRoot) {
    Process::fake();

    createApp(['web_root' => $webRoot])->assertCreated();
})->with(['public', 'web', 'html/public', 'current/public', '/']);

it('refuses to build a document root with a parent segment even if one is stored', function () {
    // Defence in depth: validation is the fix, but this is the line where the
    // damage would happen, so it refuses too rather than trusting the record.
    $app = Application::forceCreate([
        'system_user_id' => $this->su->id, 'name' => 'X',
        'slug' => 'x', 'domain' => 'x.test',
        'site_type' => 'php', 'serving_profile' => 'php', 'status' => 'pending',
    ]);

    // Written straight to the column, as a bad migration or a future code path
    // might.
    $app->forceFill(['web_root' => '../../../../etc'])->save();

    expect(fn () => app(ApplicationProvisioner::class)->documentRoot($app->load('systemUser')))
        ->toThrow(HttpException::class);
});

it('refuses a php_version that is not a version', function () {
    Process::fake();

    // It becomes a path segment and, on OpenLiteSpeed, part of an executed
    // binary path. `max:10` let a newline through.
    createApp(['php_version' => "8.4\nfoo"])->assertJsonValidationErrors('php_version');
    createApp(['php_version' => '8.4'])->assertCreated();
});
