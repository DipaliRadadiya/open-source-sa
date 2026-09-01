<?php

use App\Models\ServerCapability;
use App\Services\Server\Capabilities\ServerCapabilities;
use App\Services\Server\WebServers\WebServerManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    // Detection shells out; nothing here is testing detection itself.
    Process::fake();
});

it('records the stack the installer built', function () {
    $this->artisan('server:record-stack', ['stack' => 'lemp'])->assertSuccessful();

    $record = ServerCapability::query()->firstOrFail();

    expect($record->stack)->toBe('lemp');
    expect($record->web_server)->toBe('nginx');
    // `installer` rather than `detected`: this server's history is known, which
    // is the whole point of the command.
    expect($record->source)->toBe('installer');
});

it('maps mern to nginx, because mern is not a web server', function () {
    $this->artisan('server:record-stack', ['stack' => 'mern'])->assertSuccessful();

    expect(ServerCapability::query()->value('web_server'))->toBe('nginx');
});

it('refuses an unknown stack without touching the record', function () {
    $this->artisan('server:record-stack', ['stack' => 'lemp'])->assertSuccessful();

    $this->artisan('server:record-stack', ['stack' => 'kubernetes'])->assertFailed();

    // The earlier value survives — a typo in the installer must not blank out
    // what the panel already knew.
    expect(ServerCapability::query()->value('stack'))->toBe('lemp');
});

it('is safe to run twice', function () {
    // The installer is re-runnable, so everything it calls has to be.
    $this->artisan('server:record-stack', ['stack' => 'lemp'])->assertSuccessful();
    $this->artisan('server:record-stack', ['stack' => 'lamp'])->assertSuccessful();

    expect(ServerCapability::query()->count())->toBe(1);
    expect(ServerCapability::query()->value('web_server'))->toBe('apache');
});

/*
 * Detection, which decides the web server on a box the panel did not build.
 *
 * Every vhost the panel ever writes hangs off this one value, so getting it
 * wrong is not a cosmetic error: it picked ApacheDriver on a real OpenLiteSpeed
 * server and site creation failed with
 * `tee: /etc/apache2/sites-available/x.conf: No such file or directory`.
 */
describe('detecting the web server', function () {
    /**
     * @param  array<int, string>  $running  units `systemctl is-active` says yes to
     * @param  array<int, string>  $dirs  config directories that exist
     */
    function fakeDetectedWebServer(array $running, array $dirs): void
    {
        Process::fake(fn ($process) => Process::result(
            exitCode: ($process->command[0] ?? '') === 'systemctl'
                && in_array($process->command[3] ?? '', $running, true) ? 0 : 1,
        ));

        File::shouldReceive('isDirectory')
            ->andReturnUsing(fn (string $path): bool => in_array($path, $dirs, true));
    }

    it('believes what is running over what was left behind', function () {
        // The real failure: apache purged, its /etc directory still there, and
        // apache is listed before openlitespeed. A directory is not a web
        // server, and the box was plainly running OpenLiteSpeed.
        fakeDetectedWebServer(running: ['lshttpd'], dirs: ['/etc/apache2', '/usr/local/lsws']);

        expect(app(ServerCapabilities::class)->webServer())
            ->toBe('openlitespeed');
    });

    it('still answers when nothing is running', function () {
        // Installed but stopped is still the web server this box uses, and the
        // setup screen has to be able to say what it found.
        fakeDetectedWebServer(running: [], dirs: ['/usr/local/lsws']);

        expect(app(ServerCapabilities::class)->webServer())
            ->toBe('openlitespeed');
    });
});

/*
 * Repairing a record the box contradicts.
 *
 * The panel does not use detection — it uses this record. So a wrong one is
 * not self-correcting, and on the real server that hit this the only way out
 * was an operator running a command they had to be told about first.
 */
