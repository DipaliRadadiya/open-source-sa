<?php

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Server\LogManager;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('t')->plainTextToken;

    // A writable temp dir standing in for /var/log, wired into the registry.
    $this->logDir = sys_get_temp_dir().'/sv-oss-logs-'.uniqid();
    File::ensureDirectoryExists($this->logDir);

    config(['server.logs' => [
        ['key' => 'nginx_error', 'label' => 'Nginx — Error', 'group' => 'web', 'path' => $this->logDir.'/nginx-error.log'],
        ['key' => 'syslog', 'label' => 'System — Syslog', 'group' => 'system', 'path' => $this->logDir.'/syslog'],
    ]]);
    // No php-fpm logs bleeding in from the real /etc/php.
    config(['server.php_dir' => $this->logDir.'/empty-php']);
    File::ensureDirectoryExists($this->logDir.'/empty-php');
});

afterEach(function () {
    File::deleteDirectory($this->logDir);
});

it('lists only log sources whose file exists, with metadata', function () {
    File::put($this->logDir.'/nginx-error.log', "one\ntwo\n");
    // syslog file intentionally not created → excluded.

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")->getJson('/api/logs')->assertOk();

    $keys = collect($response->json('logs'))->pluck('key');
    expect($keys)->toContain('nginx_error');
    expect($keys)->not->toContain('syslog');

    $nginx = collect($response->json('logs'))->firstWhere('key', 'nginx_error');
    expect($nginx['group'])->toBe('web');
    expect($nginx['readable'])->toBeTrue();
    expect($nginx['size'])->toBeGreaterThan(0);
});

it('tails the last N lines of a source', function () {
    File::put($this->logDir.'/nginx-error.log', collect(range(1, 50))->map(fn ($i) => "line {$i}")->implode("\n")."\n");

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")->getJson('/api/logs/nginx_error?lines=5')->assertOk();

    expect($response->json('log.lines'))->toBe(['line 46', 'line 47', 'line 48', 'line 49', 'line 50']);
    expect($response->json('log.truncated'))->toBeTrue();
    expect($response->json('log.cursor'))->toBeGreaterThan(0);
});

it('filters lines with a literal grep', function () {
    File::put($this->logDir.'/nginx-error.log', "info start\nERROR boom\ninfo tick\nerror again\n");

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")->getJson('/api/logs/nginx_error?grep=error')->assertOk();

    // case-insensitive literal match
    expect($response->json('log.lines'))->toBe(['ERROR boom', 'error again']);
});

it('returns only bytes appended since the cursor', function () {
    File::put($this->logDir.'/nginx-error.log', "a\nb\n");
    $first = $this->withHeader('Authorization', "Bearer {$this->token}")->getJson('/api/logs/nginx_error')->assertOk();
    $cursor = $first->json('log.cursor');

    File::append($this->logDir.'/nginx-error.log', "c\nd\n");

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")->getJson("/api/logs/nginx_error?after={$cursor}")->assertOk();
    expect($response->json('log.lines'))->toBe(['c', 'd']);
});

it('caps an incremental read to a bounded byte window', function () {
    $line = "entry\n";
    File::put(
        $this->logDir.'/nginx-error.log',
        str_repeat($line, intdiv(LogManager::MAX_INCREMENTAL_BYTES, strlen($line)) + 10),
    );

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/logs/nginx_error?after=0')
        ->assertOk();

    expect($response->json('log.lines'))->toHaveCount(LogManager::MAX_LINES);
    expect($response->json('log.truncated'))->toBeTrue();
});

it('returns 404 for an unknown source key', function () {
    $this->withHeader('Authorization', "Bearer {$this->token}")->getJson('/api/logs/not-a-log')->assertNotFound();
});

it('returns 404 when the source is registered but the file is missing', function () {
    // syslog is registered but never created.
    $this->withHeader('Authorization', "Bearer {$this->token}")->getJson('/api/logs/syslog')->assertNotFound();
});

