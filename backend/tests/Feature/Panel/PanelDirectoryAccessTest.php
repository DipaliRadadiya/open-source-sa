<?php

use App\Services\Panel\PanelDirectoryAccess;
use Illuminate\Support\Facades\Process;

/**
 * The panel's checkout closed to every local account but the web server's.
 *
 * Found on the Apache test server, logged in as an ordinary SSH user the panel
 * had created: `bootstrap/cache/config.php` (APP_KEY, Redis password) and
 * `database/database.sqlite` were both 644 under a 755 tree. The same on the
 * nginx and OpenLiteSpeed servers.
 */
beforeEach(function () {
    config(['server.web_server' => 'nginx', 'server.web_server_user' => 'www-data']);

    $this->root = dirname(base_path());
    $this->acl = null;                  // getfacl output after a setfacl -m
    $this->aclInstalled = true;
    $this->ran = new ArrayObject;

    Process::fake(function ($process) {
        $args = $process->command[0] === 'sudo' ? array_slice($process->command, 2) : $process->command;
        test()->ran[] = $args;

        return match ($args[0] ?? '') {
            'which' => Process::result(exitCode: test()->aclInstalled ? 0 : 1),
            'apt-get' => Process::result(exitCode: 1),
            'setfacl' => (function () use ($args) {
                if (($args[1] ?? '') === '-m') {
                    test()->acl = ['user::rwx', 'user:'.explode(':', $args[2])[1].':--x', 'group::r-x', 'mask::r-x', 'other::---'];
                }

                return Process::result();
            })(),
            'getfacl' => Process::result(output: implode("\n", test()->acl ?? ['user::rwx', 'group::r-x', 'other::r-x'])."\n"),
            default => Process::result(),
        };
    });
});

function panelSetfaclRuns(): array
{
    return collect(test()->ran)->filter(fn ($a) => ($a[0] ?? '') === 'setfacl')->values()->all();
}

it('closes the checkout to everyone but the web server', function () {
    expect(app(PanelDirectoryAccess::class)->secure())->toBe(PanelDirectoryAccess::SECURED)
        ->and(panelSetfaclRuns())->toBe([['setfacl', '-m', 'u:www-data:--x,o::---', $this->root]]);
});

it('closes the directory holding backend/, never the backend alone or its parent', function () {
    // Closing backend/ would leave frontend/ and .git readable; closing the
    // parent (/var/www) would take every other site served from it offline.
    expect(app(PanelDirectoryAccess::class)->root())->toBe(dirname(base_path()))
        ->not->toBe(base_path())
        ->not->toBe(dirname(base_path(), 2));
});

it('does nothing when it is already closed', function () {
    $this->acl = ['user::rwx', 'user:www-data:--x', 'group::r-x', 'mask::r-x', 'other::---'];

    expect(app(PanelDirectoryAccess::class)->secure())->toBe(PanelDirectoryAccess::ALREADY)
        ->and(panelSetfaclRuns())->toBe([]);
});

it('reports a failure when the ACL does not read back, rather than trusting the exit code', function () {
    Process::fake(fn ($process) => Process::result());

    expect(app(PanelDirectoryAccess::class)->secure())->toBe(PanelDirectoryAccess::FAILED);
});

it('leaves the directory open when setfacl cannot be installed', function () {
    $this->aclInstalled = false;

    expect(app(PanelDirectoryAccess::class)->secure())->toBe(PanelDirectoryAccess::NO_ACL)
        ->and(panelSetfaclRuns())->toBe([]);
});

it('runs from sites:resync, so every existing server is closed on its next update', function () {
    $this->artisan('sites:resync')->expectsOutputToContain('Panel directory:')->assertSuccessful();

    expect(panelSetfaclRuns())->toContain(['setfacl', '-m', 'u:www-data:--x,o::---', $this->root]);
});

it('is run by the installer as the panel account', function () {
    $script = (string) file_get_contents(dirname(base_path()).'/install.sh');

    expect($script)->toMatch('/sudo -u "\$APP_USER".*artisan panel:close-directory/');
});

it('can be undone in one step', function () {
    $this->artisan('panel:close-directory', ['--open' => true])->assertSuccessful();

    expect(panelSetfaclRuns())->toBe([['setfacl', '-b', $this->root]])
        ->and(collect($this->ran)->contains(['chmod', 'o+rx', $this->root]))->toBeTrue();
});
