<?php

use App\Http\Resources\ActivityLogResource;
use App\Models\ActivityLog;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;

    // A fake /etc/php with two installed versions, so tests never depend on
    // what this machine happens to have.
    $this->phpDir = sys_get_temp_dir().'/sv-oss-php-'.getmypid();
    File::deleteDirectory($this->phpDir);
    foreach (['8.3', '8.4'] as $version) {
        File::makeDirectory("{$this->phpDir}/{$version}/fpm", 0755, true);
        File::put("{$this->phpDir}/{$version}/fpm/php.ini", "memory_limit = 128M\n");
    }

    config([
        'server.php_dir' => $this->phpDir,
        'server.php_fpm_binary' => '/usr/sbin/php-fpm',
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->phpDir);
});

function phpHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->token];
}

it('lists the installed php versions, newest first', function () {
    $response = $this->withHeaders(phpHeaders())->getJson('/api/php');

    $response->assertOk();
    expect(collect($response->json('php.versions'))->pluck('version')->all())->toBe(['8.4', '8.3']);
    // The FPM unit is named here so the PHP screen can link across to
    // Services, where starting and stopping a daemon still lives.
    $response->assertJsonPath('php.versions.0.service', 'php8.4-fpm');
    $response->assertJsonPath('php.versions.0.ini_path', config('server.php_dir').'/8.4/fpm/php.ini');
});

it('returns the raw ini for the editor to load', function () {
    Process::fake(['*' => Process::result(output: "memory_limit = 128M\n")]);

    $response = $this->withHeaders(phpHeaders())->getJson('/api/php/versions/8.4/ini');

    $response->assertOk();
    $response->assertJsonPath('php_ini.version', '8.4');
    expect($response->json('php_ini.contents'))->toContain('memory_limit');
    Process::assertRan(fn ($p) => $p->command === ['cat', "{$this->phpDir}/8.4/fpm/php.ini"]);
});

it('refuses a version that is not installed', function () {
    $this->withHeaders(phpHeaders())->getJson('/api/php/versions/5.6/ini')->assertNotFound();

    // A path is never built from an unchecked client string.
    $this->withHeaders(phpHeaders())->getJson('/api/php/versions/..%2F..%2Fetc/ini')->assertNotFound();
});

it('backs up, writes, tests and reloads on a valid ini', function () {
    Process::fake(['*' => Process::result(exitCode: 0)]);

    $this->withHeaders(phpHeaders())->putJson('/api/php/versions/8.4/ini', [
        'contents' => "memory_limit = 512M\n",
        'acknowledged' => true,
    ])->assertOk();

    $ini = "{$this->phpDir}/8.4/fpm/php.ini";

    Process::assertRan(fn ($p) => $p->command === ['cp', '-f', $ini, $ini.'.panel-bak']);
    Process::assertRan(fn ($p) => $p->command === ['tee', $ini] && str_contains($p->input, '512M'));
    Process::assertRan(fn ($p) => $p->command === ['/usr/sbin/php-fpm8.4', '-t']);
    Process::assertRan(fn ($p) => $p->command === ['systemctl', 'reload', 'php8.4-fpm']);
});

it('restores the previous ini and does not reload when php rejects it', function () {
    Process::fake(fn ($process) => in_array('-t', $process->command, true)
        ? Process::result(errorOutput: 'Failed to parse', exitCode: 1)
        : Process::result(exitCode: 0));

    $response = $this->withHeaders(phpHeaders())->putJson('/api/php/versions/8.4/ini', [
        'contents' => "this is not valid\n",
        'acknowledged' => true,
    ]);

    $response->assertStatus(422);

    $ini = "{$this->phpDir}/8.4/fpm/php.ini";

    // A broken ini can stop FPM starting at all — the working file goes back
    // and nothing is reloaded.
    Process::assertRan(fn ($p) => $p->command === ['cp', '-f', $ini.'.panel-bak', $ini]);
    Process::assertNotRan(fn ($p) => $p->command[0] === 'systemctl');
});

