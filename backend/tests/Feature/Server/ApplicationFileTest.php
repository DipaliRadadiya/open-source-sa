<?php

use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Server\Applications\FileBrowser;
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

    $this->application = Application::forceCreate([
        'system_user_id' => $systemUser->id,
        'name' => 'Shop',
        // The site directory and the web-server config are named after the
        // slug, never the domain — a domain is mutable and not unique.
        'slug' => 'shop',
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

    $root = '/home/siteowner/shop/public_html';

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

    expect(FixPermissionsFake::$ran)->toContain('chmod 0600 /home/siteowner/shop/.env');
});

it('does not touch .env when the site has none', function () {
    fakeFileServer();

    $this->actingAs($this->admin)->postJson(fixUrl())->assertOk();

    expect(FixPermissionsFake::$ran)->not->toContain('chmod 0600 /home/siteowner/shop/.env');
});

it('re-tightens the session directory once the site is isolated', function () {
    fakeFileServer();
    // Not mass-assignable (isolation only happens through the isolate
    // endpoint), so set the column directly rather than via update().
    $this->application->isolated_at = now();
    $this->application->save();

    $this->actingAs($this->admin)->postJson(fixUrl())->assertOk();

    expect(FixPermissionsFake::$ran)->toContain('chmod -R 0700 /home/siteowner/shop/.panel/sessions');
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

    // The cwd every command ran with, index-aligned with $ran.
    /** @var array<int, string> */
    public static array $cwds = [];

    public static function reset(): void
    {
        self::$fs = ['' => ['type' => 'd']];
        self::$ran = [];
        self::$archives = [];
        self::$cwds = [];
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
    $root = '/home/siteowner/shop/public_html';

    Process::fake(function ($process) use ($root) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

        FileBrowserFake::$ran[] = implode(' ', $args);
        FileBrowserFake::$cwds[] = $process->path;

        // Every browser command is wrapped as `runuser -u siteowner --`;
        // unwrap it here to work out what was actually asked for.
        $inner = ($args[0] ?? null) === 'runuser' ? array_slice($args, 4) : $args;
        $binary = $inner[0] ?? null;

        // Paths under the web root are keyed relative to it; anything above
        // it — the panel's own `.panel` directory, where file backups now
        // live — is keyed by its absolute path. Blindly substr()-ing the root
        // off a path that does not start with it produced mid-string garbage,
        // which is why a backup written outside the root appeared to vanish.
        $relative = fn (string $target): string => match (true) {
            $target === $root => '',
            str_starts_with($target, $root.'/') => ltrim(substr($target, strlen($root)), '/'),
            default => $target,
        };

        // `-maxdepth 0` stats the named paths themselves. One target with
        // `%y\t%s` is stat(); many with `%p\t%y\t%m` is statMany(), which
        // real find answers in a single call — the whole point of bulk.
        $maxdepth = array_search('-maxdepth', $inner, true);

        if ($binary === 'find' && $maxdepth !== false && ($inner[$maxdepth + 1] ?? null) === '0') {
            $targets = array_slice($inner, 1, $maxdepth - 1);
            $format = $inner[array_search('-printf', $inner, true) + 1] ?? '';

            if (str_starts_with($format, '%p')) {
                $lines = [];

                foreach ($targets as $target) {
                    $rel = $relative($target);

                    if (! array_key_exists($rel, FileBrowserFake::$fs)) {
                        continue;
                    }

                    $entry = FileBrowserFake::$fs[$rel];
                    $mode = $entry['mode'] ?? ($entry['type'] === 'd' ? '755' : '644');
                    $lines[] = $target."\t".$entry['type']."\t".$mode;
                }

                // Real find still exits non-zero when any path was missing,
                // while printing the ones that were there.
                return Process::result(
                    exitCode: count($lines) === count($targets) ? 0 : 1,
                    output: $lines === [] ? '' : implode("\n", $lines)."\n",
                    errorOutput: count($lines) === count($targets) ? '' : 'No such file or directory',
                );
            }

            $rel = $relative($targets[0] ?? '');

            if (! array_key_exists($rel, FileBrowserFake::$fs)) {
                return Process::result(exitCode: 1, errorOutput: 'No such file or directory');
            }

            $entry = FileBrowserFake::$fs[$rel];

            return Process::result(output: $entry['type']."\t".($entry['size'] ?? 0));
        }

        $statLine = function (string $name, array $entry): string {
            $mode = $entry['mode'] ?? ($entry['type'] === 'd' ? '755' : '644');
            $owner = $entry['owner'] ?? 'siteowner';
            $group = $entry['group'] ?? 'siteowner';

            // %Y is the type after following the link — the same as %y for
            // anything that is not one — and %l the target, empty otherwise.
            $targetType = $entry['target_type'] ?? $entry['type'];
            $linkTarget = $entry['link_target'] ?? '';

            return "{$name}\t{$entry['type']}\t".($entry['size'] ?? 0)
                ."\t1700000000\t{$mode}\t{$owner}\t{$group}\t{$targetType}\t{$linkTarget}";
        };

        // `find <trash> -mindepth 2 -printf '%P\n'` — everything under the
        // trash root, path relative to it. Keys in the fake are absolute for
        // anything above the web root, so this strips the root prefix the same
        // way real find's %P does.
        if ($binary === 'find' && ($inner[2] ?? null) === '-mindepth' && ($inner[3] ?? null) === '2') {
            $root = rtrim($inner[1], '/');
            $lines = [];

            foreach (array_keys(FileBrowserFake::$fs) as $path) {
                if (! str_starts_with($path, $root.'/')) {
                    continue;
                }

                $suffix = substr($path, strlen($root) + 1);

                // -mindepth 2: at least two levels below the root.
                if (! str_contains($suffix, '/')) {
                    continue;
                }

                $lines[] = $suffix;
            }

            return Process::result(output: $lines === [] ? '' : implode("\n", $lines)."\n");
        }

        if ($binary === 'find' && ($inner[2] ?? null) === '-mindepth' && ($inner[4] ?? null) === '-maxdepth') {
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
                $lines[] = $statLine($name, $entry);
            }

            return Process::result(output: $lines === [] ? '' : implode("\n", $lines)."\n");
        }

        if ($binary === 'find' && ($inner[2] ?? null) === '-mindepth' && ($inner[4] ?? null) === '-iname') {
            // ['find', $target, '-mindepth', '1', '-iname', $pattern, '-printf', $fmt]
            $rel = $relative($inner[1]);
            $needle = strtolower(stripcslashes(trim($inner[5] ?? '', '*')));
            $lines = [];

            foreach (FileBrowserFake::$fs as $path => $entry) {
                if ($path === '') {
                    continue;
                }

                if ($rel !== '' && $path !== $rel && ! str_starts_with($path, "{$rel}/")) {
                    continue;
                }

                $name = str_contains($path, '/') ? substr($path, (int) strrpos($path, '/') + 1) : $path;

                if ($needle !== '' && ! str_contains(strtolower($name), $needle)) {
                    continue;
                }

                // %P: relative to the search root, matching what real find prints.
                $relativeToScope = $rel === '' ? $path : substr($path, strlen($rel) + 1);
                $lines[] = $statLine($relativeToScope, $entry);
            }

            return Process::result(output: $lines === [] ? '' : implode("\n", $lines)."\n");
        }

        if ($binary === 'du' && ($inner[1] ?? null) === '-sb') {
            $rel = $relative($inner[2]);
            $total = 0;

            foreach (FileBrowserFake::$fs as $path => $entry) {
                if ($path === $rel || ($rel === '' && $path !== '') || str_starts_with($path, "{$rel}/")) {
                    $total += $entry['size'] ?? 0;
                }
            }

            return Process::result(output: "{$total}\t{$inner[2]}\n");
        }

        if ($binary === 'find' && ($inner[2] ?? null) === '-maxdepth' && ($inner[3] ?? null) === '1') {
            // FileBrowser::backups(): find <dir> -maxdepth 1 -name '<file>.bak-*' -printf '%f\n'
            $dirRel = $relative($inner[1]);
            $pattern = $inner[5] ?? '';
            $prefix = rtrim($pattern, '*');
            $names = [];

            foreach (FileBrowserFake::$fs as $path => $entry) {
                $parent = str_contains($path, '/') ? substr($path, 0, (int) strrpos($path, '/')) : '';

                if ($parent !== $dirRel) {
                    continue;
                }

                $name = str_contains($path, '/') ? substr($path, (int) strrpos($path, '/') + 1) : $path;

                if (str_starts_with($name, $prefix)) {
                    $names[] = $name;
                }
            }

            return Process::result(output: $names === [] ? '' : implode("\n", $names)."\n");
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

        if ($binary === 'tar' && ($inner[1] ?? null) === '-tvzf') {
            $archiveRel = $relative($inner[2]);
            $entries = FileBrowserFake::$archives[$archiveRel] ?? null;

            if ($entries === null) {
                return Process::result(exitCode: 1, errorOutput: 'not a gzip file');
            }

            $lines = [];

            foreach ($entries as $e) {
                $perm = $e['type'].str_repeat('-', 9);
                $name = $e['type'] === 'l' ? "{$e['name']} -> elsewhere" : $e['name'];
                $lines[] = "{$perm} root/root {$e['size']} 26-08-05 08:53 {$name}";
            }

            return Process::result(output: implode("\n", $lines));
        }

        if ($binary === 'tar' && ($inner[1] ?? null) === '-xzf') {
            // ['tar', '-xzf', $archive, '-C', $target]
            $archiveRel = $relative($inner[2]);
            $targetRel = $relative($inner[4]);

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

        if ($binary === 'chmod') {
            foreach (array_slice($inner, 2) as $target) {
                $rel = $relative($target);

                if (array_key_exists($rel, FileBrowserFake::$fs)) {
                    FileBrowserFake::$fs[$rel]['mode'] = $inner[1];
                }
            }

            return Process::result(exitCode: 0);
        }

        if ($binary === 'mkdir') {
            $dir = $inner[1] === '-p' ? $inner[2] : $inner[1];
            FileBrowserFake::$fs[$relative($dir)] = ['type' => 'd'];

            return Process::result(exitCode: 0);
        }

        if ($binary === 'mv' || $binary === 'cp') {
            // Last argument is the destination. When it is an existing
            // directory each source lands inside it under its own name, which
            // is how the real tools behave and how a bulk move is expressed;
            // otherwise it is a full target path for the single source.
            $arguments = array_slice($inner, $binary === 'cp' ? 2 : 1);
            $destination = $relative(array_pop($arguments));
            $intoDirectory = (FileBrowserFake::$fs[$destination]['type'] ?? null) === 'd';

            foreach ($arguments as $argument) {
                $sourceRel = $relative($argument);
                $targetRel = $intoDirectory
                    ? ltrim($destination.'/'.basename($sourceRel), '/')
                    : $destination;

                foreach (FileBrowserFake::$fs as $path => $entry) {
                    if ($path === $sourceRel) {
                        FileBrowserFake::$fs[$targetRel] = $entry;
                    } elseif (str_starts_with($path, "{$sourceRel}/")) {
                        FileBrowserFake::$fs[$targetRel.substr($path, strlen($sourceRel))] = $entry;
                    } else {
                        continue;
                    }

                    if ($binary === 'mv') {
                        unset(FileBrowserFake::$fs[$path]);
                    }
                }
            }

            return Process::result(exitCode: 0);
        }

        if ($binary === 'zip') {
            // ['zip', '-r', $target, $basename] — cwd is the source's parent.
            FileBrowserFake::$fs[$relative($inner[2])] = ['type' => 'f', 'size' => 1, 'content' => 'zipped'];

            return Process::result(exitCode: 0);
        }

        if ($binary === 'rm') {
            $flagged = in_array($inner[1] ?? '', ['-rf', '-f'], true);

            foreach (array_slice($inner, $flagged ? 2 : 1) as $target) {
                $targetRel = $relative($target);

                foreach (array_keys(FileBrowserFake::$fs) as $path) {
                    if ($path === $targetRel || str_starts_with($path, "{$targetRel}/")) {
                        unset(FileBrowserFake::$fs[$path]);
                    }
                }
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

    it('returns mode, owner and group for files and directories, but not for symlinks', function () {
        fakeFileBrowserServer();

        $response = $this->actingAs($this->admin)->getJson(filesUrl())->assertOk();

        expect($response->json('files.0.mode'))->toBe('755') // wp-content, a dir
            ->and($response->json('files.0.owner'))->toBe('siteowner')
            ->and($response->json('files.0.group'))->toBe('siteowner')
            ->and($response->json('files.1.mode'))->toBe('644') // index.php, a file
            ->and($response->json('files.2.mode'))->toBeNull() // shortcut, a symlink
            ->and($response->json('files.2.owner'))->toBeNull()
            ->and($response->json('files.2.group'))->toBeNull();
    });

    it('says where a symlink points', function () {
        FileBrowserFake::$fs['shortcut'] = [
            'type' => 'l',
            'link_target' => '../shared/uploads',
            'target_type' => 'd',
        ];
        fakeFileBrowserServer();

        $response = $this->actingAs($this->admin)->getJson(filesUrl())->assertOk();

        // Verbatim, not resolved: relative is what the link actually says, and
        // resolving it here would invent a path the user never wrote.
        expect($response->json('files.2.link_target'))->toBe('../shared/uploads')
            ->and($response->json('files.2.link_broken'))->toBeFalse();
    });

    it('flags a symlink whose target no longer exists', function () {
        // A dangling link is indistinguishable from a working one in a
        // listing, which is precisely when knowing matters — `find` reports
        // `N` for a target that is not there.
        FileBrowserFake::$fs['shortcut'] = [
            'type' => 'l',
            'link_target' => '/home/siteowner/gone',
            'target_type' => 'N',
        ];
        fakeFileBrowserServer();

        $response = $this->actingAs($this->admin)->getJson(filesUrl())->assertOk();

        expect($response->json('files.2.link_broken'))->toBeTrue()
            ->and($response->json('files.2.link_target'))->toBe('/home/siteowner/gone');
    });

    it('leaves link fields null for everything that is not a symlink', function () {
        fakeFileBrowserServer();

        $response = $this->actingAs($this->admin)->getJson(filesUrl())->assertOk();

        expect($response->json('files.0.link_target'))->toBeNull()
            ->and($response->json('files.0.link_broken'))->toBeNull()
            ->and($response->json('files.1.link_target'))->toBeNull();
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

    it('backs up the previous content before overwriting, above the web root', function () {
        fakeFileBrowserServer();

        $this->actingAs($this->admin)
            ->putJson(filesUrl('/content'), ['path' => 'index.php', 'content' => 'new content'])
            ->assertOk();

        $backupKeys = array_filter(
            array_keys(FileBrowserFake::$fs),
            fn (string $k) => str_starts_with($k, '/home/siteowner/shop/.panel/file-backups/index.php.bak-'),
        );

        expect($backupKeys)->toHaveCount(1);
        expect(FileBrowserFake::$fs[array_values($backupKeys)[0]]['content'])->toBe('<?php echo "hi";');
    });

    it('never writes a backup inside the served tree', function () {
        fakeFileBrowserServer();

        // wp-config.php is the case that matters: its backup holds live
        // database credentials, and a copy of it under the document root is
        // reachable over HTTP the moment any web server fails to block
        // dot-directories at that depth — which OpenLiteSpeed's rule, anchored
        // at the web root, may not.
        FileBrowserFake::$fs['wp-config.php'] = ['type' => 'f', 'size' => 20, 'content' => "define('DB_PASSWORD', 'hunter2');"];

        $this->actingAs($this->admin)
            ->putJson(filesUrl('/content'), ['path' => 'wp-config.php', 'content' => 'updated'])
            ->assertOk();

        $backups = array_filter(
            array_keys(FileBrowserFake::$fs),
            fn (string $k): bool => str_contains($k, 'wp-config.php.bak-'),
        );

        expect($backups)->toHaveCount(1);

        // Keyed absolutely means it resolved outside the web root — a relative
        // key is by definition inside it. Asserted this way round because the
        // point is not "which directory" but "not the served one".
        foreach ($backups as $key) {
            expect($key)->toStartWith('/home/siteowner/shop/.panel/')
                ->and($key)->not->toStartWith('.panel/');
        }
    });

    it('lists a file\'s backups alongside its content', function () {
        fakeFileBrowserServer();
        $this->actingAs($this->admin)
            ->putJson(filesUrl('/content'), ['path' => 'index.php', 'content' => 'v2'])
            ->assertOk();

        $response = $this->actingAs($this->admin)->getJson(filesUrl('/content?path=index.php'))->assertOk();

        expect($response->json('backups'))->toHaveCount(1)
            ->and($response->json('backups.0.name'))->toStartWith('index.php.bak-');
    });

    it('downloads a file with attachment headers, never sniffed content-type', function () {
        fakeFileBrowserServer();

        $response = $this->actingAs($this->admin)
            ->getJson(filesUrl('/download?path=index.php'))
            ->assertOk();

        expect($response->headers->get('Content-Type'))->toStartWith('application/octet-stream')
            ->and($response->headers->get('Content-Disposition'))->toContain('attachment')
            ->and($response->headers->get('Content-Disposition'))->toContain('index.php')
            // Streamed, so the body is produced by the callback rather than
            // held on the response — `getContent()` is false here by design.
            ->and($response->streamedContent())->toBe("<?php echo \"hi\";\n");
    });

    it('does not cap a download at the size a file has to be to fit in the editor', function () {
        fakeFileBrowserServer();

        // Comfortably past FileBrowser::MAX_BYTES, which bounds `read()`
        // because an editor has to hold the file, and used to bound this too
        // — so the panel accepted uploads it would then refuse to return.
        FileBrowserFake::$fs['huge.zip'] = ['type' => 'f', 'size' => 900 * 1024 * 1024];

        $response = $this->actingAs($this->admin)
            ->getJson(filesUrl('/download?path=huge.zip'))
            ->assertOk();

        expect($response->headers->get('Content-Length'))->toBe((string) (900 * 1024 * 1024))
            // Buffering the response would put back the memory cost that
            // streaming the read removed.
            ->and($response->headers->get('X-Accel-Buffering'))->toBe('no');
    });

    it('sends a filename both an old and a current client can read', function () {
        fakeFileBrowserServer();

        // `SafeRelativePath` already limits a path to [A-Za-z0-9._- ], so a
        // quote or a newline cannot reach this header at all — that rule is
        // the guard, not the encoding. What the encoding buys is the space:
        // an unencoded one is legal inside the quoted form but not in the
        // extended one, and clients disagree about the bare form.
        FileBrowserFake::$fs['my backup file.zip'] = ['type' => 'f', 'size' => 12];

        $disposition = $this->actingAs($this->admin)
            ->getJson(filesUrl('/download?path='.rawurlencode('my backup file.zip')))
            ->assertOk()
            ->headers->get('Content-Disposition');

        expect($disposition)->toContain('filename="my backup file.zip"')
            ->and($disposition)->toContain("filename*=UTF-8''my%20backup%20file.zip");
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

describe('searching', function () {
    beforeEach(function () {
        FileBrowserFake::reset();
        FileBrowserFake::$fs['index.php'] = ['type' => 'f', 'size' => 20];
        FileBrowserFake::$fs['wp-content'] = ['type' => 'd'];
        FileBrowserFake::$fs['wp-content/plugins'] = ['type' => 'd'];
        FileBrowserFake::$fs['wp-content/plugins/hello.php'] = ['type' => 'f', 'size' => 10];
        FileBrowserFake::$fs['wp-content/themes'] = ['type' => 'd'];
        FileBrowserFake::$fs['wp-content/themes/hello-theme'] = ['type' => 'd'];
    });

    it('finds matches anywhere in the tree, case-insensitively, with full relative paths', function () {
        fakeFileBrowserServer();

        $response = $this->actingAs($this->admin)
            ->getJson(filesUrl('/search?q=HELLO'))
            ->assertOk();

        expect($response->json('files.*.path'))->toBe(['wp-content/plugins/hello.php', 'wp-content/themes/hello-theme'])
            ->and($response->json('truncated'))->toBeFalse();
    });

    it('scopes the search to a subtree when path is given', function () {
        fakeFileBrowserServer();

        $response = $this->actingAs($this->admin)
            ->getJson(filesUrl('/search?q=hello&path=wp-content/plugins'))
            ->assertOk();

        expect($response->json('files.*.path'))->toBe(['wp-content/plugins/hello.php']);
    });

    it('rejects a scope path that escapes the site root before touching the server', function () {
        fakeFileBrowserServer();

        $this->actingAs($this->admin)
            ->getJson(filesUrl('/search?q=hello&path=../../etc'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('path');

        expect(FileBrowserFake::$ran)->toBe([]);
    });

    it('requires a non-empty query', function () {
        fakeFileBrowserServer();

        $this->actingAs($this->admin)
            ->getJson(filesUrl('/search?q='))
            ->assertStatus(422)
            ->assertJsonValidationErrors('q');
    });

    it('refuses a viewer with no app_file permission at all', function () {
        fakeFileBrowserServer();
        $user = User::factory()->create();

        $this->actingAs($user)->getJson(filesUrl('/search?q=hello'))->assertForbidden();
    });

    it('lets a view-only user search', function () {
        fakeFileBrowserServer();
        $user = User::factory()->create();
        grantPermission($user, 'app_file', view: true, manage: false);

        $this->actingAs($user)->getJson(filesUrl('/search?q=hello'))->assertOk();
    });
});

describe('folder size', function () {
    beforeEach(function () {
        FileBrowserFake::reset();
        FileBrowserFake::$fs['wp-content'] = ['type' => 'd'];
        FileBrowserFake::$fs['wp-content/uploads'] = ['type' => 'd'];
        FileBrowserFake::$fs['wp-content/uploads/photo.jpg'] = ['type' => 'f', 'size' => 1024];
        FileBrowserFake::$fs['wp-content/uploads/photo2.jpg'] = ['type' => 'f', 'size' => 2048];
    });

    it('sums the size of everything under the given folder', function () {
        fakeFileBrowserServer();

        $response = $this->actingAs($this->admin)
            ->getJson(filesUrl('/size?path=wp-content/uploads'))
            ->assertOk();

        expect($response->json('size'))->toBe(3072);
    });

    it('404s for a path that does not exist', function () {
        fakeFileBrowserServer();

        $this->actingAs($this->admin)
            ->getJson(filesUrl('/size?path=no-such-dir'))
            ->assertNotFound();
    });

    it('rejects a path that escapes the site root before touching the server', function () {
        fakeFileBrowserServer();

        $this->actingAs($this->admin)
            ->getJson(filesUrl('/size?path=../../etc'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('path');

        expect(FileBrowserFake::$ran)->toBe([]);
    });

    it('denies an unauthenticated caller', function () {
        fakeFileBrowserServer();

        $this->getJson(filesUrl('/size?path=wp-content/uploads'))->assertUnauthorized();
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

    it('also extracts a .tar.gz archive, in place, as the site\'s own user', function () {
        fakeFileBrowserServer();
        FileBrowserFake::$fs['wp-content/plugins/thing.tar.gz'] = ['type' => 'f', 'size' => 100];
        FileBrowserFake::$archives['wp-content/plugins/thing.tar.gz'] = [
            ['type' => '-', 'size' => 12, 'name' => 'my-plugin/plugin.php'],
        ];

        $this->actingAs($this->admin)
            ->postJson(filesUrl('/extract'), extractPayload('wp-content/plugins/thing.tar.gz'))
            ->assertOk();

        expect(FileBrowserFake::$fs)->toHaveKey('wp-content/plugins/my-plugin/plugin.php')
            ->and(collect(FileBrowserFake::$ran)->contains(fn (string $c) => str_starts_with($c, 'runuser -u siteowner -- tar -xzf')))->toBeTrue();
    });

    it('refuses a zip-slip entry in a .tar.gz the same way as in a .zip', function () {
        fakeFileBrowserServer();
        FileBrowserFake::$fs['wp-content/plugins/thing.tgz'] = ['type' => 'f', 'size' => 100];
        FileBrowserFake::$archives['wp-content/plugins/thing.tgz'] = [
            ['type' => '-', 'size' => 4, 'name' => '../../etc/evil.txt'],
        ];

        $this->actingAs($this->admin)
            ->postJson(filesUrl('/extract'), extractPayload('wp-content/plugins/thing.tgz'))
            ->assertStatus(422);
    });

    it('refuses a symlink entry in a .tar.gz', function () {
        fakeFileBrowserServer();
        FileBrowserFake::$fs['wp-content/plugins/thing.tar.gz'] = ['type' => 'f', 'size' => 100];
        FileBrowserFake::$archives['wp-content/plugins/thing.tar.gz'] = [
            ['type' => 'l', 'size' => 0, 'name' => 'leak'],
        ];

        $this->actingAs($this->admin)
            ->postJson(filesUrl('/extract'), extractPayload('wp-content/plugins/thing.tar.gz'))
            ->assertStatus(422);
    });
});

describe('creating a directory', function () {
    beforeEach(function () {
        FileBrowserFake::reset();
        FileBrowserFake::$fs['wp-content'] = ['type' => 'd'];
    });

    it('creates a new directory as the site\'s own user', function () {
        fakeFileBrowserServer();

        $this->actingAs($this->admin)
            ->postJson(filesUrl('/directories'), ['path' => 'wp-content/uploads'])
            ->assertOk();

        expect(FileBrowserFake::$fs['wp-content/uploads']['type'])->toBe('d')
            ->and(collect(FileBrowserFake::$ran)->contains(fn (string $c) => str_starts_with($c, 'runuser -u siteowner -- mkdir')))->toBeTrue()
            ->and(ActivityLog::where('action', 'directory_created')->exists())->toBeTrue();
    });

    it('is a no-op if the directory already exists', function () {
        fakeFileBrowserServer();
        FileBrowserFake::$fs['wp-content/uploads'] = ['type' => 'd'];

        $this->actingAs($this->admin)
            ->postJson(filesUrl('/directories'), ['path' => 'wp-content/uploads'])
            ->assertOk();
    });

    it('refuses when a file already sits at that path', function () {
        fakeFileBrowserServer();
        FileBrowserFake::$fs['wp-content/uploads'] = ['type' => 'f', 'content' => 'x'];

        $this->actingAs($this->admin)
            ->postJson(filesUrl('/directories'), ['path' => 'wp-content/uploads'])
            ->assertStatus(422);
    });

    it('404s when the containing directory does not exist', function () {
        fakeFileBrowserServer();

        $this->actingAs($this->admin)
            ->postJson(filesUrl('/directories'), ['path' => 'no-such-dir/uploads'])
            ->assertNotFound();
    });

    it('refuses a viewer who cannot manage', function () {
        fakeFileBrowserServer();
        $user = User::factory()->create();
        grantPermission($user, 'app_file', view: true, manage: false);

        $this->actingAs($user)
            ->postJson(filesUrl('/directories'), ['path' => 'wp-content/uploads'])
            ->assertForbidden();
    });
});

describe('renaming', function () {
    beforeEach(function () {
        FileBrowserFake::reset();
        FileBrowserFake::$fs['old.txt'] = ['type' => 'f', 'content' => 'hello'];
        FileBrowserFake::$fs['wp-content'] = ['type' => 'd'];
    });

    it('renames a file as the site\'s own user', function () {
        fakeFileBrowserServer();

        $this->actingAs($this->admin)
            ->putJson(filesUrl('/rename'), ['path' => 'old.txt', 'target' => 'new.txt'])
            ->assertOk();

        expect(FileBrowserFake::$fs)->not->toHaveKey('old.txt')
            ->and(FileBrowserFake::$fs['new.txt']['content'])->toBe('hello')
            ->and(collect(FileBrowserFake::$ran)->contains(fn (string $c) => str_starts_with($c, 'runuser -u siteowner -- mv')))->toBeTrue()
            ->and(ActivityLog::where('action', 'file_renamed')->exists())->toBeTrue();
    });

    it('moves a file into a different directory', function () {
        fakeFileBrowserServer();

        $this->actingAs($this->admin)
            ->putJson(filesUrl('/rename'), ['path' => 'old.txt', 'target' => 'wp-content/old.txt'])
            ->assertOk();

        expect(FileBrowserFake::$fs)->toHaveKey('wp-content/old.txt');
    });

    it('refuses when the destination already exists — unlike upload, this never overwrites', function () {
        fakeFileBrowserServer();
        FileBrowserFake::$fs['new.txt'] = ['type' => 'f', 'content' => 'do not touch me'];

        $this->actingAs($this->admin)
            ->putJson(filesUrl('/rename'), ['path' => 'old.txt', 'target' => 'new.txt'])
            ->assertStatus(422);

        expect(FileBrowserFake::$fs['new.txt']['content'])->toBe('do not touch me');
    });

    it('404s when the source does not exist', function () {
        fakeFileBrowserServer();

        $this->actingAs($this->admin)
            ->putJson(filesUrl('/rename'), ['path' => 'nope.txt', 'target' => 'new.txt'])
            ->assertNotFound();
    });

    it('refuses a viewer who cannot manage', function () {
        fakeFileBrowserServer();
        $user = User::factory()->create();
        grantPermission($user, 'app_file', view: true, manage: false);

        $this->actingAs($user)
            ->putJson(filesUrl('/rename'), ['path' => 'old.txt', 'target' => 'new.txt'])
            ->assertForbidden();
    });
});

describe('copying', function () {
    beforeEach(function () {
        FileBrowserFake::reset();
        FileBrowserFake::$fs['old.txt'] = ['type' => 'f', 'content' => 'hello'];
        FileBrowserFake::$fs['wp-content'] = ['type' => 'd'];
    });

    it('copies a file as the site\'s own user, keeping the original', function () {
        fakeFileBrowserServer();

        $this->actingAs($this->admin)
            ->postJson(filesUrl('/copy'), ['path' => 'old.txt', 'target' => 'new.txt'])
            ->assertOk();

        expect(FileBrowserFake::$fs)->toHaveKey('old.txt')
            ->and(FileBrowserFake::$fs['old.txt']['content'])->toBe('hello')
            ->and(FileBrowserFake::$fs['new.txt']['content'])->toBe('hello')
            ->and(collect(FileBrowserFake::$ran)->contains(fn (string $c) => str_starts_with($c, 'runuser -u siteowner -- cp -r')))->toBeTrue()
            ->and(ActivityLog::where('action', 'file_copied')->exists())->toBeTrue();
    });

    it('copies a directory into a different directory', function () {
        fakeFileBrowserServer();
        FileBrowserFake::$fs['a-folder'] = ['type' => 'd'];
        FileBrowserFake::$fs['a-folder/inside.txt'] = ['type' => 'f', 'content' => 'x'];

        $this->actingAs($this->admin)
            ->postJson(filesUrl('/copy'), ['path' => 'a-folder', 'target' => 'wp-content/a-folder'])
            ->assertOk();

        expect(FileBrowserFake::$fs)->toHaveKey('a-folder/inside.txt')
            ->and(FileBrowserFake::$fs)->toHaveKey('wp-content/a-folder/inside.txt');
    });

    it('refuses when the destination already exists — same non-overwrite default as rename', function () {
        fakeFileBrowserServer();
        FileBrowserFake::$fs['new.txt'] = ['type' => 'f', 'content' => 'do not touch me'];

        $this->actingAs($this->admin)
            ->postJson(filesUrl('/copy'), ['path' => 'old.txt', 'target' => 'new.txt'])
            ->assertStatus(422);

        expect(FileBrowserFake::$fs['new.txt']['content'])->toBe('do not touch me');
    });

    it('404s when the source does not exist', function () {
        fakeFileBrowserServer();

        $this->actingAs($this->admin)
            ->postJson(filesUrl('/copy'), ['path' => 'nope.txt', 'target' => 'new.txt'])
            ->assertNotFound();
    });

    it('refuses a viewer who cannot manage', function () {
        fakeFileBrowserServer();
        $user = User::factory()->create();
        grantPermission($user, 'app_file', view: true, manage: false);

        $this->actingAs($user)
            ->postJson(filesUrl('/copy'), ['path' => 'old.txt', 'target' => 'new.txt'])
            ->assertForbidden();
    });
});

describe('compressing', function () {
    beforeEach(function () {
        FileBrowserFake::reset();
        FileBrowserFake::$fs['my-plugin'] = ['type' => 'd'];
        FileBrowserFake::$fs['my-plugin/plugin.php'] = ['type' => 'f', 'content' => 'x'];
        FileBrowserFake::$fs['wp-content'] = ['type' => 'd'];
    });

    it('packages a folder into a new zip, running with the source\'s parent as cwd', function () {
        fakeFileBrowserServer();

        $this->actingAs($this->admin)
            ->postJson(filesUrl('/compress'), ['path' => 'my-plugin', 'target' => 'wp-content/backup.zip'])
            ->assertOk();

        expect(FileBrowserFake::$fs)->toHaveKey('wp-content/backup.zip')
            // The archive contains relative paths (my-plugin/...), not the
            // full server path — that's what running with cwd set achieves.
            ->and(FileBrowserFake::$cwds)->toContain('/home/siteowner/shop/public_html')
            ->and(collect(FileBrowserFake::$ran)->contains(fn (string $c) => str_starts_with($c, 'runuser -u siteowner -- zip -r')))->toBeTrue()
            ->and(ActivityLog::where('action', 'files_compressed')->exists())->toBeTrue();
    });

    it('refuses a target that does not end in .zip', function () {
        fakeFileBrowserServer();

        $this->actingAs($this->admin)
            ->postJson(filesUrl('/compress'), ['path' => 'my-plugin', 'target' => 'wp-content/backup.tar'])
            ->assertStatus(422);
    });

    it('refuses when the destination already exists', function () {
        fakeFileBrowserServer();
        FileBrowserFake::$fs['wp-content/backup.zip'] = ['type' => 'f', 'content' => 'already here'];

        $this->actingAs($this->admin)
            ->postJson(filesUrl('/compress'), ['path' => 'my-plugin', 'target' => 'wp-content/backup.zip'])
            ->assertStatus(422);
    });

    it('404s when the source does not exist', function () {
        fakeFileBrowserServer();

        $this->actingAs($this->admin)
            ->postJson(filesUrl('/compress'), ['path' => 'nope', 'target' => 'wp-content/backup.zip'])
            ->assertNotFound();
    });

    it('refuses a viewer who cannot manage', function () {
        fakeFileBrowserServer();
        $user = User::factory()->create();
        grantPermission($user, 'app_file', view: true, manage: false);

        $this->actingAs($user)
            ->postJson(filesUrl('/compress'), ['path' => 'my-plugin', 'target' => 'wp-content/backup.zip'])
            ->assertForbidden();
    });
});

describe('deleting', function () {
    beforeEach(function () {
        FileBrowserFake::reset();
        FileBrowserFake::$fs['old.txt'] = ['type' => 'f', 'content' => 'hello'];
        FileBrowserFake::$fs['wp-content'] = ['type' => 'd'];
        FileBrowserFake::$fs['wp-content/uploads'] = ['type' => 'd'];
        FileBrowserFake::$fs['wp-content/uploads/photo.jpg'] = ['type' => 'f'];
    });

    it('deletes a file as the site\'s own user, with confirm: true', function () {
        fakeFileBrowserServer();

        $this->actingAs($this->admin)
            ->deleteJson(filesUrl(), ['path' => 'old.txt', 'confirm' => true])
            ->assertOk();

        // Gone from where it was, but moved rather than destroyed: delete is
        // recoverable unless the caller asks otherwise.
        expect(FileBrowserFake::$fs)->not->toHaveKey('old.txt')
            ->and(collect(FileBrowserFake::$ran)->contains(fn (string $c) => str_starts_with($c, 'runuser -u siteowner -- mv')))->toBeTrue()
            ->and(collect(FileBrowserFake::$ran)->contains(fn (string $c) => str_starts_with($c, 'runuser -u siteowner -- rm')))->toBeFalse()
            ->and(ActivityLog::where('action', 'file_deleted')->exists())->toBeTrue();
    });

    it('deletes permanently when asked, and only then', function () {
        fakeFileBrowserServer();

        $this->actingAs($this->admin)
            ->deleteJson(filesUrl(), ['path' => 'old.txt', 'confirm' => true, 'permanent' => true])
            ->assertOk();

        // The escape hatch has to stay real: someone deleting to free disk
        // space and seeing nothing freed would be right to call that a bug.
        expect(FileBrowserFake::$fs)->not->toHaveKey('old.txt')
            ->and(collect(FileBrowserFake::$ran)->contains(fn (string $c) => str_starts_with($c, 'runuser -u siteowner -- rm')))->toBeTrue();
    });

    it('deletes a directory recursively', function () {
        fakeFileBrowserServer();

        $this->actingAs($this->admin)
            ->deleteJson(filesUrl(), ['path' => 'wp-content/uploads', 'confirm' => true])
            ->assertOk();

        expect(FileBrowserFake::$fs)->not->toHaveKey('wp-content/uploads')
            ->and(FileBrowserFake::$fs)->not->toHaveKey('wp-content/uploads/photo.jpg');
    });

    it('refuses without confirm: true', function () {
        fakeFileBrowserServer();

        $this->actingAs($this->admin)
            ->deleteJson(filesUrl(), ['path' => 'old.txt'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('confirm');

        expect(FileBrowserFake::$fs)->toHaveKey('old.txt');
    });

    it('refuses to delete the site root', function () {
        fakeFileBrowserServer();

        $this->actingAs($this->admin)
            ->deleteJson(filesUrl(), ['path' => '', 'confirm' => true])
            ->assertStatus(422);
    });

    it('404s when the path does not exist', function () {
        fakeFileBrowserServer();

        $this->actingAs($this->admin)
            ->deleteJson(filesUrl(), ['path' => 'nope.txt', 'confirm' => true])
            ->assertNotFound();
    });

    it('refuses a viewer who cannot manage', function () {
        fakeFileBrowserServer();
        $user = User::factory()->create();
        grantPermission($user, 'app_file', view: true, manage: false);

        $this->actingAs($user)
            ->deleteJson(filesUrl(), ['path' => 'old.txt', 'confirm' => true])
            ->assertForbidden();
    });
});

describe('restoring a backup', function () {
    beforeEach(function () {
        FileBrowserFake::reset();
        FileBrowserFake::$fs['index.php'] = ['type' => 'f', 'size' => 20, 'content' => 'v2'];
        FileBrowserFake::$fs['.panel/backups'] = ['type' => 'd'];
        FileBrowserFake::$fs['.panel/backups/index.php.bak-20260805-090000'] = ['type' => 'f', 'content' => 'v1'];
    });

    it('puts a previous save back, and itself backs up what was there first', function () {
        fakeFileBrowserServer();

        $this->actingAs($this->admin)
            ->postJson(filesUrl('/content/restore'), ['path' => 'index.php', 'backup' => 'index.php.bak-20260805-090000'])
            ->assertOk();

        // The fake's `cat` normalises output to end with one newline, same
        // as everywhere else this file asserts on read-then-write content.
        expect(FileBrowserFake::$fs['index.php']['content'])->toBe("v1\n")
            ->and(ActivityLog::where('action', 'file_restored')->exists())->toBeTrue();

        // Restoring is itself a write, so it backs up what "v2" was before
        // being replaced — restoring the wrong one is itself undoable. The new
        // copy lands above the web root; the one it restored FROM was seeded in
        // the pre-relocation location, so this also proves those are still
        // readable rather than stranded.
        $backupKeys = array_filter(
            array_keys(FileBrowserFake::$fs),
            fn (string $k) => str_starts_with($k, '/home/siteowner/shop/.panel/file-backups/index.php.bak-'),
        );
        expect($backupKeys)->toHaveCount(1);
    });

    it('refuses a name that is not shaped like a backup of this file — refused, not sanitised', function () {
        fakeFileBrowserServer();

        $this->actingAs($this->admin)
            ->postJson(filesUrl('/content/restore'), ['path' => 'index.php', 'backup' => '../../../etc/passwd'])
            ->assertStatus(422);
    });

    it('refuses a backup name belonging to a different file', function () {
        fakeFileBrowserServer();
        FileBrowserFake::$fs['other.php'] = ['type' => 'f', 'content' => 'x'];

        $this->actingAs($this->admin)
            ->postJson(filesUrl('/content/restore'), ['path' => 'other.php', 'backup' => 'index.php.bak-20260805-090000'])
            ->assertStatus(422);
    });

    it('404s when the named backup does not exist on disk', function () {
        fakeFileBrowserServer();

        $this->actingAs($this->admin)
            ->postJson(filesUrl('/content/restore'), ['path' => 'index.php', 'backup' => 'index.php.bak-20260101-000000'])
            ->assertNotFound();
    });

    it('refuses a viewer who cannot manage', function () {
        fakeFileBrowserServer();
        $user = User::factory()->create();
        grantPermission($user, 'app_file', view: true, manage: false);

        $this->actingAs($user)
            ->postJson(filesUrl('/content/restore'), ['path' => 'index.php', 'backup' => 'index.php.bak-20260805-090000'])
            ->assertForbidden();
    });
});

describe('setting permissions on one file', function () {
    beforeEach(function () {
        FileBrowserFake::reset();
        FileBrowserFake::$fs['index.php'] = ['type' => 'f', 'content' => 'x'];
        FileBrowserFake::$fs['wp-content'] = ['type' => 'd'];
    });

    it('sets the mode on a file as the site\'s own user', function () {
        fakeFileBrowserServer();

        $this->actingAs($this->admin)
            ->putJson(filesUrl('/permissions'), ['path' => 'index.php', 'mode' => '600'])
            ->assertOk();

        expect(FileBrowserFake::$fs['index.php']['mode'])->toBe('600')
            ->and(collect(FileBrowserFake::$ran)->contains(fn (string $c) => str_starts_with($c, 'runuser -u siteowner -- chmod 600')))->toBeTrue()
            ->and(ActivityLog::where('action', 'file_chmod')->exists())->toBeTrue();
    });

    it('sets the mode on a directory', function () {
        fakeFileBrowserServer();

        $this->actingAs($this->admin)
            ->putJson(filesUrl('/permissions'), ['path' => 'wp-content', 'mode' => '755'])
            ->assertOk();

        expect(FileBrowserFake::$fs['wp-content']['mode'])->toBe('755');
    });

    it('refuses a mode with a fourth digit', function () {
        fakeFileBrowserServer();

        $this->actingAs($this->admin)
            ->putJson(filesUrl('/permissions'), ['path' => 'index.php', 'mode' => '4755'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('mode');
    });

    it('refuses a mode with an out-of-range digit', function () {
        fakeFileBrowserServer();

        $this->actingAs($this->admin)
            ->putJson(filesUrl('/permissions'), ['path' => 'index.php', 'mode' => '899'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('mode');
    });

    it('404s when the path does not exist', function () {
        fakeFileBrowserServer();

        $this->actingAs($this->admin)
            ->putJson(filesUrl('/permissions'), ['path' => 'nope.txt', 'mode' => '644'])
            ->assertNotFound();
    });

    it('refuses a viewer who cannot manage', function () {
        fakeFileBrowserServer();
        $user = User::factory()->create();
        grantPermission($user, 'app_file', view: true, manage: false);

        $this->actingAs($user)
            ->putJson(filesUrl('/permissions'), ['path' => 'index.php', 'mode' => '644'])
            ->assertForbidden();
    });
});

/*
 * Bulk operations.
 *
 * The point is not only the UX. Every action used to take one path, so
 * clearing a cache directory of 200 files meant 200 requests, each spawning
 * its own `runuser` process to stat and another to act — and the delete
 * endpoint is throttled at 10/min, so it could not be done at all. These
 * assert the two things that make a batch trustworthy: it really is one
 * command, and a batch that partly fails says exactly which parts.
 */
describe('bulk operations', function () {
    beforeEach(function () {
        FileBrowserFake::reset();
        FileBrowserFake::$fs['cache'] = ['type' => 'd'];
        FileBrowserFake::$fs['cache/a.txt'] = ['type' => 'f', 'size' => 1];
        FileBrowserFake::$fs['cache/b.txt'] = ['type' => 'f', 'size' => 1];
        FileBrowserFake::$fs['cache/sub'] = ['type' => 'd'];
        FileBrowserFake::$fs['cache/sub/deep.txt'] = ['type' => 'f', 'size' => 1];
        FileBrowserFake::$fs['keep'] = ['type' => 'd'];
    });

    it('deletes a whole selection to the trash, keeping the batch together', function () {
        fakeFileBrowserServer();

        $this->actingAs($this->admin)
            ->deleteJson(filesUrl(), [
                'paths' => ['cache/a.txt', 'cache/b.txt', 'cache/sub'],
                'confirm' => true,
                'count' => 3,
            ])
            ->assertOk()
            ->assertJsonPath('deleted', true);

        expect(FileBrowserFake::$fs)->not->toHaveKey('cache/a.txt')
            ->and(FileBrowserFake::$fs)->not->toHaveKey('cache/b.txt')
            ->and(collect(FileBrowserFake::$ran)->contains(fn (string $c) => str_contains($c, ' rm ')))->toBeFalse();

        // One timestamp for the selection, so twelve things deleted together
        // come back together rather than scattering across twelve folders.
        $batches = collect(array_keys(FileBrowserFake::$fs))
            ->filter(fn (string $k) => str_contains($k, '/.panel/trash/'))
            ->map(fn (string $k) => explode('/', explode('/.panel/trash/', $k)[1])[0])
            ->unique();

        expect($batches)->toHaveCount(1);
    });

    it('deletes a whole selection in one pair of commands when permanent', function () {
        fakeFileBrowserServer();

        $this->actingAs($this->admin)
            ->deleteJson(filesUrl(), [
                'paths' => ['cache/a.txt', 'cache/b.txt', 'cache/sub'],
                'confirm' => true,
                'count' => 3,
                'permanent' => true,
            ])
            ->assertOk()
            ->assertJsonPath('deleted', true)
            ->assertJsonPath('failed', []);

        expect(FileBrowserFake::$fs)->not->toHaveKey('cache/a.txt')
            ->and(FileBrowserFake::$fs)->not->toHaveKey('cache/b.txt')
            ->and(FileBrowserFake::$fs)->not->toHaveKey('cache/sub/deep.txt')
            ->and(FileBrowserFake::$fs)->toHaveKey('cache');

        // Directories and files go through separate commands: `delete()` has
        // always used -r only for a directory, and a blanket -rf would widen
        // what one mistaken path can destroy.
        $removals = collect(FileBrowserFake::$ran)->filter(fn (string $c) => str_contains($c, ' rm '));

        expect($removals)->toHaveCount(2)
            ->and($removals->first(fn (string $c) => str_contains($c, '-rf')))->toContain('cache/sub')
            ->and($removals->first(fn (string $c) => str_contains($c, '-f ') && ! str_contains($c, '-rf')))
            ->toContain('cache/a.txt')
            ->toContain('cache/b.txt');
    });

    it('reports each path that failed and still does the rest', function () {
        fakeFileBrowserServer();

        $response = $this->actingAs($this->admin)
            ->deleteJson(filesUrl(), [
                'paths' => ['cache/a.txt', 'cache/gone.txt', 'cache/b.txt'],
                'confirm' => true,
                'count' => 3,
            ])
            ->assertOk();

        expect($response->json('deleted'))->toBeFalse()
            ->and($response->json('succeeded'))->toEqualCanonicalizing(['cache/a.txt', 'cache/b.txt'])
            ->and($response->json('failed'))->toBe([['path' => 'cache/gone.txt', 'reason' => 'not_found']]);

        // The two that existed are gone: one bad path does not abandon the batch.
        expect(FileBrowserFake::$fs)->not->toHaveKey('cache/a.txt')
            ->and(FileBrowserFake::$fs)->not->toHaveKey('cache/b.txt');
    });

    it('refuses when the confirmed count disagrees with the selection', function () {
        fakeFileBrowserServer();

        // The realistic bulk-delete accident: a stale checkbox means the
        // selection is not what the user is looking at. `confirm` cannot
        // catch it — it is true either way.
        $this->actingAs($this->admin)
            ->deleteJson(filesUrl(), [
                'paths' => ['cache/a.txt', 'cache/b.txt'],
                'confirm' => true,
                'count' => 1,
            ])
            ->assertStatus(422);

        expect(FileBrowserFake::$fs)->toHaveKey('cache/a.txt');
    });

    it('refuses more paths than one argument vector can carry', function () {
        fakeFileBrowserServer();

        $paths = array_map(fn (int $i): string => "cache/f{$i}.txt", range(1, FileBrowser::MAX_BULK_PATHS + 1));

        $this->actingAs($this->admin)
            ->deleteJson(filesUrl(), ['paths' => $paths, 'confirm' => true, 'count' => count($paths)])
            ->assertStatus(422);
    });

    it('validates every path in a selection, not just the first', function () {
        fakeFileBrowserServer();

        $this->actingAs($this->admin)
            ->deleteJson(filesUrl(), [
                'paths' => ['cache/a.txt', '../../../etc/passwd'],
                'confirm' => true,
                'count' => 2,
            ])
            ->assertStatus(422);

        expect(FileBrowserFake::$fs)->toHaveKey('cache/a.txt');
    });

    it('moves a selection into a directory', function () {
        fakeFileBrowserServer();

        $this->actingAs($this->admin)
            ->putJson(filesUrl('/rename'), [
                'paths' => ['cache/a.txt', 'cache/b.txt'],
                'target_directory' => 'keep',
            ])
            ->assertOk()
            ->assertJsonPath('renamed', true);

        expect(FileBrowserFake::$fs)->toHaveKey('keep/a.txt')
            ->and(FileBrowserFake::$fs)->toHaveKey('keep/b.txt')
            ->and(FileBrowserFake::$fs)->not->toHaveKey('cache/a.txt');
    });

    it('copies a selection without moving it', function () {
        fakeFileBrowserServer();

        $this->actingAs($this->admin)
            ->postJson(filesUrl('/copy'), [
                'paths' => ['cache/a.txt', 'cache/sub'],
                'target_directory' => 'keep',
            ])
            ->assertOk();

        expect(FileBrowserFake::$fs)->toHaveKey('keep/a.txt')
            ->and(FileBrowserFake::$fs)->toHaveKey('keep/sub/deep.txt')
            // Still where it started, unlike a move.
            ->and(FileBrowserFake::$fs)->toHaveKey('cache/a.txt');
    });

    it('refuses to overwrite something already at the destination', function () {
        fakeFileBrowserServer();
        FileBrowserFake::$fs['keep/a.txt'] = ['type' => 'f', 'size' => 99];

        $response = $this->actingAs($this->admin)
            ->putJson(filesUrl('/rename'), [
                'paths' => ['cache/a.txt', 'cache/b.txt'],
                'target_directory' => 'keep',
            ])
            ->assertOk();

        // The single-path form has always refused rather than overwritten; a
        // selection must not become the way around that.
        expect($response->json('failed'))->toBe([['path' => 'cache/a.txt', 'reason' => 'exists']])
            ->and($response->json('succeeded'))->toBe(['cache/b.txt'])
            ->and(FileBrowserFake::$fs['keep/a.txt']['size'])->toBe(99)
            ->and(FileBrowserFake::$fs)->toHaveKey('cache/a.txt');
    });

    it('chmods a selection in one command and verifies the mode landed', function () {
        fakeFileBrowserServer();

        $this->actingAs($this->admin)
            ->putJson(filesUrl('/permissions'), [
                'paths' => ['cache/a.txt', 'cache/b.txt'],
                'mode' => '600',
            ])
            ->assertOk()
            ->assertJsonPath('chmoded', true);

        expect(FileBrowserFake::$fs['cache/a.txt']['mode'])->toBe('600')
            ->and(FileBrowserFake::$fs['cache/b.txt']['mode'])->toBe('600')
            ->and(collect(FileBrowserFake::$ran)->filter(fn (string $c) => str_contains($c, ' chmod ')))
            ->toHaveCount(1);
    });

    it('zips a selection from one folder', function () {
        fakeFileBrowserServer();

        $this->actingAs($this->admin)
            ->postJson(filesUrl('/compress'), [
                'paths' => ['cache/a.txt', 'cache/b.txt'],
                'target' => 'cache/bundle.zip',
            ])
            ->assertOk();

        expect(FileBrowserFake::$fs)->toHaveKey('cache/bundle.zip');

        // Bare names, run from their shared parent — otherwise the archive
        // contains the whole absolute path.
        $zip = collect(FileBrowserFake::$ran)->first(fn (string $c) => str_contains($c, ' zip '));

        expect($zip)->toEndWith('a.txt b.txt');
    });

    it('refuses to zip sources spread across folders', function () {
        fakeFileBrowserServer();

        // There is no single directory to run `zip` from, so there is no way
        // to produce bare names for both.
        $this->actingAs($this->admin)
            ->postJson(filesUrl('/compress'), [
                'paths' => ['cache/a.txt', 'cache/sub/deep.txt'],
                'target' => 'cache/bundle.zip',
            ])
            ->assertStatus(422);
    });
});

/*
 * Rate limiting on the resumable-upload endpoints.
 *
 * `bootstrap/app.php` prepends `throttle:api` to every API route, and a
 * per-route throttle does not replace it — it stacks, so the lower of the two
 * wins. These endpoints declare 240/min and 1200/min precisely because one
 * upload is legitimately thousands of requests; with the global 120/min still
 * applied those numbers were decoration, and a large upload competed for that
 * one budget with every other call the UI made.
 *
 * Asserted by making the requests rather than by reading the route: the
 * declared limit and the effective limit were different things, and only one
 * of them is observable.
 */
describe('upload throttling', function () {
    it('lets an upload run past the global api limit', function () {
        fakeFileBrowserServer();

        // Pinned rather than read from the environment. This derived its loop
        // count from the configured global limit but asserts against the
        // route's own 240/min — so the moment a developer raised
        // RATE_LIMIT_API above 210 in their .env, the loop overshot 240 and the
        // test failed on the very limit it exists to prove is working. A test
        // whose premise depends on an untracked local file is not testing what
        // it claims.
        config(['server.rate_limits.api' => 180]);

        // Past the global budget entirely: anything beyond it proves the
        // route's own limit is the one in force.
        $beyondGlobal = (int) config('server.rate_limits.api') + 30;

        for ($i = 1; $i <= $beyondGlobal; $i++) {
            $response = $this->actingAs($this->admin)
                ->getJson(filesUrl('/uploads/'.str_repeat('a', 32)));

            expect($response->getStatusCode())->not->toBe(429);
        }
    });

    it('still bounds the endpoints that are not part of an upload', function () {
        fakeFileBrowserServer();

        // The global limit is deliberately left on everything else: removing
        // it wholesale would be a rate limiter that bounds nothing.
        $statuses = [];
        $beyondGlobal = (int) config('server.rate_limits.api') + 10;

        for ($i = 1; $i <= $beyondGlobal; $i++) {
            $statuses[] = $this->actingAs($this->admin)->getJson(filesUrl())->getStatusCode();
        }

        expect($statuses)->toContain(429);
    });
});

describe('trash', function () {
    beforeEach(function () {
        FileBrowserFake::reset();
        FileBrowserFake::$fs['old.txt'] = ['type' => 'f', 'size' => 5, 'content' => 'gone?'];
    });

    it('lists what was deleted, puts it back, and refuses to overwrite', function () {
        fakeFileBrowserServer();

        $this->actingAs($this->admin)
            ->deleteJson(filesUrl(), ['path' => 'old.txt', 'confirm' => true])
            ->assertOk();

        // Listed with where it came from — "config.php" alone does not tell
        // anyone which one it was.
        $trash = $this->actingAs($this->admin)->getJson(filesUrl('/trash'))->assertOk()->json('trash');

        expect($trash)->toHaveCount(1)
            ->and($trash[0]['path'])->toBe('old.txt')
            ->and($trash[0]['batch'])->toMatch('/^\d{8}-\d{6}$/');

        $this->actingAs($this->admin)
            ->postJson(filesUrl('/trash/restore'), ['batch' => $trash[0]['batch'], 'path' => 'old.txt'])
            ->assertOk();

        expect(FileBrowserFake::$fs)->toHaveKey('old.txt');
    });

    it('refuses to restore over something that is there again', function () {
        fakeFileBrowserServer();

        $this->actingAs($this->admin)
            ->deleteJson(filesUrl(), ['path' => 'old.txt', 'confirm' => true])
            ->assertOk();

        $batch = $this->actingAs($this->admin)->getJson(filesUrl('/trash'))->json('trash.0.batch');

        // Something new at the same path. Restoring must not win silently:
        // the file that is there now is the one somebody kept.
        FileBrowserFake::$fs['old.txt'] = ['type' => 'f', 'size' => 3, 'content' => 'new'];

        $this->actingAs($this->admin)
            ->postJson(filesUrl('/trash/restore'), ['batch' => $batch, 'path' => 'old.txt'])
            ->assertStatus(422);

        expect(FileBrowserFake::$fs['old.txt']['content'])->toBe('new');
    });

    it('refuses a batch name that is not a timestamp', function () {
        fakeFileBrowserServer();

        // It is half a filesystem path. The only thing keeping `..` out of it
        // is that it can never be anything but digits and a dash.
        $this->actingAs($this->admin)
            ->postJson(filesUrl('/trash/restore'), ['batch' => '../../etc', 'path' => 'old.txt'])
            ->assertStatus(422);
    });
});
