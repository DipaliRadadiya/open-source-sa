<?php

use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\SystemUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;

/**
 * Fake server state, held statically for the same reason ApplicationPhpTest
 * holds PoolFake statically: Pest's `test()` proxy does not reliably carry
 * writes made from inside an HTTP request back to the test.
 */
class FixPermissionsFake
{
    /** @var array<int, string> every command the panel ran */
    public static array $ran = [];

    /** Whether every command the panel runs succeeds. */
    public static bool $ok = true;

    /** Whether the site has an `.env` on disk. */
    public static bool $envExists = false;

    public static function reset(): void
    {
        self::$ran = [];
        self::$ok = true;
        self::$envExists = false;
    }
}

/*
 * "My site says permission denied" is the problem this button exists to
 * solve, now that sites run under their own Linux user rather than shared
 * www-data. So the tests that matter are: does it reset ownership across the
 * whole site, does it leave the site readable by the web server it still
 * needs to be readable by, and does it re-tighten the two paths that must
 * stay narrower than the rest of the tree.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();

    $systemUser = SystemUser::create(['username' => 'siteowner', 'home_path' => '/home/siteowner']);

    $this->application = Application::create([
        'system_user_id' => $systemUser->id,
        'name' => 'Shop',
        'domain' => 'shop.test',
        'site_type' => 'php',
        'serving_profile' => 'php',
        'status' => 'active',
        'web_root' => '/',
        'php_version' => '8.4',
    ]);

    FixPermissionsFake::reset();
});

function fakeFileServer(): void
{
    Process::fake(function ($process) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;
        [$binary] = $args;

        FixPermissionsFake::$ran[] = implode(' ', $args);

        if ($binary === 'test' && ($args[1] ?? '') === '-f') {
            return Process::result(exitCode: FixPermissionsFake::$envExists ? 0 : 1);
        }

        return Process::result(
            exitCode: FixPermissionsFake::$ok ? 0 : 1,
            errorOutput: FixPermissionsFake::$ok ? '' : 'permission denied',
        );
    });
}

function fixUrl(): string
{
    return '/api/applications/'.test()->application->id.'/fix-permissions';
}

it('resets ownership and modes across the whole site', function () {
    fakeFileServer();

    $this->actingAs($this->admin)->postJson(fixUrl())->assertOk();

    $root = '/home/siteowner/shop.test';

    expect(FixPermissionsFake::$ran)->toContain("chown -R siteowner:siteowner {$root}")
        // 0755/0644, not tighter: nginx serves static assets straight off disk
        // as its own user, and is not a member of the site's group. Anything
        // tighter breaks every image and script on the site.
        ->and(collect(FixPermissionsFake::$ran)->contains(fn (string $c) => str_contains($c, "find {$root} -type d -exec chmod 0755")))->toBeTrue()
        ->and(collect(FixPermissionsFake::$ran)->contains(fn (string $c) => str_contains($c, "find {$root} -type f -exec chmod 0644")))->toBeTrue();
});

it('logs the action', function () {
    fakeFileServer();

    $this->actingAs($this->admin)->postJson(fixUrl())->assertOk();

    expect(ActivityLog::where('type', 'application')->where('action', 'permissions_fixed')->exists())->toBeTrue();
});

it('re-tightens .env back to 0600 when one exists', function () {
    fakeFileServer();
    FixPermissionsFake::$envExists = true;

    $this->actingAs($this->admin)->postJson(fixUrl())->assertOk();

    expect(FixPermissionsFake::$ran)->toContain('chmod 0600 /home/siteowner/shop.test/.env');
});

it('does not touch .env when the site has none', function () {
    fakeFileServer();

    $this->actingAs($this->admin)->postJson(fixUrl())->assertOk();

    expect(FixPermissionsFake::$ran)->not->toContain('chmod 0600 /home/siteowner/shop.test/.env');
});

it('re-tightens the session directory once the site is isolated', function () {
    fakeFileServer();
    // Not mass-assignable (isolation only happens through the isolate
    // endpoint), so set the column directly rather than via update().
    $this->application->isolated_at = now();
    $this->application->save();

    $this->actingAs($this->admin)->postJson(fixUrl())->assertOk();

    expect(FixPermissionsFake::$ran)->toContain('chmod -R 0700 /home/siteowner/shop.test/.panel/sessions');
});

it('leaves the session directory alone for a site that is not isolated', function () {
    fakeFileServer();

    $this->actingAs($this->admin)->postJson(fixUrl())->assertOk();

    expect(collect(FixPermissionsFake::$ran)->contains(fn (string $c) => str_contains($c, '.panel/sessions')))->toBeFalse();
});

it('reports a server failure and does not log success', function () {
    fakeFileServer();
    FixPermissionsFake::$ok = false;

    $this->actingAs($this->admin)->postJson(fixUrl())->assertStatus(500);

    expect(ActivityLog::where('action', 'permissions_fixed')->exists())->toBeFalse();
});

describe('permissions', function () {
    it('refuses a viewer who cannot manage', function () {
        fakeFileServer();
        $user = User::factory()->create();
        grantPermission($user, 'app_file', view: true, manage: false);

        $this->actingAs($user)->postJson(fixUrl())->assertForbidden();
    });

    it('denies an unauthenticated caller', function () {
        fakeFileServer();

        $this->postJson(fixUrl())->assertUnauthorized();
    });
});

/**
 * A tiny virtual filesystem for the browse/view/edit/download endpoints,
 * keyed by path relative to the site root (`''` is the root itself).
 */
