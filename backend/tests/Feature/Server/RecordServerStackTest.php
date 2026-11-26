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
    // `installer` rather than `detected`: the installer knows what it built.
    expect($record->source)->toBe('installer');
});

it('maps mern to nginx, because mern is not a web server', function () {
    $this->artisan('server:record-stack', ['stack' => 'mern'])->assertSuccessful();

    expect(ServerCapability::query()->value('web_server'))->toBe('nginx');
});

it('refuses an unknown stack without touching the record', function () {
    $this->artisan('server:record-stack', ['stack' => 'lemp'])->assertSuccessful();

    $this->artisan('server:record-stack', ['stack' => 'kubernetes'])->assertFailed();

    // The earlier value survives: a typo in the installer must not blank what the panel knew.
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
 * Every vhost the panel writes hangs off this value: a wrong one picks ApacheDriver on an
 * OpenLiteSpeed server and site creation fails writing under /etc/apache2.
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
        // Apache purged, its /etc directory left behind, and apache listed before openlitespeed:
        // a directory is not a web server.
        fakeDetectedWebServer(running: ['lshttpd'], dirs: ['/etc/apache2/sites-available', '/usr/local/lsws/conf/vhosts']);

        expect(app(ServerCapabilities::class)->webServer())
            ->toBe('openlitespeed');
    });

    it('still answers when nothing is running', function () {
        // Installed but stopped is still the box's web server; the setup screen must name it.
        fakeDetectedWebServer(running: [], dirs: ['/usr/local/lsws/conf/vhosts']);

        expect(app(ServerCapabilities::class)->webServer())
            ->toBe('openlitespeed');
    });

    /*
     * The phantom /etc/apache2.
     *
     * `phpX.Y-fpm` ships `conf-available/phpX.Y-fpm.conf`, which creates /etc/apache2 and
     * /etc/apache2/conf-available. Every stack installs php-fpm, so an OpenLiteSpeed box that
     * never had Apache still has /etc/apache2, and `apt purge` cannot remove it.
     *
     * Detecting on `sites-available` settles it: only `apache2` ships that directory.
     */
    it('is not fooled by the /etc/apache2 that php-fpm ships', function () {
        // A clean OLS box: no Apache, but php-fpm's directory is there.
        fakeDetectedWebServer(
            running: ['lshttpd'],
            dirs: ['/etc/apache2', '/etc/apache2/conf-available', '/usr/local/lsws/conf/vhosts'],
        );

        expect(app(ServerCapabilities::class)->webServer())
            ->toBe('openlitespeed');
    });

    it('is not fooled by it while OpenLiteSpeed is stopped either', function () {
        // Nothing is running, so only the directory list decides. This is the installer's window:
        // php-fpm is installed before OpenLiteSpeed starts.
        fakeDetectedWebServer(
            running: [],
            dirs: ['/etc/apache2', '/etc/apache2/conf-available', '/usr/local/lsws/conf/vhosts'],
        );

        expect(app(ServerCapabilities::class)->webServer())
            ->toBe('openlitespeed');
    });

    it('still detects a real Apache install', function () {
        // The other half: tightening the probe must not blind the panel to a real Apache.
        // `sites-available` is what the apache2 package ships.
        fakeDetectedWebServer(
            running: ['apache2'],
            dirs: ['/etc/apache2', '/etc/apache2/conf-available', '/etc/apache2/sites-available'],
        );

        expect(app(ServerCapabilities::class)->webServer())
            ->toBe('apache');
    });
});