describe('reconciling a wrong record', function () {
    it('corrects a recorded web server that is not even installed', function () {
        ServerCapability::query()->create([
            'stack' => null,
            'web_server' => 'apache',
            'capabilities' => ['php' => true, 'node' => true],
            'source' => 'detected',
            'verified_at' => now(),
        ]);

        // Apache purged — /etc/apache2 gone — and OpenLiteSpeed running.
        fakeDetectedWebServer(running: ['lshttpd'], dirs: ['/usr/local/lsws']);

        $capabilities = app(ServerCapabilities::class);

        expect($capabilities->reconcileWebServer())->toBe('openlitespeed')
            ->and(ServerCapability::query()->value('web_server'))->toBe('openlitespeed')
            // Marked, so nobody later mistakes this for what the installer said.
            ->and(ServerCapability::query()->value('source'))->toBe('reconciled');
    });

    it('leaves a stopped web server alone', function () {
        ServerCapability::query()->create([
            'stack' => 'lamp',
            'web_server' => 'apache',
            'capabilities' => ['php' => true, 'node' => false],
            'source' => 'installer',
            'verified_at' => now(),
        ]);

        // Apache installed but stopped, OpenLiteSpeed running alongside it.
        // Stopping a web server is not the panel's cue to decide the box runs
        // something else — that is a judgement call, and this must not make it.
        fakeDetectedWebServer(running: ['lshttpd'], dirs: ['/etc/apache2', '/usr/local/lsws']);

        expect(app(ServerCapabilities::class)->reconcileWebServer())
            ->toBeNull()
            ->and(ServerCapability::query()->value('web_server'))->toBe('apache');
    });

    it('never overrules the installer', function () {
        // `source: installer` is a statement of what was built. A filesystem
        // check is a weaker signal, and silently overriding it would write
        // vhosts for a web server nobody chose — a worse version of the bug
        // this repairs. panel:doctor reports the disagreement instead.
        ServerCapability::query()->create([
            'stack' => 'ols',
            'web_server' => 'openlitespeed',
            'capabilities' => ['php' => true, 'node' => true],
            'source' => 'installer',
            'verified_at' => now(),
        ]);

        fakeDetectedWebServer(running: ['nginx'], dirs: ['/etc/nginx']);

        expect(app(ServerCapabilities::class)->reconcileWebServer())
            ->toBeNull()
            ->and(ServerCapability::query()->value('web_server'))->toBe('openlitespeed');
    });

    it('does not guess when two others are running', function () {
        ServerCapability::query()->create([
            'stack' => null,
            'web_server' => 'apache',
            'capabilities' => ['php' => true, 'node' => false],
            'source' => 'detected',
            'verified_at' => now(),
        ]);

        fakeDetectedWebServer(running: ['nginx', 'lshttpd'], dirs: ['/etc/nginx', '/usr/local/lsws']);

        // Two candidates is a judgement call. panel:doctor reports it instead.
        expect(app(ServerCapabilities::class)->reconcileWebServer())
            ->toBeNull()
            ->and(ServerCapability::query()->value('web_server'))->toBe('apache');
    });
});

it('repairs the record at the moment a feature asks for the driver', function () {
    // The point of the whole exercise. Leaving the repair to `panel:doctor`
    // means the panel knows it is wrong, keeps writing vhosts into a directory
    // that is not there, and waits to be asked — which on the real server
    // showed up as site creation failing, then WAF failing differently, then
    // every other vhost write, all of them one stored value.
    ServerCapability::query()->create([
        'stack' => null,
        'web_server' => 'apache',
        'capabilities' => ['php' => true, 'node' => true],
        'source' => 'detected',
        'verified_at' => now(),
    ]);

    fakeDetectedWebServer(running: ['lshttpd'], dirs: ['/usr/local/lsws']);

    expect(app(WebServerManager::class)->driver()->name())
        ->toBe('openlitespeed');
});

it('does not re-probe once it has looked', function () {
    ServerCapability::query()->create([
        'stack' => 'ols',
        'web_server' => 'openlitespeed',
        'capabilities' => ['php' => true, 'node' => true],
        'source' => 'installer',
        'verified_at' => now(),
    ]);

    fakeDetectedWebServer(running: ['lshttpd'], dirs: ['/usr/local/lsws']);

    $manager = app(WebServerManager::class);
    $manager->driver();
    $manager->driver();
    $manager->driver();

    // A healthy record costs a directory check and nothing else: it never
    // reaches the systemd probe, and resolving the driver repeatedly — which
    // several features do — must not turn into repeated subprocesses.
    Process::assertNothingRan();
});