/*
 * PHP does not refuse an ini it cannot parse: it warns, exits 0, and ignores
 * every directive after the error. Measured on both stacks on 2026-09-23 — a
 * `max_input_vars = 3000` below an unclosed quote read back as 1000.
 */
it('restores the previous ini when php exits 0 but could not parse it', function () {
    Process::fake(fn ($process) => in_array('-t', $process->command, true)
        ? Process::result(
            errorOutput: "PHP:  syntax error, unexpected end of file, expecting TC_DOLLAR_CURLY or TC_QUOTED_STRING or '\"' in /etc/php/8.4/fpm/php.ini on line 4\n"
                ."[23-Sep-2026 11:50:41] NOTICE: configuration file /etc/php/8.4/fpm/php-fpm.conf test is successful\n",
        )
        : Process::result(exitCode: 0));

    $this->withHeaders(phpHeaders())->putJson('/api/php/versions/8.4/ini', [
        'contents' => "memory_limit = 512M\nfoo = \"bar\nmax_input_vars = 3000\n",
        'acknowledged' => true,
    ])->assertStatus(422)->assertJsonPath('message', 'PHP rejected that configuration, so the previous one was restored. Nothing was reloaded.')
        // FS-C17: PHP's own line, so the editor can say where — not the
        // "test is successful" line printed beside it.
        ->assertJsonPath('php_errors', ["PHP:  syntax error, unexpected end of file, expecting TC_DOLLAR_CURLY or TC_QUOTED_STRING or '\"' in /etc/php/8.4/fpm/php.ini on line 4"]);

    $ini = "{$this->phpDir}/8.4/fpm/php.ini";

    Process::assertRan(fn ($p) => $p->command === ['cp', '-f', $ini.'.panel-bak', $ini]);
    Process::assertNotRan(fn ($p) => $p->command[0] === 'systemctl');
});

it('does not refuse an ini over a php warning that is not a parse error', function () {
    // A running PHP with a complaint. Failing on these would refuse every save
    // on a server that already loads a module twice.
    Process::fake(fn ($process) => in_array('-t', $process->command, true)
        ? Process::result(errorOutput: "PHP Warning:  Module \"redis\" is already loaded in Unknown on line 0\n")
        : Process::result(exitCode: 0));

    $this->withHeaders(phpHeaders())->putJson('/api/php/versions/8.4/ini', [
        'contents' => "memory_limit = 512M\n",
        'acknowledged' => true,
    ])->assertOk();

    Process::assertRan(fn ($p) => $p->command === ['systemctl', 'reload', 'php8.4-fpm']);
});

it('writes the ini exactly as sent, final newline and indentation included', function () {
    // The global TrimStrings middleware dropped the last newline on every
    // save — a file that should have come back byte-identical did not.
    Process::fake(['*' => Process::result(exitCode: 0)]);

    $contents = "  ; indented comment\nmemory_limit = 512M\n";

    $this->withHeaders(phpHeaders())->putJson('/api/php/versions/8.4/ini', [
        'contents' => $contents,
        'acknowledged' => true,
    ])->assertOk();

    Process::assertRan(fn ($p) => $p->command === ['tee', "{$this->phpDir}/8.4/fpm/php.ini"] && $p->input === $contents);
});

it('has a sentence, in every locale, for every PHP error it can raise', function () {
    // Four of these were deleted in a refactor and never re-added, so a
    // rejected ini answered with the literal "errors/php.invalid_ini".
    $source = (string) file_get_contents(app_path('Exceptions/Server/Php/PhpConfigException.php'));
    preg_match_all("/'(errors\/php\.[a-z_]+)'/", $source, $matches);

    expect($matches[1])->not->toBeEmpty();

    foreach (config('app.available_locales') as $locale) {
        foreach (array_unique($matches[1]) as $key) {
            expect(Lang::hasForLocale($key, $locale))->toBeTrue("{$key} missing for {$locale}");
        }
    }
});

