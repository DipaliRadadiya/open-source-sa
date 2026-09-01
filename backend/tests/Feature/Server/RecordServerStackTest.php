<?php

use App\Models\ServerCapability;
use App\Services\Server\Capabilities\ServerCapabilities;
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
