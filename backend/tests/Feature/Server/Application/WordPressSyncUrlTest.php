<?php

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Models\SystemUser;
use App\Services\Server\Applications\Installers\WordPressInstaller;
use Illuminate\Support\Facades\Process;

/**
 * Changing a WordPress site's address, as a certificate does.
 *
 * A staging site pins WP_HOME/WP_SITEURL in wp-config.php, and a constant
 * overrides the option. Updating only the options left a staging site on the
 * http:// it was created with after its certificate was issued, and once the
 * database already held the new address `wp option update` failed, so every
 * `sites:resync` of that site failed too.
 */
beforeEach(function () {
    $systemUser = SystemUser::create([
        'username' => 'wpuser', 'home_path' => '/home/wpuser', 'shell' => '/bin/bash', 'sudo' => false,
    ]);

    $this->application = Application::forceCreate([
        'system_user_id' => $systemUser->id,
        'name' => 'Staging', 'slug' => 'staging', 'domain' => 'staging.example.com',
        'site_type' => 'wordpress', 'serving_profile' => 'php', 'php_version' => '8.4',
        'web_root' => '/', 'status' => 'active',
    ]);
});

/**
 * @param  array<int, string>  $defined  constants `wp config has` answers yes for
 */
function fakeWpConfig(array $defined, bool $setFails = false): void
{
    $GLOBALS['wpSyncRan'] = [];

    Process::fake(function ($process) use ($defined, $setFails) {
        $command = $process->command;
        $GLOBALS['wpSyncRan'][] = $command;

        if (in_array('config', $command, true) && in_array('has', $command, true)) {
            return Process::result(exitCode: array_intersect($defined, $command) === [] ? 1 : 0);
        }

        if ($setFails && in_array('config', $command, true) && in_array('set', $command, true)) {
            return Process::result(errorOutput: 'wp-config.php is not writable', exitCode: 1);
        }

        return Process::result(exitCode: 0);
    });
}

it('rewrites pinned WP_HOME and WP_SITEURL before the options', function () {
    fakeWpConfig(['WP_HOME', 'WP_SITEURL']);

    app(WordPressInstaller::class)->syncUrl($this->application, 'https://staging.example.com');

    foreach (['WP_HOME', 'WP_SITEURL'] as $constant) {
        Process::assertRan(fn ($process) => in_array('runuser', $process->command, true)
            && array_diff(['config', 'set', $constant, 'https://staging.example.com', '--type=constant'], $process->command) === []);
    }

    // Order matters: with the constant still on http://, WordPress reads the
    // option back as http:// and `option update` fails on a row that already
    // holds the new address.
    $commands = collect($GLOBALS['wpSyncRan']);
    $lastSet = $commands->search(fn ($c) => in_array('set', $c, true) && in_array('WP_SITEURL', $c, true));
    $firstOption = $commands->search(fn ($c) => in_array('option', $c, true) && in_array('update', $c, true));

    expect($lastSet)->not->toBeFalse()
        ->and($firstOption)->not->toBeFalse()
        ->and($lastSet)->toBeLessThan($firstOption);
});

it('writes no constant on a site that does not define one', function () {
    fakeWpConfig([]);

    app(WordPressInstaller::class)->syncUrl($this->application, 'https://staging.example.com');

    Process::assertNotRan(fn ($process) => in_array('config', $process->command, true)
        && in_array('set', $process->command, true));

    foreach (['home', 'siteurl'] as $option) {
        Process::assertRan(fn ($process) => array_diff(['option', 'update', $option, 'https://staging.example.com'], $process->command) === []);
    }
});

it('fails the address change when a pinned constant cannot be rewritten', function () {
    fakeWpConfig(['WP_HOME', 'WP_SITEURL'], setFails: true);

    // Reporting success here would leave a site the panel calls HTTPS
    // serving itself as http:// — exactly the state this fix exists to end.
    expect(fn () => app(WordPressInstaller::class)->syncUrl($this->application, 'https://staging.example.com'))
        ->toThrow(ProvisioningFailedException::class);

    Process::assertNotRan(fn ($process) => in_array('option', $process->command, true)
        && in_array('update', $process->command, true));
});