it('requires the impact to be acknowledged', function () {
    Process::fake();

    $this->withHeaders(phpHeaders())->putJson('/api/php/versions/8.4/ini', [
        'contents' => "memory_limit = 512M\n",
    ])->assertStatus(422)->assertJsonValidationErrors('acknowledged');

    // Nothing was touched.
    Process::assertNothingRan();
});

it('only reloads the version that was edited', function () {
    Process::fake(['*' => Process::result(exitCode: 0)]);

    $this->withHeaders(phpHeaders())->putJson('/api/php/versions/8.3/ini', [
        'contents' => "memory_limit = 256M\n",
        'acknowledged' => true,
    ])->assertOk();

    Process::assertRan(fn ($p) => $p->command === ['systemctl', 'reload', 'php8.3-fpm']);
    Process::assertNotRan(fn ($p) => $p->command === ['systemctl', 'reload', 'php8.4-fpm']);
});

it('denies editing with view-only access', function () {
    Process::fake();
    $user = User::factory()->create();
    grantPermission($user, 'php', view: true, manage: false);
    $token = $user->createToken('t')->plainTextToken;

    // Reading is allowed...
    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/php')->assertOk();

    // ...editing is not.
    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson('/api/php/versions/8.4/ini', ['contents' => 'x', 'acknowledged' => true])
        ->assertForbidden();
});

/*
 * Bug #28: php.ini is read by the PHP-FPM master, which runs as root, so an
 * `extension=/tmp/x.so` is code run as root at the next reload. Extensions
 * load from PHP's own directory only — which is also where v7 copied the
 * ionCube loader, so a migrated v7 php.ini still saves.
 */
function fakePhpWithExtensionDir(): void
{
    Process::fake(fn ($p) => str_contains(implode(' ', (array) $p->command), 'PHP_EXTENSION_DIR')
        ? Process::result(output: '/usr/lib/php/20240924')
        : Process::result(exitCode: 0));
}

it('refuses an extension loaded from outside PHP\'s own directory, before writing anything', function (string $line) {
    fakePhpWithExtensionDir();

    $this->withHeaders(phpHeaders())->putJson('/api/php/versions/8.4/ini', [
        'contents' => "memory_limit = 512M\n{$line}\n",
        'acknowledged' => true,
    ])->assertUnprocessable()
        ->assertJsonPath('message', __('errors/php.ini_extension_outside_dir', ['line' => $line, 'directory' => '/usr/lib/php/20240924']));

    Process::assertNotRan(fn ($p) => ($p->command[0] ?? '') === 'tee');
    Process::assertNotRan(fn ($p) => ($p->command[0] ?? '') === 'systemctl');
})->with([
    'absolute elsewhere' => ['extension=/tmp/x.so'],
    'zend_extension elsewhere' => ['zend_extension = "/home/site/evil.so"'],
    'climbing out' => ['extension=/usr/lib/php/20240924/../../../../tmp/x.so'],
    'relative climb' => ['extension=../../../tmp/x.so'],
    'environment variable' => ['extension=${HOME}/x.so'],
    'variable inside the directory' => ['extension=/usr/lib/php/20240924/${EVIL}'],
    'moving extension_dir' => ['extension_dir = /tmp'],
]);

it('still saves the extension lines a real server has', function (string $line) {
    fakePhpWithExtensionDir();

    $this->withHeaders(phpHeaders())->putJson('/api/php/versions/8.4/ini', [
        'contents' => "{$line}\n",
        'acknowledged' => true,
    ])->assertOk();
})->with([
    'bare name' => ['extension=redis'],
    'bare .so' => ['extension = "imagick.so" ; comment'],
    'commented out' => [';extension=/tmp/x.so'],
    'v7 ionCube line' => ['zend_extension = /usr/lib/php/20240924/ioncube_loader_lin_8.4.so'],
    'extension_dir unchanged' => ['extension_dir = "/usr/lib/php/20240924/"'],
]);

/*
 * RC-8 (PHP page redesign, 12 Oct): the server's php.ini could only be edited
 * as the whole raw file. The common settings now have fields of their own;
 * only their lines change, through the same backup / check / rollback.
 */