class FileBrowserFake
{
    /** @var array<string, array{type: string, size?: int, content?: string}> */
    public static array $fs = [];

    /** @var array<int, string> every command the panel ran, runuser prefix included */
    public static array $ran = [];

    /**
     * Registered "zip contents" keyed by the archive's path relative to the
     * site root — a real zip is never written to disk for these tests, only
     * the `unzip -Z`/`unzip -o` output it would produce.
     *
     * @var array<string, array<int, array{type: string, size: int, name: string}>>
     */
    public static array $archives = [];

    public static function reset(): void
    {
        self::$fs = ['' => ['type' => 'd']];
        self::$ran = [];
        self::$archives = [];
    }
}

/*
 * A file browser is the first feature in this codebase to accept a
 * client-supplied path at all — everything else (logs, .env) uses a fixed or
 * keyed path precisely to avoid this surface. Two things make it safe: the
 * validator refuses anything that isn't a plain relative path, and every
 * command against the result runs as the site's own Linux user rather than
 * the panel's root — so the tests that matter are exactly those two.
 */

function fakeFileBrowserServer(): void
{
    $root = '/home/siteowner/shop.test';

    Process::fake(function ($process) use ($root) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        FileBrowserFake::$ran[] = implode(' ', $args);

        // Every browser command is wrapped as `runuser -u siteowner --`;
        // unwrap it here to work out what was actually asked for.
        $inner = ($args[0] ?? null) === 'runuser' ? array_slice($args, 4) : $args;
        $binary = $inner[0] ?? null;

        $relative = fn (string $target): string => $target === $root ? '' : ltrim(substr($target, strlen($root)), '/');

        if ($binary === 'find' && ($inner[2] ?? null) === '-maxdepth' && ($inner[3] ?? null) === '0') {
            $rel = $relative($inner[1]);

            if (! array_key_exists($rel, FileBrowserFake::$fs)) {
                return Process::result(exitCode: 1, errorOutput: 'No such file or directory');
            }

            $entry = FileBrowserFake::$fs[$rel];

            return Process::result(output: $entry['type']."\t".($entry['size'] ?? 0));
        }

        if ($binary === 'find' && ($inner[2] ?? null) === '-mindepth') {
            $rel = $relative($inner[1]);
            $lines = [];

            foreach (FileBrowserFake::$fs as $path => $entry) {
                if ($path === '') {
                    continue;
                }

                $parent = str_contains($path, '/') ? substr($path, 0, (int) strrpos($path, '/')) : '';

                if ($parent !== $rel) {
                    continue;
                }

                $name = str_contains($path, '/') ? substr($path, (int) strrpos($path, '/') + 1) : $path;
                $lines[] = "{$name}\t{$entry['type']}\t".($entry['size'] ?? 0)."\t1700000000";
            }

            return Process::result(output: $lines === [] ? '' : implode("\n", $lines)."\n");
        }

        if ($binary === 'cat') {
            $entry = FileBrowserFake::$fs[$relative($inner[1])] ?? null;

            return Process::result(output: $entry['content'] ?? '');
        }

        if ($binary === 'tee') {
            $rel = $relative($inner[1]);
            FileBrowserFake::$fs[$rel] = [
                'type' => 'f',
                ...(FileBrowserFake::$fs[$rel] ?? []),
                'content' => $process->input ?? '',
            ];

            return Process::result(exitCode: 0);
        }

        if ($binary === 'unzip' && ($inner[1] ?? null) === '-Z') {
            $archiveRel = $relative($inner[2]);
            $entries = FileBrowserFake::$archives[$archiveRel] ?? null;

            if ($entries === null) {
                return Process::result(exitCode: 1, errorOutput: 'cannot find or open zipfile');
            }

            $lines = ['Archive:  x.zip', 'Zip file size: 1 bytes, number of entries: '.count($entries)];

            foreach ($entries as $e) {
                $perm = $e['type'].str_repeat('-', 9);
                $lines[] = "{$perm}  3.0 unx {$e['size']} tx stor 26-Aug-05 08:53 {$e['name']}";
            }

            $lines[] = count($entries).' files, 0 bytes uncompressed, 0 bytes compressed:  0.0%';

            return Process::result(output: implode("\n", $lines));
        }

        if ($binary === 'unzip' && ($inner[1] ?? null) === '-o') {
            // ['unzip', '-o', '-d', $target, $archive]
            $targetRel = $relative($inner[3]);
            $archiveRel = $relative($inner[4]);

            foreach (FileBrowserFake::$archives[$archiveRel] ?? [] as $e) {
                $name = rtrim($e['name'], '/');

                if ($name === '') {
                    continue;
                }

                $entryRel = $targetRel === '' ? $name : "{$targetRel}/{$name}";
                FileBrowserFake::$fs[$entryRel] = ['type' => $e['type'] === 'd' ? 'd' : 'f', 'size' => $e['size'], 'content' => 'extracted'];
            }

            return Process::result(exitCode: 0);
        }

        return Process::result(exitCode: 0);
    });
}

