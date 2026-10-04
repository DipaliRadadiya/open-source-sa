<?php

use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Collection;
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
    $this->ran = new ArrayObject;
});

/**
 * A fake server with a filesystem. `$this->disk` is the file contents,
 * `$this->present` the paths that exist but have no readable content.
 */
function fakeSite(): void
{
    Process::fake(function ($process) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;
        test()->ran->append($args);

        // A `.env` beside the code is handled as the site user — the fake acts
        // on the command under the `runuser -u <user> --` prefix.
        if (($args[0] ?? '') === 'runuser') {
            $args = array_slice($args, 4);
        }

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
    it('does not show the screen to a view-only role at all', function () {
        // Operator decision 2026-09-29: the `.env` screen is for whoever may
        // edit it. A view grant opens neither the file, its history, nor a
        // save (see VisiblePermissions::MANAGE_ONLY for the sidebar half).
        fakeSite();
        $user = User::factory()->create();
        grantPermission($user, 'app_environment', view: true, manage: false);

        $this->actingAs($user)->getJson(envUrl())->assertForbidden();
        $this->actingAs($user)->getJson(envUrl('/history'))->assertForbidden();
        $this->actingAs($user)->putJson(envUrl(), ['raw' => "A=1\n"])->assertForbidden();
    });

    it('still gives the whole file to someone who may edit it', function () {
        fakeSite();
        $editor = User::factory()->create();
        grantPermission($editor, 'app_environment', view: true, manage: true);

        $this->actingAs($editor)->getJson(envUrl())->assertOk()
            ->assertJsonPath('environment.raw', "APP_ENV=production\nAPP_KEY=base64:abc\nDB_PASSWORD=hunter2\n");
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

    it('does not call a .env in the web root exposed, since every vhost refuses dotfiles', function () {
        $this->disk = ['/home/envowner/deployed-site/public_html/.env' => "APP_KEY=base64:real\n"];
        fakeSite();

        $response = $this->actingAs($this->admin)->getJson(envUrl())->assertOk();

        // It said "exposed" for Akaunting and Mautic while their /.env
        // answered 403: nginx and Apache refuse a dotfile at any depth, and
        // OLS does too since its rule stopped being anchored to `/`.
        expect($response->json('environment.exposed'))->toBeFalse()
            ->and(collect($response->json('environment.checks'))->pluck('code'))
            ->not->toContain('file_exposed');
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

            if (($args[0] ?? '') === 'runuser') {
                $args = array_slice($args, 4);
            }

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

describe('the history of who changed it', function () {
    it('names the person, the keys they changed and the version they replaced', function () {
        $this->backupNames = ['.env.bak-20260907-120000'];
        fakeSite();

        $editor = User::factory()->create();
        grantPermission($editor, 'app_environment', view: true, manage: true);

        $this->actingAs($editor)
            ->putJson(envUrl(), ['raw' => "APP_ENV=production\nDB_PASSWORD=changed\n"])
            ->assertOk();

        $row = $this->actingAs($this->admin)->getJson(envUrl('/history'))->assertOk()->json('history.0');

        expect($row['user']['username'])->toBe($editor->username)
            ->and($row['is_system'])->toBeFalse()
            ->and($row['action'])->toBe('environment_updated')
            // Names only. APP_KEY was removed, DB_PASSWORD changed.
            ->and($row['keys'])->toContain('DB_PASSWORD')
            ->and($row['backup'])->toMatch('/^\.env\.bak-\d{8}-\d{6}/');
    });

    it('never carries a value out of the file', function () {
        // The whole premise of the screen is that these are secrets. A history
        // that stored old values would put every rotated password into the
        // panel database, readable by anyone who can read a log.
        $this->backupNames = ['.env.bak-20260907-120000'];
        fakeSite();

        $this->actingAs($this->admin)
            ->putJson(envUrl(), ['raw' => "DB_PASSWORD=newsecret\n"])
            ->assertOk();

        $body = $this->actingAs($this->admin)->getJson(envUrl('/history'))->assertOk()->getContent();

        expect($body)->not->toContain('hunter2')
            ->and($body)->not->toContain('newsecret');
    });

    it('offers a restore only while the file is still on disk', function () {
        fakeSite();

        // Two saves half a minute apart, so they take distinct names, then
        // only the second's backup survives pruning.
        $this->actingAs($this->admin)->putJson(envUrl(), ['raw' => "A=1\n"])->assertOk();
        $first = ActivityLog::query()->latest('id')->first()->properties['backup'];

        $this->travel(30)->seconds();

        $this->actingAs($this->admin)->putJson(envUrl(), ['raw' => "A=2\n"])->assertOk();
        $second = ActivityLog::query()->latest('id')->first()->properties['backup'];

        expect($second)->not->toBe($first);

        $this->backupNames = [$second];

        $rows = collect($this->actingAs($this->admin)->getJson(envUrl('/history'))->assertOk()->json('history'))
            ->keyBy('backup');

        expect($rows[$second]['restorable'])->toBeTrue()
            // Pruned, but still shown: dropping it would rewrite the record of
            // who touched the file, which is what this screen is for.
            ->and($rows[$first]['restorable'])->toBeFalse();
    });

    it('shows a first save as having nothing to go back to', function () {
        // Different from "the backup was pruned": there was never a file to
        // keep, and saying "no longer available" would invent a lost version.
        $this->disk = [];
        $this->present = [];
        fakeSite();

        $this->actingAs($this->admin)->putJson(envUrl(), ['raw' => "A=1\n"])->assertOk();

        $row = $this->actingAs($this->admin)->getJson(envUrl('/history'))->assertOk()->json('history.0');

        expect($row['backup'])->toBeNull()
            ->and($row['restorable'])->toBeFalse();
    });

    it('records what a restore replaced, so the restore itself can be undone', function () {
        $this->backupNames = ['.env.bak-20260907-120000'];
        // `find` lists it; `test -f` is what the restore actually checks.
        $this->disk['/home/envowner/deployed-site/.env.bak-20260907-120000'] = "APP_ENV=local\n";
        fakeSite();

        $this->actingAs($this->admin)
            ->postJson(envUrl('/restore'), ['backup' => '.env.bak-20260907-120000'])
            ->assertOk();

        $row = $this->actingAs($this->admin)->getJson(envUrl('/history'))->assertOk()->json('history.0');

        expect($row['action'])->toBe('environment_restored')
            ->and($row['restored_from'])->toBe('.env.bak-20260907-120000')
            // The safety copy of what the restore overwrote — a different file
            // from the one it put back.
            ->and($row['backup'])->not->toBe('.env.bak-20260907-120000')
            ->and($row['backup'])->toMatch('/^\.env\.bak-\d{8}-\d{6}/');
    });

    it('is refused without the environment grant', function () {
        fakeSite();

        $outsider = User::factory()->create();

        $this->actingAs($outsider)->getJson(envUrl('/history'))->assertForbidden();
    });

    it('is readable by someone who may edit the file', function () {
        fakeSite();

        $editor = User::factory()->create();
        grantPermission($editor, 'app_environment', view: true, manage: true);

        $this->actingAs($editor)->getJson(envUrl('/history'))->assertOk();
    });

    it('keeps two saves in the same second apart', function () {
        // Second-precision names collide, and a collision means a history row
        // that restores somebody else's edit while labelled with this one.
        $this->freezeTime();
        $this->backupNames = [];
        fakeSite();

        // `test -f` answers from $present, so the first backup must appear
        // there for the second save to see the collision.
        $this->actingAs($this->admin)->putJson(envUrl(), ['raw' => "A=1\n"])->assertOk();
        $first = ActivityLog::query()->latest('id')->first()->properties['backup'];

        $this->present[] = '/home/envowner/deployed-site/'.$first;

        $this->actingAs($this->admin)->putJson(envUrl(), ['raw' => "A=2\n"])->assertOk();
        $second = ActivityLog::query()->latest('id')->first()->properties['backup'];

        expect($second)->not->toBe($first);
    });
});

describe('what one change did, variable by variable', function () {
    it('shows the old and new value of every key that moved, except a secret\'s', function () {
        // The values come off the backup files, never out of the activity log.
        $this->disk['/home/envowner/deployed-site/.env.bak-20260907-120000'] =
            "APP_ENV=production\nAPP_KEY=base64:abc\nDB_PASSWORD=hunter2\nAPP_DEBUG=true\n";
        $this->backupNames = ['.env.bak-20260907-120000'];
        fakeSite();

        $log = ActivityLog::create([
            'user_id' => $this->admin->id,
            'type' => 'application',
            'action' => 'environment_updated',
            'subject_type' => $this->application->getMorphClass(),
            'subject_id' => $this->application->id,
            'properties' => ['backup' => '.env.bak-20260907-120000'],
        ]);

        // Nothing has changed the file since, so "after" is what is on disk:
        // DB_PASSWORD rotated, APP_KEY gone, MAIL_HOST added, APP_DEBUG off.
        $this->disk['/home/envowner/deployed-site/.env'] =
            "APP_ENV=production\nDB_PASSWORD=rotated\nMAIL_HOST=smtp.test\nAPP_DEBUG=false\n";

        $response = $this->actingAs($this->admin)->getJson(envUrl('/history/'.$log->id.'/diff'))->assertOk();
        $changes = collect($response->json('diff.changes'))->keyBy('key');

        // Bug #67: which secret changed, never its value — the before side
        // is a rotated password nothing else on screen shows.
        expect($changes['DB_PASSWORD'])->toMatchArray([
            'before' => null, 'after' => null, 'status' => 'changed', 'secret' => true,
        ])
            ->and($changes['APP_KEY'])->toMatchArray([
                'before' => null, 'after' => null, 'status' => 'removed', 'secret' => true,
            ])
            ->and($response->getContent())->not->toContain('hunter2')->not->toContain('rotated')->not->toContain('base64:abc')
            ->and($changes['APP_DEBUG'])->toMatchArray([
                'before' => 'true', 'after' => 'false', 'status' => 'changed', 'secret' => false,
            ])
            ->and($changes['MAIL_HOST'])->toMatchArray([
                'before' => null, 'after' => 'smtp.test', 'status' => 'added', 'secret' => false,
            ])
            // Unchanged keys are not noise worth showing.
            ->and($changes->has('APP_ENV'))->toBeFalse();
    });

    it('says it cannot show a change whose backup has been pruned', function () {
        // Rather than an empty list, which would read as "this change touched
        // nothing" — a different and false statement.
        $this->backupNames = [];
        fakeSite();

        $log = ActivityLog::create([
            'user_id' => $this->admin->id,
            'type' => 'application',
            'action' => 'environment_updated',
            'subject_type' => $this->application->getMorphClass(),
            'subject_id' => $this->application->id,
            'properties' => ['backup' => '.env.bak-20250101-000000'],
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson(envUrl('/history/'.$log->id.'/diff'))
            ->assertOk();

        expect($response->json('diff.available'))->toBeFalse()
            ->and($response->json('diff.changes'))->toBe([]);
    });

    it('treats a first save as everything added', function () {
        // No backup because there was no file — distinct from a pruned one,
        // where the previous state is unknown rather than empty.
        fakeSite();

        $log = ActivityLog::create([
            'user_id' => $this->admin->id,
            'type' => 'application',
            'action' => 'environment_updated',
            'subject_type' => $this->application->getMorphClass(),
            'subject_id' => $this->application->id,
            'properties' => ['backup' => null],
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson(envUrl('/history/'.$log->id.'/diff'))
            ->assertOk();

        expect($response->json('diff.available'))->toBeTrue()
            ->and(collect($response->json('diff.changes'))->pluck('status')->unique()->all())
            ->toBe(['added']);
    });

    it('refuses a log id belonging to another application', function () {
        // Route model binding resolves ids globally, and this endpoint answers
        // with secret values.
        fakeSite();

        $other = Application::forceCreate([
            'system_user_id' => $this->application->system_user_id,
            'name' => 'Other Site', 'slug' => 'other-site', 'domain' => 'other.test',
            'site_type' => 'git', 'serving_profile' => 'php', 'status' => 'active', 'web_root' => '/',
        ]);

        $log = ActivityLog::create([
            'user_id' => $this->admin->id,
            'type' => 'application',
            'action' => 'environment_updated',
            'subject_type' => $other->getMorphClass(),
            'subject_id' => $other->id,
            'properties' => ['backup' => '.env.bak-20260907-120000'],
        ]);

        $this->actingAs($this->admin)
            ->getJson(envUrl('/history/'.$log->id.'/diff'))
            ->assertNotFound();
    });

    it('refuses a log row that is not an environment change', function () {
        fakeSite();

        $log = ActivityLog::create([
            'user_id' => $this->admin->id,
            'type' => 'application',
            'action' => 'deleted',
            'subject_type' => $this->application->getMorphClass(),
            'subject_id' => $this->application->id,
            'properties' => [],
        ]);

        $this->actingAs($this->admin)
            ->getJson(envUrl('/history/'.$log->id.'/diff'))
            ->assertNotFound();
    });

    it('is refused for a viewer who cannot restore a backup anyway', function () {
        // A manage user can already read these values by restoring; this only
        // saves them the round trip. A viewer cannot, so for them it would be
        // a genuine widening rather than a convenience.
        fakeSite();

        $viewer = User::factory()->create();
        grantPermission($viewer, 'app_environment', view: true, manage: false);

        $log = ActivityLog::create([
            'user_id' => $this->admin->id,
            'type' => 'application',
            'action' => 'environment_updated',
            'subject_type' => $this->application->getMorphClass(),
            'subject_id' => $this->application->id,
            'properties' => ['backup' => '.env.bak-20260907-120000'],
        ]);

        $this->actingAs($viewer)
            ->getJson(envUrl('/history/'.$log->id.'/diff'))
            ->assertForbidden();
    });

    it('refuses a backup name that is not one this panel writes', function () {
        fakeSite();

        $log = ActivityLog::create([
            'user_id' => $this->admin->id,
            'type' => 'application',
            'action' => 'environment_updated',
            'subject_type' => $this->application->getMorphClass(),
            'subject_id' => $this->application->id,
            'properties' => ['backup' => '../../../../etc/passwd'],
        ]);

        // The name reaches a path. Refused rather than sanitised.
        $this->actingAs($this->admin)
            ->getJson(envUrl('/history/'.$log->id.'/diff'))
            ->assertStatus(500);
    });
});

describe('restoring a backup', function () {
    it('refuses a name that is not a backup with a 422, not a 500', function (string $name) {
        // It reached the service unvalidated, which threw, and the user saw
        // "server error" while an error was logged (found live 2026-09-29).
        fakeSite();

        $this->actingAs($this->admin)->postJson(envUrl('/restore'), ['backup' => $name])
            ->assertStatus(422)
            ->assertJsonValidationErrors('backup');
    })->with([
        'a path' => ['../../etc/passwd'],
        'almost a backup' => ['.env.bak-2026'],
        'nothing' => [''],
    ]);

    it('refuses a well-formed backup that is not on disk with a 422', function () {
        fakeSite();

        $this->actingAs($this->admin)->postJson(envUrl('/restore'), ['backup' => '.env.bak-20260101-000000'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('backup');
    });
});

describe('the mode a save leaves the file in', function () {
    beforeEach(function () {
        ServerCapability::query()->delete();
        ServerCapability::create(['stack' => 'lemp', 'web_server' => 'nginx', 'capabilities' => ['php' => true],
            'source' => 'installer', 'verified_at' => now()]);
    });

    /**
     * Every command the panel ran, without the sudo prefix.
     *
     * @return Collection<int, array<int, string>>
     */
    function envCommands(): Collection
    {
        return collect(test()->ran->getArrayCopy())->values();
    }

    it('keeps it private to the site user where the site runs as that user', function () {
        $this->application->forceFill(['isolated_at' => now()])->save();
        fakeSite();

        $this->actingAs($this->admin)->putJson(envUrl(), ['raw' => "APP_ENV=production\n"])->assertOk();

        $temporary = '/home/envowner/deployed-site/.env.panel-tmp';

        expect(envCommands()->contains(['chmod', '0600', $temporary]))->toBeTrue()
            // This `.env` is at the top of the site root, so root writes it
            // and hands it to the user — and to nobody else.
            ->and(envCommands()->filter(fn (array $c) => ($c[0] ?? '') === 'chown')->values()->all())
            ->toBe([['chown', '-h', 'envowner:envowner', $temporary]]);
    });

    it('hands the group to the web server even when the site user writes the file', function () {
        // A `.env` beside the code is written as the site user, who cannot
        // give a file to the web server's group — root has to, or PHP loses
        // its configuration on the first save.
        $beside = '/home/envowner/deployed-site/public_html/.env';
        $this->disk = [$beside => "APP_ENV=production\n"];
        fakeSite();

        $this->actingAs($this->admin)->putJson(envUrl(), ['raw' => "APP_ENV=production\n"])->assertOk();

        expect(envCommands()->contains(['chown', '-h', 'envowner:www-data', $beside.'.panel-tmp']))->toBeTrue()
            ->and(envCommands()->contains(['runuser', '-u', 'envowner', '--', 'chmod', '0640', $beside.'.panel-tmp']))->toBeTrue();
    });

    it('leaves it readable by the web server where PHP runs as that account', function () {
        // A flat 0600 took the file away from this site's own PHP.
        fakeSite();

        $this->actingAs($this->admin)->putJson(envUrl(), ['raw' => "APP_ENV=production\n"])->assertOk();

        $temporary = '/home/envowner/deployed-site/.env.panel-tmp';
        $commands = envCommands();
        $chown = $commands->search(['chown', '-h', 'envowner:www-data', $temporary]);
        $chmod = $commands->search(['chmod', '0640', $temporary]);
        $swap = $commands->search(['mv', $temporary, '/home/envowner/deployed-site/.env']);

        expect($chown)->not->toBeFalse()
            ->and($chmod)->not->toBeFalse()
            ->and($swap)->not->toBeFalse()
            // Both before the rename: the file is never in place with the
            // wrong owner or mode.
            ->and($chown)->toBeLessThan($swap)
            ->and($chmod)->toBeLessThan($swap)
            ->and($commands->contains(fn (array $c) => in_array('0600', $c, true)))->toBeFalse();
    });
});

describe('one-click apps with a .env beside their code (bug #69)', function () {
    it('opens Mautic\'s real .env and names it Symfony, not Node.js', function () {
        $this->application->forceFill(['site_type' => 'mautic', 'slug' => 'mautic'])->save();
        // Where Mautic really keeps it: inside the document root, beside its
        // code. Mautic also ships a package.json for its asset build, which
        // the detector used to see first.
        $this->disk = ['/home/envowner/mautic/public_html/.env' => "APP_ENV=prod\nAPP_DEBUG=0\n"];
        $this->present = ['/home/envowner/mautic/public_html/bin/console', '/home/envowner/mautic/public_html/package.json'];
        fakeSite();

        $response = $this->actingAs($this->admin)->getJson(envUrl())->assertOk();

        expect($response->json('environment.path'))->toBe('/home/envowner/mautic/public_html/.env')
            ->and($response->json('environment.raw'))->toBe("APP_ENV=prod\nAPP_DEBUG=0\n")
            ->and($response->json('environment.framework'))->toBe('symfony')
            ->and($response->json('environment.framework_title'))->toBe('Symfony');
    });

    it('opens Akaunting\'s real .env as a Laravel application', function () {
        $this->application->forceFill(['site_type' => 'akaunting', 'slug' => 'akaunting'])->save();
        $this->disk = ['/home/envowner/akaunting/public_html/.env' => "APP_NAME=Akaunting\nDB_PASSWORD=secret\n"];
        $this->present = ['/home/envowner/akaunting/public_html/artisan'];
        fakeSite();

        $response = $this->actingAs($this->admin)->getJson(envUrl())->assertOk();

        expect($response->json('environment.path'))->toBe('/home/envowner/akaunting/public_html/.env')
            ->and($response->json('environment.raw'))->toContain('DB_PASSWORD=secret')
            ->and($response->json('environment.framework'))->toBe('laravel');
    });
});

describe('the key an application encrypts its data with (bug #17)', function () {
    beforeEach(function () {
        $this->application->forceFill(['site_type' => 'n8n', 'slug' => 'flows', 'serving_profile' => 'node'])->save();
        $this->env = '/home/envowner/flows/public_html/.env';
        $this->disk = [$this->env => "N8N_ENCRYPTION_KEY=\"0123456789abcdef0123\"\nN8N_PORT=5678\n"];
        $this->present = [];
    });

    it('refuses to change n8n\'s encryption key', function () {
        fakeSite();

        // Accepted before, and every credential saved in n8n became unreadable,
        // with no error until a workflow next ran.
        $this->actingAs($this->admin)->putJson(envUrl(), ['raw' => "N8N_ENCRYPTION_KEY=\"ffffffffffffffffffff\"\nN8N_PORT=5678\n"])
            ->assertUnprocessable()
            ->assertJsonPath('errors.raw.0', __('errors/application.environment_key_locked', ['key' => 'N8N_ENCRYPTION_KEY']));

        expect($this->written)->toBeNull();
    });

    it('refuses to remove it or empty it', function (string $raw) {
        fakeSite();

        $this->actingAs($this->admin)->putJson(envUrl(), ['raw' => $raw])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('raw');

        expect($this->written)->toBeNull();
    })->with([
        'removed' => ["N8N_PORT=5678\n"],
        'emptied' => ["N8N_ENCRYPTION_KEY=\nN8N_PORT=5678\n"],
        'a second, different value' => ["N8N_ENCRYPTION_KEY=\"0123456789abcdef0123\"\nN8N_ENCRYPTION_KEY=other\n"],
    ]);

    it('still saves every other change, however the key is quoted', function () {
        fakeSite();

        $raw = "N8N_PORT=5679\nN8N_ENCRYPTION_KEY='0123456789abcdef0123'\n";

        $this->actingAs($this->admin)->putJson(envUrl(), ['raw' => $raw])->assertOk();

        expect($this->written)->toBe($raw);
    });

    it('lets a file with no key yet be given one', function (string $current) {
        $this->disk = [$this->env => $current];
        fakeSite();

        $this->actingAs($this->admin)->putJson(envUrl(), ['raw' => "N8N_PORT=5678\nN8N_ENCRYPTION_KEY=newkey0123456789\n"])
            ->assertOk();
    })->with([
        'no line' => ["N8N_PORT=5678\n"],
        'an empty line' => ["N8N_PORT=5678\nN8N_ENCRYPTION_KEY=\n"],
    ]);

    it('refuses to restore a backup that holds another key', function () {
        $this->disk['/home/envowner/flows/public_html/.env.bak-20260101-000000'] = "N8N_ENCRYPTION_KEY=\"oldoldoldoldoldold\"\n";
        fakeSite();

        // A copy from before a key change undoes it just the same.
        $this->actingAs($this->admin)->postJson(envUrl('/restore'), ['backup' => '.env.bak-20260101-000000'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.backup.0', __('errors/application.environment_key_locked_backup', ['key' => 'N8N_ENCRYPTION_KEY']));

        expect($this->written)->toBeNull();
    });

    it('restores a backup that holds the same key', function () {
        $this->disk['/home/envowner/flows/public_html/.env.bak-20260101-000000'] = "N8N_ENCRYPTION_KEY=0123456789abcdef0123\nN8N_PORT=1\n";
        fakeSite();

        $this->actingAs($this->admin)->postJson(envUrl('/restore'), ['backup' => '.env.bak-20260101-000000'])
            ->assertOk();

        expect($this->written)->toContain('N8N_PORT=1');
    });

    it('locks APP_KEY on the Laravel apps the panel installs', function (string $type, string $path) {
        $this->application->forceFill(['site_type' => $type, 'slug' => 'books', 'serving_profile' => 'php'])->save();
        $this->disk = [$path => "APP_KEY=base64:abc\n"];
        $this->present = ['/home/envowner/books/public_html/artisan'];
        fakeSite();

        $this->actingAs($this->admin)->putJson(envUrl(), ['raw' => "APP_KEY=base64:xyz\n"])
            ->assertUnprocessable()
            ->assertJsonPath('errors.raw.0', __('errors/application.environment_key_locked', ['key' => 'APP_KEY']));
    })->with([
        ['akaunting', '/home/envowner/books/public_html/.env'],
        // Served from its own /public, so the code is the app root.
        ['statamic', '/home/envowner/books/.env'],
    ]);

    it('leaves a git site\'s APP_KEY to its owner', function () {
        // The code is theirs; so is deciding to rotate its key.
        $this->application->forceFill(['site_type' => 'git', 'slug' => 'deployed-site', 'serving_profile' => 'php'])->save();
        $this->disk = ['/home/envowner/deployed-site/.env' => "APP_KEY=base64:abc\n"];
        $this->present = ['/home/envowner/deployed-site/public_html/artisan'];
        fakeSite();

        $this->actingAs($this->admin)->putJson(envUrl(), ['raw' => "APP_KEY=base64:xyz\n"])->assertOk();
    });
});