describe('the common php.ini settings as fields', function () {
    function fakeIni(string $contents, ?ArrayObject $written = null): void
    {
        Process::fake(function ($process) use ($contents, $written) {
            if (($process->command[0] ?? '') === 'cat') {
                return Process::result(output: $contents);
            }
            if (($process->command[0] ?? '') === 'tee' && $written !== null) {
                $written[] = (string) $process->input;
            }

            return Process::result();
        });
    }

    $ini = "[PHP]\n; Maximum amount of memory a script may consume\n;memory_limit = 64M\nmemory_limit = 128M\nupload_max_filesize = 2M\npost_max_size = 8M\n\n[Date]\n;date.timezone =\n";

    it('reads what the file sets, and null where PHP\'s default applies', function () use ($ini) {
        fakeIni($ini);

        $this->withHeaders(phpHeaders())->getJson('/api/php/versions/8.4/settings')
            ->assertOk()
            ->assertJsonPath('settings.memory_limit', '128M')
            ->assertJsonPath('settings.upload_max_filesize', '2M')
            ->assertJsonPath('settings.post_max_size', '8M')
            ->assertJsonPath('settings.max_execution_time', null);
    });

    it('changes only those lines, adds a missing one under [PHP], and leaves comments alone', function () use ($ini) {
        $written = new ArrayObject;
        fakeIni($ini, $written);

        $this->withHeaders(phpHeaders())->putJson('/api/php/versions/8.4/settings', [
            'memory_limit' => '512m', 'upload_max_filesize' => '64M', 'post_max_size' => '64M', 'max_execution_time' => 120,
        ])->assertOk();

        $file = $written[0];
        expect($file)->toContain("memory_limit = 512M\n")
            ->toContain(";memory_limit = 64M\n")
            ->toContain("upload_max_filesize = 64M\n")
            ->toContain("post_max_size = 64M\n")
            ->toContain("[PHP]\nmax_execution_time = 120\n")
            ->toContain("[Date]\n;date.timezone =\n")
            ->and(substr_count($file, "\nmemory_limit ="))->toBe(1);

        // Through the raw editor's own path: backup, PHP's check, reload.
        $path = "{$this->phpDir}/8.4/fpm/php.ini";
        Process::assertRan(fn ($p) => $p->command === ['cp', '-f', $path, $path.'.panel-bak']);
        Process::assertRan(fn ($p) => $p->command === ['/usr/sbin/php-fpm8.4', '-t']);
    });

    it('refuses a POST size below the upload size, counting what the file already says', function () use ($ini) {
        fakeIni($ini);

        $this->withHeaders(phpHeaders())->putJson('/api/php/versions/8.4/settings', ['upload_max_filesize' => '16M'])
            ->assertUnprocessable()->assertJsonValidationErrors('post_max_size');

        Process::assertNotRan(fn ($p) => ($p->command[0] ?? '') === 'tee');
    });

    it('refuses values PHP would not understand', function (array $body, string $field) use ($ini) {
        fakeIni($ini);

        $this->withHeaders(phpHeaders())->putJson('/api/php/versions/8.4/settings', $body)
            ->assertUnprocessable()->assertJsonValidationErrors($field);
    })->with([
        [['memory_limit' => "128M\nextension=evil.so"], 'memory_limit'],
        [['memory_limit' => '128 MB'], 'memory_limit'],
        [['max_execution_time' => 99999], 'max_execution_time'],
        [['max_input_vars' => 10], 'max_input_vars'],
    ]);

    it('needs php manage to save, view to read', function () use ($ini) {
        fakeIni($ini);
        $viewer = User::factory()->create();
        grantPermission($viewer, 'php');

        $this->actingAs($viewer)->getJson('/api/php/versions/8.4/settings')->assertOk();
        $this->actingAs($viewer)->putJson('/api/php/versions/8.4/settings', ['memory_limit' => '256M'])->assertForbidden();
    });

    it('answers 404 for a version that is not installed', function () {
        $this->withHeaders(phpHeaders())->getJson('/api/php/versions/5.6/settings')->assertNotFound();
    });
});