function filesUrl(string $suffix = ''): string
{
    return '/api/applications/'.test()->application->id.'/files'.$suffix;
}

describe('browsing', function () {
    beforeEach(function () {
        FileBrowserFake::reset();
        FileBrowserFake::$fs['index.php'] = ['type' => 'f', 'size' => 20, 'content' => '<?php echo "hi";'];
        FileBrowserFake::$fs['wp-content'] = ['type' => 'd'];
        FileBrowserFake::$fs['wp-content/uploads'] = ['type' => 'd'];
        FileBrowserFake::$fs['shortcut'] = ['type' => 'l'];
    });

    it('lists a directory with directories first, then alphabetically', function () {
        fakeFileBrowserServer();

        $response = $this->actingAs($this->admin)->getJson(filesUrl())->assertOk();

        expect($response->json('files.*.name'))->toBe(['wp-content', 'index.php', 'shortcut']);
        expect($response->json('files.0.type'))->toBe('dir')
            ->and($response->json('files.2.type'))->toBe('symlink');
    });

    it('runs every command as the site\'s own user, never as root', function () {
        fakeFileBrowserServer();

        $this->actingAs($this->admin)->getJson(filesUrl())->assertOk();

        expect(FileBrowserFake::$ran)->each->toStartWith('runuser -u siteowner --');
    });

    it('rejects a path that escapes the site root before touching the server', function () {
        fakeFileBrowserServer();

        $this->actingAs($this->admin)
            ->getJson(filesUrl('?path=../../etc/passwd'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('path');

        expect(FileBrowserFake::$ran)->toBe([]);
    });

    it('rejects an absolute path', function () {
        fakeFileBrowserServer();

        $this->actingAs($this->admin)
            ->getJson(filesUrl('?path=/etc/passwd'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('path');
    });

    it('reads a text file', function () {
        fakeFileBrowserServer();

        $response = $this->actingAs($this->admin)
            ->getJson(filesUrl('/content?path=index.php'))
            ->assertOk();

        // The test Process fake normalises fake output to end with exactly
        // one newline, same as a real `cat` would for a file that has one.
        expect($response->json('content'))->toBe("<?php echo \"hi\";\n");
    });

    it('refuses a file larger than the size cap', function () {
        fakeFileBrowserServer();
        FileBrowserFake::$fs['big.log'] = ['type' => 'f', 'size' => 10 * 1024 * 1024, 'content' => 'irrelevant'];

        $this->actingAs($this->admin)
            ->getJson(filesUrl('/content?path=big.log'))
            ->assertStatus(422);
    });

    it('refuses a binary file for viewing', function () {
        fakeFileBrowserServer();
        FileBrowserFake::$fs['photo.png'] = ['type' => 'f', 'size' => 4, 'content' => "\x00\x01\x02\x03"];

        $this->actingAs($this->admin)
            ->getJson(filesUrl('/content?path=photo.png'))
            ->assertStatus(422);
    });

    it('404s for a path that does not exist', function () {
        fakeFileBrowserServer();

        $this->actingAs($this->admin)
            ->getJson(filesUrl('/content?path=nope.txt'))
            ->assertNotFound();
    });

    it('saves an edit to an existing file as the site user', function () {
        fakeFileBrowserServer();

        $this->actingAs($this->admin)
            ->putJson(filesUrl('/content'), ['path' => 'index.php', 'content' => '<?php echo "bye";'])
            ->assertOk();

        expect(FileBrowserFake::$fs['index.php']['content'])->toBe('<?php echo "bye";')
            ->and(collect(FileBrowserFake::$ran)->contains(fn (string $c) => str_starts_with($c, 'runuser -u siteowner -- tee')))->toBeTrue()
            ->and(ActivityLog::where('type', 'application')->where('action', 'file_edited')->exists())->toBeTrue();
    });

    it('refuses to edit a path that does not exist — this is edit, not create', function () {
        fakeFileBrowserServer();

        $this->actingAs($this->admin)
            ->putJson(filesUrl('/content'), ['path' => 'new.txt', 'content' => 'hello'])
            ->assertNotFound();
    });

    it('downloads a file with attachment headers, never sniffed content-type', function () {
        fakeFileBrowserServer();

        $response = $this->actingAs($this->admin)
            ->getJson(filesUrl('/download?path=index.php'))
            ->assertOk();

        expect($response->headers->get('Content-Type'))->toStartWith('application/octet-stream')
            ->and($response->headers->get('Content-Disposition'))->toContain('attachment')
            ->and($response->headers->get('Content-Disposition'))->toContain('index.php')
            ->and($response->getContent())->toBe("<?php echo \"hi\";\n");
    });

    it('refuses to download a directory', function () {
        fakeFileBrowserServer();

        $this->actingAs($this->admin)
            ->getJson(filesUrl('/download?path=wp-content'))
            ->assertNotFound();
    });

    describe('permissions', function () {
        it('lets a viewer browse, view and download but not edit', function () {
            fakeFileBrowserServer();
            $user = User::factory()->create();
            grantPermission($user, 'app_file', view: true, manage: false);

            $this->actingAs($user)->getJson(filesUrl())->assertOk();
            $this->actingAs($user)->getJson(filesUrl('/content?path=index.php'))->assertOk();
            $this->actingAs($user)->getJson(filesUrl('/download?path=index.php'))->assertOk();
            $this->actingAs($user)
                ->putJson(filesUrl('/content'), ['path' => 'index.php', 'content' => 'x'])
                ->assertForbidden();
        });

        it('denies an unauthenticated caller', function () {
            fakeFileBrowserServer();

            $this->getJson(filesUrl())->assertUnauthorized();
        });
    });
});

describe('uploading', function () {
    beforeEach(function () {
        FileBrowserFake::reset();
        FileBrowserFake::$fs['wp-content'] = ['type' => 'd'];
        FileBrowserFake::$fs['wp-content/plugins'] = ['type' => 'd'];
    });

    it('writes an uploaded file as the site\'s own user', function () {
        fakeFileBrowserServer();
        $file = UploadedFile::fake()->createWithContent('thing.zip', 'zip-bytes');

        $this->actingAs($this->admin)
            ->post(filesUrl('/upload'), ['path' => 'wp-content/plugins/thing.zip', 'file' => $file])
            ->assertOk();

        expect(FileBrowserFake::$fs['wp-content/plugins/thing.zip']['content'])->toBe('zip-bytes')
            ->and(collect(FileBrowserFake::$ran)->contains(fn (string $c) => str_starts_with($c, 'runuser -u siteowner -- tee')))->toBeTrue()
            ->and(ActivityLog::where('type', 'application')->where('action', 'file_uploaded')->exists())->toBeTrue();
    });

    it('never builds the target from the uploaded file\'s own name', function () {
        fakeFileBrowserServer();
        // The client picks the target path; the file's own (client-supplied,
        // equally untrusted) original name must never leak into it.
        $file = UploadedFile::fake()->createWithContent('../../etc/evil.php', 'x');

        $this->actingAs($this->admin)
            ->post(filesUrl('/upload'), ['path' => 'wp-content/plugins/safe-name.txt', 'file' => $file])
            ->assertOk();

        expect(FileBrowserFake::$fs)->toHaveKey('wp-content/plugins/safe-name.txt')
            ->and(FileBrowserFake::$fs)->not->toHaveKey('etc/evil.php');
    });

    it('rejects a target path that escapes the site root', function () {
        fakeFileBrowserServer();
        $file = UploadedFile::fake()->createWithContent('thing.zip', 'x');

        $this->actingAs($this->admin)
            ->post(filesUrl('/upload'), ['path' => '../../etc/passwd', 'file' => $file])
            ->assertStatus(422)
            ->assertJsonValidationErrors('path');
    });

    it('refuses to upload into a directory that does not exist', function () {
        fakeFileBrowserServer();
        $file = UploadedFile::fake()->createWithContent('thing.zip', 'x');

        $this->actingAs($this->admin)
            ->post(filesUrl('/upload'), ['path' => 'no-such-dir/thing.zip', 'file' => $file])
            ->assertNotFound();
    });

    it('overwrites an existing file at that path', function () {
        fakeFileBrowserServer();
        FileBrowserFake::$fs['wp-content/plugins/thing.zip'] = ['type' => 'f', 'content' => 'old'];
        $file = UploadedFile::fake()->createWithContent('thing.zip', 'new');

        $this->actingAs($this->admin)
            ->post(filesUrl('/upload'), ['path' => 'wp-content/plugins/thing.zip', 'file' => $file])
            ->assertOk();

        expect(FileBrowserFake::$fs['wp-content/plugins/thing.zip']['content'])->toBe('new');
    });

    it('refuses a file above the upload size cap', function () {
        fakeFileBrowserServer();
        $file = UploadedFile::fake()->create('thing.zip', (50 * 1024) + 1);

        $this->actingAs($this->admin)
            ->post(filesUrl('/upload'), ['path' => 'wp-content/plugins/thing.zip', 'file' => $file])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    });

    it('refuses a viewer who cannot manage', function () {
        fakeFileBrowserServer();
        $user = User::factory()->create();
        grantPermission($user, 'app_file', view: true, manage: false);
        $file = UploadedFile::fake()->createWithContent('thing.zip', 'x');

        $this->actingAs($user)
            ->post(filesUrl('/upload'), ['path' => 'wp-content/plugins/thing.zip', 'file' => $file])
            ->assertForbidden();
    });
});

/*
 * Extract is where an untrusted archive meets the filesystem — the panel's
 * own Restores\Steps\ExtractArchive gets to run as root because it only ever
 * unpacks the panel's own backups; this one takes a file a client uploaded,
 * so every entry is listed and judged before anything is written.
 */

describe('extracting', function () {
    beforeEach(function () {
        FileBrowserFake::reset();
        FileBrowserFake::$fs['wp-content'] = ['type' => 'd'];
        FileBrowserFake::$fs['wp-content/plugins'] = ['type' => 'd'];
        FileBrowserFake::$fs['wp-content/plugins/thing.zip'] = ['type' => 'f', 'size' => 100];
    });

    function extractPayload(string $path = 'wp-content/plugins/thing.zip', string $target = 'wp-content/plugins'): array
    {
        return ['path' => $path, 'target' => $target];
    }

    it('extracts a well-formed archive in place, as the site\'s own user', function () {
        fakeFileBrowserServer();
        FileBrowserFake::$archives['wp-content/plugins/thing.zip'] = [
            ['type' => 'd', 'size' => 0, 'name' => 'my-plugin/'],
            ['type' => '-', 'size' => 12, 'name' => 'my-plugin/plugin.php'],
        ];

        $this->actingAs($this->admin)
            ->postJson(filesUrl('/extract'), extractPayload())
            ->assertOk();

        expect(FileBrowserFake::$fs)->toHaveKey('wp-content/plugins/my-plugin/plugin.php')
            ->and(collect(FileBrowserFake::$ran)->contains(fn (string $c) => str_starts_with($c, 'runuser -u siteowner -- unzip -o')))->toBeTrue()
            ->and(ActivityLog::where('type', 'application')->where('action', 'files_extracted')->exists())->toBeTrue();
    });

    it('overwrites an existing file at the extracted path', function () {
        fakeFileBrowserServer();
        FileBrowserFake::$fs['wp-content/plugins/my-plugin/plugin.php'] = ['type' => 'f', 'content' => 'old'];
        FileBrowserFake::$archives['wp-content/plugins/thing.zip'] = [
            ['type' => '-', 'size' => 12, 'name' => 'my-plugin/plugin.php'],
        ];

        $this->actingAs($this->admin)->postJson(filesUrl('/extract'), extractPayload())->assertOk();

        expect(FileBrowserFake::$fs['wp-content/plugins/my-plugin/plugin.php']['content'])->toBe('extracted');
    });

    it('refuses a zip-slip entry before extracting anything', function () {
        fakeFileBrowserServer();
        FileBrowserFake::$archives['wp-content/plugins/thing.zip'] = [
            ['type' => '-', 'size' => 4, 'name' => '../../etc/evil.txt'],
        ];

        $this->actingAs($this->admin)
            ->postJson(filesUrl('/extract'), extractPayload())
            ->assertStatus(422);

        expect(collect(FileBrowserFake::$ran)->contains(fn (string $c) => str_starts_with($c, 'runuser -u siteowner -- unzip -o')))->toBeFalse();
    });

    it('refuses an archive entry that is a symlink', function () {
        fakeFileBrowserServer();
        FileBrowserFake::$archives['wp-content/plugins/thing.zip'] = [
            ['type' => 'l', 'size' => 5, 'name' => 'leak'],
        ];

        $this->actingAs($this->admin)
            ->postJson(filesUrl('/extract'), extractPayload())
            ->assertStatus(422);
    });

    it('refuses an archive that would be too large once extracted', function () {
        fakeFileBrowserServer();
        FileBrowserFake::$archives['wp-content/plugins/thing.zip'] = [
            ['type' => '-', 'size' => 300 * 1024 * 1024, 'name' => 'huge.bin'],
        ];

        $this->actingAs($this->admin)
            ->postJson(filesUrl('/extract'), extractPayload())
            ->assertStatus(422);
    });

    it('refuses an archive with too many entries', function () {
        fakeFileBrowserServer();
        FileBrowserFake::$archives['wp-content/plugins/thing.zip'] = array_map(
            fn (int $i) => ['type' => '-', 'size' => 1, 'name' => "file{$i}.txt"],
            range(1, 10001),
        );

        $this->actingAs($this->admin)
            ->postJson(filesUrl('/extract'), extractPayload())
            ->assertStatus(422);
    });

    it('refuses an unreadable or corrupt archive', function () {
        fakeFileBrowserServer();
        // Deliberately not registered in FileBrowserFake::$archives.

        $this->actingAs($this->admin)
            ->postJson(filesUrl('/extract'), extractPayload())
            ->assertStatus(422);
    });

    it('refuses anything that is not a .zip by extension', function () {
        fakeFileBrowserServer();
        FileBrowserFake::$fs['wp-content/plugins/thing.tar.gz'] = ['type' => 'f', 'size' => 100];

        $this->actingAs($this->admin)
            ->postJson(filesUrl('/extract'), extractPayload('wp-content/plugins/thing.tar.gz'))
            ->assertStatus(422);
    });

    it('404s when the target directory does not exist', function () {
        fakeFileBrowserServer();
        FileBrowserFake::$archives['wp-content/plugins/thing.zip'] = [
            ['type' => '-', 'size' => 4, 'name' => 'file.txt'],
        ];

        $this->actingAs($this->admin)
            ->postJson(filesUrl('/extract'), extractPayload(target: 'no-such-dir'))
            ->assertNotFound();
    });

    it('rejects a target path that escapes the site root', function () {
        fakeFileBrowserServer();

        $this->actingAs($this->admin)
            ->postJson(filesUrl('/extract'), extractPayload(target: '../../etc'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('target');
    });

    it('refuses a viewer who cannot manage', function () {
        fakeFileBrowserServer();
        $user = User::factory()->create();
        grantPermission($user, 'app_file', view: true, manage: false);

        $this->actingAs($user)
            ->postJson(filesUrl('/extract'), extractPayload())
            ->assertForbidden();
    });
});
