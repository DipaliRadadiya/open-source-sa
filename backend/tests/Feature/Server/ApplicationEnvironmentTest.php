<?php

use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\SystemUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Process;

/*
 * The endpoint. Every file operation here goes through ServerOps because the
 * file belongs to the site's system user and the panel account cannot open it,
 * so the fake stands in for a real directory: `cat` returns what is "on disk",
 * `tee` writes to it, `test` answers existence.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();

    $systemUser = SystemUser::create(['username' => 'envowner', 'home_path' => '/home/envowner']);

    $this->application = Application::forceCreate([
        'system_user_id' => $systemUser->id,
        'name' => 'Deployed Site',
        'slug' => 'deployed-site',
        'domain' => 'deployed.test',
        'site_type' => 'git',            // git sites keep a .env
        'serving_profile' => 'php',
        'status' => 'active',
        'web_root' => '/',
    ]);

    $this->disk = ['/home/envowner/deployed-site/.env' => "APP_ENV=production\nAPP_KEY=base64:abc\nDB_PASSWORD=hunter2\n"];
    $this->present = ['/home/envowner/deployed-site/public_html/artisan'];
    $this->backupNames = [];
    $this->written = null;
});

/**
 * A fake server with a filesystem. `$this->disk` is the file contents,
 * `$this->present` the paths that exist but have no readable content.
 */
function fakeSite(): void
{
    Process::fake(function ($process) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;
        [$binary] = $args;

        $disk = test()->disk;
        $present = array_merge(test()->present, array_keys($disk));

        if ($binary === 'test') {
            return Process::result(exitCode: in_array($args[2] ?? '', $present, true) ? 0 : 1);
        }

        if ($binary === 'cat') {
            $path = $args[1] ?? '';

            return array_key_exists($path, $disk)
                ? Process::result(output: $disk[$path])
                : Process::result(errorOutput: 'No such file', exitCode: 1);
        }

        if ($binary === 'tee') {
            test()->written = $process->input ?? '';

            return Process::result(exitCode: 0);
        }

        if ($binary === 'find') {
            return Process::result(output: implode("\n", test()->backupNames));
        }

        return Process::result(exitCode: 0);
    });
}

function envUrl(string $suffix = ''): string
{
    return '/api/applications/'.test()->application->id.'/environment'.$suffix;
}

it('returns the file, its parsed variables and what it thinks of them', function () {
    fakeSite();

    $response = $this->actingAs($this->admin)->getJson(envUrl())->assertOk();

    expect($response->json('environment.exists'))->toBeTrue()
        ->and($response->json('environment.framework'))->toBe('laravel')
        ->and($response->json('environment.framework_title'))->toBe('Laravel')
        ->and($response->json('environment.raw'))->toContain('APP_ENV=production')
        ->and($response->json('environment.path'))->toBe('/home/envowner/deployed-site/.env');

    // The parsed view the UI renders values from, with the secret withheld.
    $variables = collect($response->json('environment.variables'));
    expect($variables->firstWhere('key', 'APP_ENV')['value'])->toBe('production')
        ->and($variables->firstWhere('key', 'DB_PASSWORD')['value'])->toBeNull()
        ->and($variables->firstWhere('key', 'DB_PASSWORD')['secret'])->toBeTrue();
});

it('never sends a secret value in any field of the response', function () {
    fakeSite();

    $response = $this->actingAs($this->admin)->getJson(envUrl())->assertOk();

    // `raw` is the editor's content and legitimately holds it; nothing else
    // should. This is the assertion that would catch a future field added
    // without thinking about it.
    $withoutRaw = $response->json('environment');
    unset($withoutRaw['raw']);

    expect(json_encode($withoutRaw))->not->toContain('hunter2');
});

it('reports that a Laravel site with a cached config needs applying', function () {
    $this->present[] = '/home/envowner/deployed-site/public_html/bootstrap/cache/config.php';
    fakeSite();

    // Without this the panel says "Saved" and the site carries on reading the
    // cached values — the failure has no error and no symptom.
    expect($this->actingAs($this->admin)->getJson(envUrl())->json('environment.requires_apply'))
        ->toBeTrue();
});

it('does not claim an apply is needed when there is no cache', function () {
    fakeSite();

    expect($this->actingAs($this->admin)->getJson(envUrl())->json('environment.requires_apply'))
        ->toBeFalse();
});