describe('the user may save php.ini without a copy (2026-10-29)', function () {
    it('writes no copy, and puts the old text back from memory when php rejects the new one', function () {
        $old = "memory_limit = 128M\n";
        Process::fake(fn ($process) => match (true) {
            ($process->command[0] ?? '') === 'cat' => Process::result(output: $old),
            in_array('-t', $process->command, true) => Process::result(errorOutput: 'Failed to parse', exitCode: 1),
            default => Process::result(),
        });

        $this->withHeaders(phpHeaders())->putJson('/api/php/versions/8.4/ini', [
            'contents' => "this is not valid\n", 'acknowledged' => true, 'safety_copy' => false,
        ])->assertStatus(422);

        $ini = "{$this->phpDir}/8.4/fpm/php.ini";
        Process::assertNotRan(fn ($p) => ($p->command[0] ?? '') === 'cp');
        Process::assertRan(fn ($p) => $p->command === ['tee', $ini] && $p->input === $old);
        Process::assertNotRan(fn ($p) => $p->command[0] === 'systemctl');
    });

    it('saves without a copy and says so in the log', function () {
        Process::fake(['*' => Process::result(exitCode: 0)]);

        $this->withHeaders(phpHeaders())->putJson('/api/php/versions/8.4/ini', [
            'contents' => "memory_limit = 512M\n", 'acknowledged' => true, 'safety_copy' => false,
        ])->assertOk();

        Process::assertNotRan(fn ($p) => ($p->command[0] ?? '') === 'cp');
        Process::assertRan(fn ($p) => $p->command === ['systemctl', 'reload', 'php8.4-fpm']);
        $log = ActivityLog::where('action', 'ini_updated')->latest('id')->first();
        expect(ActivityLogResource::make($log)->resolve()['description'])->toEndWith('(without a safety copy)');
    });

    it('still keeps the copy when nothing is said', function () {
        Process::fake(['*' => Process::result(exitCode: 0)]);

        $this->withHeaders(phpHeaders())->putJson('/api/php/versions/8.4/ini', [
            'contents' => "memory_limit = 512M\n", 'acknowledged' => true,
        ])->assertOk();

        $ini = "{$this->phpDir}/8.4/fpm/php.ini";
        Process::assertRan(fn ($p) => $p->command === ['cp', '-f', $ini, $ini.'.panel-bak']);
    });

    it('saves the settings form without a copy, and never writes safety_copy into the file', function () {
        $written = new ArrayObject;
        Process::fake(function ($process) use ($written) {
            if (($process->command[0] ?? '') === 'cat') {
                return Process::result(output: "[PHP]\nmemory_limit = 128M\n");
            }
            if (($process->command[0] ?? '') === 'tee') {
                $written[] = (string) $process->input;
            }

            return Process::result();
        });

        $this->withHeaders(phpHeaders())->putJson('/api/php/versions/8.4/settings', ['memory_limit' => '256M', 'safety_copy' => false])
            ->assertOk();

        expect($written[0])->toContain('memory_limit = 256M')->not->toContain('safety_copy');
        Process::assertNotRan(fn ($p) => ($p->command[0] ?? '') === 'cp');
    });

    it('does not rewrite or reload when only the switch is sent', function () {
        Process::fake(fn ($process) => ($process->command[0] ?? '') === 'cat'
            ? Process::result(output: "[PHP]\nmemory_limit = 128M\n")
            : Process::result());

        $this->withHeaders(phpHeaders())->putJson('/api/php/versions/8.4/settings', ['safety_copy' => false])->assertOk();

        Process::assertNotRan(fn ($p) => in_array($p->command[0] ?? '', ['tee', 'systemctl'], true));
    });

    it('refuses a value that is not true or false', function () {
        $this->withHeaders(phpHeaders())->putJson('/api/php/versions/8.4/ini', [
            'contents' => "memory_limit = 512M\n", 'acknowledged' => true, 'safety_copy' => 'maybe',
        ])->assertJsonValidationErrors('safety_copy');
    });
});
