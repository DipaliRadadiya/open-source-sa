<?php

use App\Models\PanelUpdate;
use App\Services\Panel\FrontendUnit;
use App\Services\Panel\UpdateScript;
use Illuminate\Support\Facades\Process;

/*
 * Bug #15: the panel's web interface listened on every address. Next's
 * standalone server binds `HOSTNAME || '0.0.0.0'`, and the unit set only PORT.
 */

describe('the loopback line', function () {
    it('is added right after PORT', function () {
        expect(FrontendUnit::withLoopback("[Service]\nEnvironment=PORT=3100\nEnvironment=NODE_ENV=production\n"))
            ->toBe("[Service]\nEnvironment=PORT=3100\nEnvironment=HOSTNAME=127.0.0.1\nEnvironment=NODE_ENV=production\n");
    });

    it('leaves a unit that already has it, sets its own HOSTNAME, or has no PORT', function () {
        expect(FrontendUnit::withLoopback("Environment=PORT=3100\nEnvironment=HOSTNAME=127.0.0.1\n"))->toBeNull()
            // An operator who chose an address keeps it.
            ->and(FrontendUnit::withLoopback("Environment=PORT=3100\nEnvironment=HOSTNAME=10.0.0.5\n"))->toBeNull()
            ->and(FrontendUnit::withLoopback("[Service]\nExecStart=/usr/bin/node server.js\n"))->toBeNull();
    });
});

describe('panel:frontend-unit', function () {
    it('rewrites the unit and reloads systemd, without restarting the interface', function () {
        $dir = sys_get_temp_dir().'/fu-'.uniqid();
        mkdir($dir);
        config(['server.applications.systemd_dir' => $dir, 'panel_update.services.frontend' => 'panel-frontend.service']);
        file_put_contents("{$dir}/panel-frontend.service", "[Service]\nEnvironment=PORT=3100\n");
        Process::fake();

        $this->artisan('panel:frontend-unit')->assertSuccessful();

        expect(file_get_contents("{$dir}/panel-frontend.service"))->toContain(FrontendUnit::LISTEN);
        Process::assertRan(fn ($process) => $process->command === ['systemctl', 'daemon-reload']);
        Process::assertNotRan(fn ($process) => in_array('restart', (array) $process->command, true));

        $this->artisan('panel:frontend-unit')->expectsOutputToContain('is up to date')->assertSuccessful();

        array_map('unlink', glob("{$dir}/*"));
        rmdir($dir);
    });

    it('does nothing where there is no unit', function () {
        config(['server.applications.systemd_dir' => sys_get_temp_dir().'/fu-absent-'.uniqid()]);
        Process::fake();

        $this->artisan('panel:frontend-unit')->assertSuccessful();

        Process::assertNothingRan();
    });
});

describe('the installer and the updater agree', function () {
    it('installs new servers with the loopback line', function () {
        $install = (string) file_get_contents(base_path('../install.sh'));

        expect($install)->toContain(FrontendUnit::LISTEN);
    });

    it('updates existing servers before the interface is restarted, without failing the update', function () {
        $script = app(UpdateScript::class)->render(
            new PanelUpdate(['id' => 1, 'from_commit' => str_repeat('a', 40)]),
            '1.0.17',
        );

        $line = collect(explode("\n", $script))->first(fn (string $l) => str_contains($l, 'panel:frontend-unit'));

        expect($line)->toContain('|| echo "WARNING')
            ->and(strpos($script, 'note configure_frontend_unit'))->toBeLessThan(strpos($script, 'note restart_services'));
    });
});