it('downloads a log file and records the activity', function () {
    File::put($this->logDir.'/nginx-error.log', "downloadable\n");

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->get('/api/logs/nginx_error/download')
        ->assertOk()
        ->assertDownload('nginx_error.log');

    expect(ActivityLog::where('type', 'log')->where('action', 'downloaded')->exists())->toBeTrue();
});

it('denies a user without the logs permission', function () {
    File::put($this->logDir.'/nginx-error.log', "secret\n");
    $stranger = User::factory()->create();
    $token = $stranger->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/logs')->assertForbidden();
    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/logs/nginx_error')->assertForbidden();
});

it('allows a viewer with the logs permission', function () {
    File::put($this->logDir.'/nginx-error.log', "visible\n");
    $viewer = User::factory()->create();
    grantPermission($viewer, 'logs', view: true, manage: false);
    $token = $viewer->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/logs/nginx_error')
        ->assertOk()
        ->assertJsonPath('log.lines', ['visible']);
});

it('bounds what it reads from a log with almost no line breaks', function () {
    // One line far larger than the read window: the newline count alone can
    // never satisfy the loop, so an unbounded tail loads the whole file.
    $blob = str_repeat('x', LogManager::MAX_INCREMENTAL_BYTES * 3);
    File::put($this->logDir.'/nginx-error.log', "first line\n".$blob."\nlast line\n");

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/logs/nginx_error')
        ->assertOk();

    $returned = strlen(implode("\n", $response->json('log.lines')));

    expect($returned)->toBeLessThanOrEqual(LogManager::MAX_INCREMENTAL_BYTES)
        ->and($response->json('log.truncated'))->toBeTrue()
        // The newest content is what a log viewer is for.
        ->and($response->json('log.lines'))->toContain('last line');
});

it('does not invent a line by starting mid-way through one', function () {
    $blob = str_repeat('y', LogManager::MAX_INCREMENTAL_BYTES + 1000);
    File::put($this->logDir.'/nginx-error.log', $blob."\ncomplete line\n");

    $lines = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/logs/nginx_error')
        ->assertOk()
        ->json('log.lines');

    // The half of $blob inside the window is not a log entry that ever existed.
    expect($lines)->toBe(['complete line']);
});

it('ships a registry whose keys are unique and paths absolute', function () {
    // `find()` returns the first key that matches, so a duplicate would resolve
    // to the wrong file — silently, and only for the source listed second.
    // Read from the file, not from config(): beforeEach replaces the registry
    // with a temp one, so config() would assert against the fixture.
    $sources = (require base_path('config/server.php'))['logs'];

    $keys = array_column($sources, 'key');

    expect($keys)->toBe(array_unique($keys))
        ->and($keys)->toContain('auth', 'kernel', 'mail', 'syslog');

    foreach ($sources as $source) {
        expect($source)->toHaveKeys(['key', 'label', 'group', 'path']);

        // The journal is not a file and carries no path; everything else must
        // name one absolutely, since nothing resolves these relative to a cwd.
        if (($source['kind'] ?? 'file') !== 'journal') {
            expect($source['path'])->toStartWith('/');
        }
    }
});

it('hides a log source whose file is not on this server', function () {
    // Only nginx-error exists; syslog is registered but absent.
    File::put($this->logDir.'/nginx-error.log', "up\n");

    $keys = collect(
        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/logs')->assertOk()->json('logs')
    )->pluck('key');

    expect($keys)->toContain('nginx_error')
        ->and($keys)->not->toContain('syslog');
});

