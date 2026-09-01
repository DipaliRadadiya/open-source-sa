<?php

use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\Cronjob;
use App\Models\SystemUser;
use App\Models\User;
use App\Models\Worker;
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

it('orders cronjob log sources without case bias', function () {
    config(['server.cronjob_log_dir' => $this->logDir]);

    foreach ([
        ['name' => 'Case Zebra', 'slug' => 'case-zebra'],
        ['name' => 'case apple', 'slug' => 'case-apple'],
        ['name' => 'CASE Banana', 'slug' => 'case-banana'],
    ] as $job) {
        Cronjob::create($job + [
            'username' => 'root',
            'command' => 'echo test',
            'expression' => '* * * * *',
        ]);
        File::put($this->logDir.'/'.$job['slug'].'.log', "output\n");
    }

    $logs = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/logs')
        ->assertOk()
        ->json('logs');

    expect(collect($logs)->where('group', 'cronjob')->pluck('label')->all())
        ->toBe(['Cron — case apple', 'Cron — CASE Banana', 'Cron — Case Zebra']);
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
        // Broken, not refused: sudo permits `tail` and the read still fails.
        // That is a fault on this server, so it gets a reference to quote.
        Process::fake(function ($process) {
            $command = (array) $process->command;

            if (($command[0] ?? '') === 'sudo' && in_array('-l', $command, true)) {
                return Process::result(output: '(root) NOPASSWD: /usr/bin/tail');
            }

            return in_array('tail', $command, true)
                ? Process::result(exitCode: 1, errorOutput: 'tail: error reading: Input/output error')
                : Process::result(output: "4096 1755264000\n");
        });

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/logs/letsencrypt')
            ->assertStatus(500)
            ->assertJsonStructure(['message', 'reference']);
    });

    it('answers 403, not 500, when sudo refuses the reader', function () {
        // The difference that matters to whoever is looking at the screen: a
        // grant that predates the binary this needs is a known state with a
        // known remedy (re-run install.sh), not a crash. Reported like the
        // unprivileged branch already reports a file it cannot open, so one
        // unreadable source does not read as a broken page.
        Process::fake(function ($process) {
            $command = (array) $process->command;

            // sudo permits nothing — an out-of-date sudoers file.
            if (($command[0] ?? '') === 'sudo' && in_array('-l', $command, true)) {
                return Process::result(errorOutput: 'sorry, user may not run sudo', exitCode: 1);
            }

            return in_array('tail', $command, true)
                ? Process::result(exitCode: 1, errorOutput: 'sudo: a password is required')
                : Process::result(output: "4096 1755264000\n");
        });

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/logs/letsencrypt')
            ->assertStatus(403)
            ->assertJsonPath('code', 'log_not_permitted');
    });

    it('keeps a source it could not check, rather than hiding it', function () {
        // Absent and "could not ask" lead to opposite conclusions: one says
        // this server has no such log, the other says the panel could not
        // look. Hiding the second reads as the first — an empty Logs screen on
        // a server whose sudo grant is out of date, and a user concluding
        // their cron jobs never wrote anything.
        Process::fake(fn () => Process::result(
            errorOutput: 'sudo: a password is required',
            exitCode: 1,
        ));

        $keys = collect($this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/logs')->assertOk()->json('logs'))->pluck('key');

        expect($keys)->toContain('letsencrypt');
    });

    it('still hides a source the server genuinely does not have', function () {
        // The other half. `test -f` exiting 1 with nothing on stderr is the
        // command answering "no such file" — certbot was never installed here,
        // and offering a log that does not exist is its own kind of wrong.
        Process::fake(fn ($process) => in_array('test', (array) $process->command, true)
            ? Process::result(exitCode: 1)
            : Process::result(exitCode: 0));

        $keys = collect($this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/logs')->assertOk()->json('logs'))->pluck('key');

        expect($keys)->not->toContain('letsencrypt');
    });

    it('says up front that a privileged source is not readable when sudo refuses', function () {
        // So the screen can grey the entry instead of offering something that
        // fails on click. `readable` used to be hardcoded true for these.
        Process::fake(function ($process) {
            $command = (array) $process->command;

            if (($command[0] ?? '') === 'sudo' && in_array('-l', $command, true)) {
                return Process::result(errorOutput: 'sorry, user may not run sudo', exitCode: 1);
            }

            return Process::result(output: "4096 1755264000\n");
        });

        $source = collect($this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/logs')->assertOk()->json('logs'))
            ->firstWhere('key', 'letsencrypt');

        expect($source)->not->toBeNull()
            ->and($source['readable'])->toBeFalse();
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

describe('a cron job log', function () {
    it('is read through sudo, because the panel account cannot open it', function () {
        // The file is 0640 owned by the account the job runs as, and its group
        // is that user's own — so `adm`, which is what lets the panel read
        // nginx and syslog, does not help. Read as the panel account this is
        // the one source on the screen that always comes back empty.
        config(['server.cronjob_log_dir' => '/var/log/cronjobs']);

        Cronjob::create([
            'name' => 'Nightly report', 'slug' => 'nightly-report',
            'username' => 'deploy', 'command' => 'php artisan report',
            'expression' => '0 3 * * *',
        ]);

        $ran = new ArrayObject;

        Process::fake(function ($process) use ($ran) {
            $command = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;
            $ran[] = $command;

            return ($command[0] ?? '') === 'tail'
                ? Process::result(output: "job output line\n")
                : Process::result(exitCode: 0);
        });

        $body = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/logs/cronjob_nightly-report')
            ->assertOk()
            ->json('log');

        expect($body['lines'])->toContain('job output line');

        // Through `tail`, against the job's own log — not PHP's file(), which
        // is what every other source uses and what fails here.
        expect(collect($ran)->contains(fn (array $c): bool => ($c[0] ?? '') === 'tail'
            && in_array('/var/log/cronjobs/nightly-report.log', $c, true)))->toBeTrue();
    });

    it('says a privileged source cannot be followed or downloaded', function () {
        // Both need a handle the panel does not have: byte-offset following
        // needs a stable file it can stat, and streaming through sudo would
        // pin a worker for the whole transfer. Reported so the UI offers
        // refresh rather than a follow that silently never advances.
        config(['server.cronjob_log_dir' => '/var/log/cronjobs']);

        Cronjob::create([
            'name' => 'Nightly report', 'slug' => 'nightly-report',
            'username' => 'deploy', 'command' => 'php artisan report',
            'expression' => '0 3 * * *',
        ]);

        Process::fake(fn () => Process::result(exitCode: 0));

        $source = collect($this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/logs')->assertOk()->json('logs'))
            ->firstWhere('key', 'cronjob_nightly-report');

        expect($source)->not->toBeNull()
            ->and($source['kind'])->toBe('privileged')
            ->and($source['follow'])->toBeFalse()
            ->and($source['downloadable'])->toBeFalse();
    });
});

describe('worker logs', function () {
    beforeEach(function () {
        config(['server.logs' => [
            ['key' => 'journal', 'label' => 'System — Journal', 'group' => 'system', 'kind' => 'journal', 'path' => ''],
        ]]);

        $systemUser = SystemUser::create(['username' => 'siteowner', 'home_path' => '/home/siteowner']);

        $application = Application::forceCreate([
            'system_user_id' => $systemUser->id,
            'name' => 'Shop', 'slug' => 'shop', 'domain' => 'shop.test',
            'site_type' => 'php', 'serving_profile' => 'php',
            'status' => 'active', 'web_root' => '/', 'php_version' => '8.4',
        ]);

        $this->worker = Worker::forceCreate([
            'application_id' => $application->id,
            'name' => 'Queue', 'kind' => 'queue',
            'command' => 'php artisan queue:work',
            'directory' => '/home/siteowner/shop',
            'processes' => 1, 'enabled' => true,
        ]);

        Process::fake(fn () => Process::result(output: "worker started\n"));
    });

    it('offers one source per worker, named after the worker', function () {
        // The API already sent `log_identifier` and the worker row already
        // linked to /logs?source=sv-worker-{id}. No source by that key existed,
        // so the one button offering a worker's output led nowhere.
        $logs = collect($this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/logs')->assertOk()->json('logs'))->keyBy('key');

        expect($logs)->toHaveKey('sv-worker-'.$this->worker->id)
            ->and($logs['sv-worker-'.$this->worker->id]['label'])->toBe('Shop — Queue')
            ->and($logs['sv-worker-'.$this->worker->id]['group'])->toBe('worker')
            ->and($logs['sv-worker-'.$this->worker->id]['kind'])->toBe('journal');
    });

    it('reads only that worker, not the whole box', function () {
        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/logs/sv-worker-'.$this->worker->id)
            ->assertOk()
            ->assertJsonPath('log.lines', ['worker started']);

        // Without `-t` this source would show every unit on the server
        // interleaved, which is not an answer to "what is this worker doing".
        Process::assertRan(fn ($p) => in_array('journalctl', $p->command, true)
            && in_array('-t', $p->command, true)
            && in_array('sv-worker-'.$this->worker->id, $p->command, true));
    });

    it('leaves the system journal showing the whole system', function () {
        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/logs/journal')->assertOk();

        // That source exists precisely to show everything; narrowing it would
        // remove the only view of units the panel does not manage.
        Process::assertRan(fn ($p) => in_array('journalctl', $p->command, true)
            && in_array('-t', $p->command, true) === false);
    });
});