/*
 * Repairing a record the box contradicts.
 *
 * The panel uses the stored record, not detection, so a wrong one does not correct itself
 * unless it is reconciled.
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
        fakeDetectedWebServer(running: ['lshttpd'], dirs: ['/usr/local/lsws/conf/vhosts']);

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

        // Apache installed but stopped, OpenLiteSpeed running alongside it. A stopped web server
        // is not a cue to decide the box runs something else.
        fakeDetectedWebServer(running: ['lshttpd'], dirs: ['/etc/apache2/sites-available', '/usr/local/lsws/conf/vhosts']);

        expect(app(ServerCapabilities::class)->reconcileWebServer())
            ->toBeNull()
            ->and(ServerCapability::query()->value('web_server'))->toBe('apache');
    });

    it('repairs an OLS box recorded as apache by the phantom directory', function () {
        // Detection ran before the installer's record and picked apache off php-fpm's
        // /etc/apache2. The repair must not trust that same phantom directory for its "is the
        // recorded server installed?" test, or it never fires and only `server:record-stack ols`
        // can fix the record.
        ServerCapability::query()->create([
            'stack' => null,
            'web_server' => 'apache',
            'capabilities' => ['php' => true, 'node' => false],
            'source' => 'detected',
            'verified_at' => now(),
        ]);

        fakeDetectedWebServer(
            running: ['lshttpd'],
            dirs: ['/etc/apache2', '/etc/apache2/conf-available', '/usr/local/lsws/conf/vhosts'],
        );

        expect(app(ServerCapabilities::class)->reconcileWebServer())->toBe('openlitespeed')
            ->and(ServerCapability::query()->value('web_server'))->toBe('openlitespeed');
    });

    it('never overrules the installer', function () {
        // `source: installer` states what was built; a filesystem check is weaker, and overriding
        // it would write vhosts for a web server nobody chose. panel:doctor reports the
        // disagreement instead.
        ServerCapability::query()->create([
            'stack' => 'ols',
            'web_server' => 'openlitespeed',
            'capabilities' => ['php' => true, 'node' => true],
            'source' => 'installer',
            'verified_at' => now(),
        ]);

        fakeDetectedWebServer(running: ['nginx'], dirs: ['/etc/nginx/sites-available']);

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

        fakeDetectedWebServer(running: ['nginx', 'lshttpd'], dirs: ['/etc/nginx/sites-available', '/usr/local/lsws/conf/vhosts']);

        // Two candidates is a judgement call. panel:doctor reports it instead.
        expect(app(ServerCapabilities::class)->reconcileWebServer())
            ->toBeNull()
            ->and(ServerCapability::query()->value('web_server'))->toBe('apache');
    });
});

it('repairs the record at the moment a feature asks for the driver', function () {
    // Leaving the repair to `panel:doctor` would keep writing vhosts into a directory that is
    // not there; every vhost write depends on this one stored value.
    ServerCapability::query()->create([
        'stack' => null,
        'web_server' => 'apache',
        'capabilities' => ['php' => true, 'node' => true],
        'source' => 'detected',
        'verified_at' => now(),
    ]);

    fakeDetectedWebServer(running: ['lshttpd'], dirs: ['/usr/local/lsws/conf/vhosts']);

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

    fakeDetectedWebServer(running: ['lshttpd'], dirs: ['/usr/local/lsws/conf/vhosts']);

    $manager = app(WebServerManager::class);
    $manager->driver();
    $manager->driver();
    $manager->driver();

    // A healthy record costs a directory check only: resolving the driver repeatedly must not
    // spawn repeated subprocesses.
    Process::assertNothingRan();
});

/*
 * Detection must not depend on one exact directory.
 *
 * Only Apache has a decoy directory, so only Apache needs the narrow check; narrowing nginx and
 * OpenLiteSpeed to their vhost directory makes doctor report "none found" on a working box.
 */
describe('detection tolerates either directory', function () {
    it('finds OpenLiteSpeed from the server root alone', function () {
        fakeDetectedWebServer(running: ['lshttpd'], dirs: ['/usr/local/lsws']);

        expect(app(ServerCapabilities::class)->webServer())->toBe('openlitespeed');
    });

    it('finds nginx from /etc/nginx alone', function () {
        fakeDetectedWebServer(running: ['nginx'], dirs: ['/etc/nginx']);

        expect(app(ServerCapabilities::class)->webServer())->toBe('nginx');
    });

    // The one that must stay narrow: php-fpm ships /etc/apache2, so a bare one is not Apache.
    it('still refuses to call php-fpm\'s /etc/apache2 an Apache install', function () {
        fakeDetectedWebServer(
            running: ['lshttpd'],
            dirs: ['/etc/apache2', '/etc/apache2/conf-available', '/usr/local/lsws'],
        );

        expect(app(ServerCapabilities::class)->webServer())->toBe('openlitespeed');
    });
});

/*
 * install.sh records the stack before it writes the sudoers grant, and `which` is privileged,
 * so `sudo -n which node` is refused: Node must be found without sudo.
 */
describe('finding Node before sudo is set up', function () {
    beforeEach(function () {
        $this->binDir = sys_get_temp_dir().'/sv-oss-capbin-'.getmypid();
        File::deleteDirectory($this->binDir);
        File::makeDirectory($this->binDir, 0755, true);
        config(['server.binary_search_path' => [$this->binDir]]);

        // Exactly what an install sees: every sudo refused.
        Process::fake(['*' => Process::result(errorOutput: 'sudo: a password is required', exitCode: 1)]);
    });

    afterEach(fn () => File::deleteDirectory($this->binDir));

    it('records Node on a fresh MERN install', function () {
        File::put("{$this->binDir}/node", "#!/bin/sh\n");
        chmod("{$this->binDir}/node", 0755);

        $this->artisan('server:record-stack', ['stack' => 'mern'])->assertSuccessful();

        expect(app(ServerCapabilities::class)->supports('node'))->toBeTrue();
    });

    it('still records no Node when there is none', function () {
        $this->artisan('server:record-stack', ['stack' => 'mern'])->assertSuccessful();

        expect(app(ServerCapabilities::class)->supports('node'))->toBeFalse();
    });

    it('does not count a file that cannot be run', function () {
        File::put("{$this->binDir}/node", "x\n");
        chmod("{$this->binDir}/node", 0644);

        $this->artisan('server:record-stack', ['stack' => 'mern'])->assertSuccessful();

        expect(app(ServerCapabilities::class)->supports('node'))->toBeFalse();
    });

    it('corrects an old wrong record on the next deploy', function () {
        $this->artisan('server:record-stack', ['stack' => 'mern'])->assertSuccessful();
        expect(app(ServerCapabilities::class)->supports('node'))->toBeFalse();

        File::put("{$this->binDir}/node", "#!/bin/sh\n");
        chmod("{$this->binDir}/node", 0755);

        $this->artisan('runtimes:repair-node')->assertSuccessful();

        expect(app(ServerCapabilities::class)->supports('node'))->toBeTrue();
    });
});