describe('sources the panel cannot open itself', function () {
    beforeEach(function () {
        config(['server.logs' => [
            ['key' => 'nginx_error', 'label' => 'Nginx — Error', 'group' => 'web', 'path' => $this->logDir.'/nginx-error.log'],
            ['key' => 'letsencrypt', 'label' => "Let's Encrypt", 'group' => 'security', 'kind' => 'privileged', 'path' => '/var/log/letsencrypt/letsencrypt.log'],
            ['key' => 'journal', 'label' => 'System — Journal', 'group' => 'system', 'kind' => 'journal', 'path' => ''],
        ]]);
    });

    /** Answer the privileged probes the way a real server would. */
    function fakePrivilegedLogs(string $tail = "line one\nline two\n"): void
    {
        Process::fake(function ($process) use ($tail) {
            $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;

            if (($args[0] ?? '') === 'stat') {
                return Process::result(output: "4096 1755264000\n");
            }

            if (($args[0] ?? '') === 'tail' || ($args[0] ?? '') === 'journalctl') {
                return Process::result(output: $tail);
            }

            return Process::result(exitCode: 0);
        });
    }

    it('offers them without the panel account being able to read the file', function () {
        fakePrivilegedLogs();

        $logs = collect($this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/logs')->assertOk()->json('logs'))->keyBy('key');

        // /var/log/letsencrypt is 0700 root — `readable` would be false if the
        // panel had to open it, and the source would never have appeared.
        expect($logs['letsencrypt']['readable'])->toBeTrue()
            ->and($logs['letsencrypt']['kind'])->toBe('privileged')
            ->and($logs['letsencrypt']['size'])->toBe(4096)
            // Neither can be followed by byte offset or streamed as a file.
            ->and($logs['letsencrypt']['follow'])->toBeFalse()
            ->and($logs['letsencrypt']['downloadable'])->toBeFalse();

        // The journal has no single size or mtime — null, not a plausible zero
        // that reads as an empty log last written in 1970.
        expect($logs['journal']['size'])->toBeNull()
            ->and($logs['journal']['modified'])->toBeNull();
    });

    it('reads them through the system', function () {
        fakePrivilegedLogs("certbot: renewing\ncertbot: done\n");

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/logs/letsencrypt')
            ->assertOk()
            ->assertJsonPath('log.kind', 'privileged')
            ->assertJsonPath('log.lines', ['certbot: renewing', 'certbot: done'])
            // No bytes to come back with.
            ->assertJsonPath('log.cursor', null);

        Process::assertRan(fn ($p) => in_array('journalctl', $p->command, true) === false
            && in_array('/var/log/letsencrypt/letsencrypt.log', $p->command, true));
    });

    it('reads the journal without waiting on a pager', function () {
        fakePrivilegedLogs("kernel: started\n");

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/logs/journal')
            ->assertOk()
            ->assertJsonPath('log.lines', ['kernel: started']);

        // Without --no-pager journalctl blocks on a pager that is not there.
        Process::assertRan(fn ($p) => in_array('journalctl', $p->command, true)
            && in_array('--no-pager', $p->command, true));
    });

    it('filters them too', function () {
        fakePrivilegedLogs("certbot: renewing\ncertbot: FAILED\ncertbot: done\n");

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/logs/letsencrypt?grep=failed')
            ->assertOk()
            ->assertJsonPath('log.lines', ['certbot: FAILED']);
    });

    it('refuses to download one rather than failing oddly', function () {
        fakePrivilegedLogs();

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/logs/journal/download')
            ->assertStatus(422);
    });

    it('reports a failed privileged read as a failure, not an empty log', function () {
        Process::fake(fn ($process) => in_array('tail', $process->command, true)
            ? Process::result(exitCode: 1, errorOutput: 'sudo: a password is required')
            : Process::result(output: "4096 1755264000\n"));

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/logs/letsencrypt')
            ->assertStatus(500)
            ->assertJsonStructure(['message', 'reference']);
    });

    it('hides one the server does not have', function () {
        // certbot never installed: `test -f` says no, so the source is not
        // offered rather than offered and broken.
        Process::fake(fn ($process) => in_array('test', $process->command, true)
            ? Process::result(exitCode: 1)
            : Process::result(exitCode: 0));

        $keys = collect($this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/logs')->assertOk()->json('logs'))->pluck('key');

        expect($keys)->not->toContain('letsencrypt');
    });
});