it('saves the file and records which keys changed, never their values', function () {
    fakeSite();

    $this->actingAs($this->admin)
        ->putJson(envUrl(), ['raw' => "APP_ENV=production\nAPP_KEY=base64:abc\nDB_PASSWORD=newsecret\nMAIL_FROM=hi@x.test\n"])
        ->assertOk();

    expect($this->written)->toContain('MAIL_FROM=hi@x.test');

    $entry = ActivityLog::query()->where('action', 'environment_updated')->firstOrFail();

    // A password change must appear as a changed key — comparing the parsed
    // values would compare null to null and record nothing.
    expect($entry->properties['keys'])->toContain('DB_PASSWORD')
        ->and($entry->properties['keys'])->toContain('MAIL_FROM')
        ->and(json_encode($entry->properties))->not->toContain('newsecret')
        ->and(json_encode($entry->properties))->not->toContain('hunter2');
});

it('refuses to save a file it cannot parse', function () {
    fakeSite();

    $this->actingAs($this->admin)
        ->putJson(envUrl(), ['raw' => "APP_ENV=production\nTHIS LINE IS BROKEN\n"])
        ->assertStatus(422)
        ->assertJsonValidationErrors('raw');

    // Nothing written: installing a file the parser cannot read would take the
    // site down, and the user is one keystroke from fixing it.
    expect($this->written)->toBeNull();
});

it('lets the user empty the file', function () {
    fakeSite();

    // Clearing the editor is a legitimate save. `required` counts "" as absent,
    // so this used to answer "The raw field is required" about the field the
    // user had just deliberately emptied.
    $this->actingAs($this->admin)
        ->putJson(envUrl(), ['raw' => ''])
        ->assertOk();

    expect($this->written)->toBe("\n");
});

it('still refuses a request that omits the file entirely', function () {
    fakeSite();

    // The case `present` keeps catching: no `raw` key at all is a client bug,
    // and treating it as "empty" would blank someone's environment over a
    // malformed request.
    $this->actingAs($this->admin)
        ->putJson(envUrl(), [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('raw');

    expect($this->written)->toBeNull();
});

it('saves a file with warnings, because they are the user\'s business', function () {
    fakeSite();

    // Debug mode on is worth saying loudly and not worth blocking — it is a
    // legitimate thing to do while chasing a bug.
    $this->actingAs($this->admin)
        ->putJson(envUrl(), ['raw' => "APP_ENV=production\nAPP_KEY=base64:abc\nAPP_DEBUG=true\n"])
        ->assertOk();

    expect($this->written)->toContain('APP_DEBUG=true');
});

describe('site types that keep no .env', function () {
    beforeEach(function () {
        $this->wordpress = Application::forceCreate([
            'system_user_id' => $this->application->system_user_id,
            'name' => 'Blog',
            'slug' => 'blog',
            'domain' => 'blog.test',
            'site_type' => 'wordpress',
            'serving_profile' => 'php',
            'status' => 'active',
            'web_root' => '/',
        ]);
    });

    it('is absent from the application sidebar', function () {
        fakeSite();

        $response = $this->actingAs($this->admin)
            ->getJson('/api/permissions?level=application&application_id='.$this->wordpress->id)
            ->assertOk();

        expect(collect($response->json('permissions'))->pluck('name'))->not->toContain('app_environment');
    });

    it('is refused at the endpoint too, not just hidden in the nav', function () {
        fakeSite();

        // A missing nav item is not access control — the URL is still typeable.
        // 404 rather than 403, set by the shared permission middleware: for a
        // WordPress site this screen does not exist at all, which is a
        // different statement from "you may not have it".
        $this->actingAs($this->admin)
            ->getJson("/api/applications/{$this->wordpress->id}/environment")
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->putJson("/api/applications/{$this->wordpress->id}/environment", ['raw' => "A=1\n"])
            ->assertNotFound();
    });
});

describe('permissions', function () {
    it('lets a viewer read but not write', function () {
        fakeSite();
        $user = User::factory()->create();
        grantPermission($user, 'app_environment', view: true, manage: false);

        $this->actingAs($user)->getJson(envUrl())->assertOk();
        $this->actingAs($user)->putJson(envUrl(), ['raw' => "A=1\n"])->assertForbidden();
    });

    it('denies a user with no grant at all', function () {
        fakeSite();

        $this->actingAs(User::factory()->create())->getJson(envUrl())->assertForbidden();
    });

    it('denies an unauthenticated caller', function () {
        fakeSite();

        $this->getJson(envUrl())->assertUnauthorized();
    });
});

describe('which file the screen opens', function () {
    it('opens the .env beside the code when one is already there', function () {
        // This site's code root is also its served directory, so the panel
        // creates a .env one level above it. The framework does not look
        // there: it reads the one beside its own code — which git put there,
        // which the file manager shows, and which the screen used to report as
        // "no environment file", offering to create a second copy that nothing
        // would ever read.
        $beside = '/home/envowner/deployed-site/public_html/.env';
        $this->disk = [$beside => "APP_ENV=production\nAPP_KEY=base64:real\n"];
        fakeSite();

        $response = $this->actingAs($this->admin)->getJson(envUrl())->assertOk();

        expect($response->json('environment.exists'))->toBeTrue()
            ->and($response->json('environment.path'))->toBe($beside)
            ->and($response->json('environment.raw'))->toContain('base64:real');
    });

    it('says so when that file is reachable over the web', function () {
        $this->disk = ['/home/envowner/deployed-site/public_html/.env' => "APP_KEY=base64:real\n"];
        fakeSite();

        $response = $this->actingAs($this->admin)->getJson(envUrl())->assertOk();

        // Reported, not silently relocated. Moving it would break the site,
        // and Apache's dotfile rule is a DirectoryMatch — it does not cover a
        // `.env` file at all, so this is a live disclosure and not a theory.
        expect($response->json('environment.exposed'))->toBeTrue()
            ->and(collect($response->json('environment.checks'))->pluck('code'))
            ->toContain('file_exposed');
    });

    it('does not cry exposure for a file the panel put out of reach', function () {
        // The default fixture: .env above public_html, which is where the
        // panel creates one and where it stays for a site with a web root.
        fakeSite();

        $response = $this->actingAs($this->admin)->getJson(envUrl())->assertOk();

        expect($response->json('environment.exposed'))->toBeFalse()
            ->and(collect($response->json('environment.checks'))->pluck('code'))
            ->not->toContain('file_exposed');
    });

    it('writes to the file it opened, not the one policy would create', function () {
        $beside = '/home/envowner/deployed-site/public_html/.env';
        $this->disk = [$beside => "APP_ENV=production\n"];
        fakeSite();

        $paths = new ArrayObject;
        Process::fake(function ($process) use ($paths, $beside) {
            $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

            if (($args[0] ?? '') === 'tee') {
                $paths[] = (string) ($args[1] ?? '');
            }

            if (($args[0] ?? '') === 'cat') {
                return Process::result(output: "APP_ENV=production\n");
            }

            return Process::result(exitCode: ($args[0] ?? '') === 'test' && ($args[2] ?? '') !== $beside ? 1 : 0);
        });

        $this->actingAs($this->admin)
            ->putJson(envUrl(), ['raw' => "APP_ENV=production\nMAIL_FROM=hi@x.test\n"])
            ->assertOk();

        // A save that lands anywhere else is the same bug from the other end.
        expect(collect($paths)->every(fn (string $p): bool => str_starts_with($p, $beside)))->toBeTrue()
            ->and($paths->count())->toBeGreaterThan(0);
    });
});

describe('when the panel cannot tell', function () {
    it('refuses to report "no environment file" when the check itself failed', function () {
        // `test -f` exits 1 for a file that is not there AND for a command
        // that never ran — a sudoers grant older than this build is the
        // common one. Read as the first, the screen shows an empty editor and
        // offers to create a .env for a site whose .env the user is looking
        // at in the file manager. `test` prints nothing on either outcome, so
        // stderr means something refused to run it.
        Process::fake(fn () => Process::result(
            errorOutput: 'sudo: a password is required',
            exitCode: 1,
        ));

        $response = $this->actingAs($this->admin)->getJson(envUrl())->assertStatus(500);

        // A reference the user can quote, not a bare 500 — the server-ops log
        // holds the command and the stderr under this id.
        expect($response->json('reference'))->not->toBeEmpty();
    });

    it('still reports a genuinely absent file as absent', function () {
        // The other half: exit 1 and nothing on stderr is a real answer, and
        // a site with no .env yet is an ordinary state this screen exists to
        // fix. Turning that into an error would break every new site.
        $this->disk = [];
        $this->present = [];
        fakeSite();

        $response = $this->actingAs($this->admin)->getJson(envUrl())->assertOk();

        expect($response->json('environment.exists'))->toBeFalse()
            ->and($response->json('environment.raw'))->toBe('');
    });
});
