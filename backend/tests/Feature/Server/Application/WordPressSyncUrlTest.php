<?php

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Models\SystemUser;
use App\Services\Server\Applications\Installers\WordPressInstaller;
use Illuminate\Support\Facades\Process;

/**
 * Changing a WordPress site's address, as a certificate does.
 *
 * A staging site pins WP_HOME/WP_SITEURL in wp-config.php, and a constant overrides the
 * option, so updating only the options leaves the site on its old http:// address.
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
function fakeWpConfig(array $defined, bool $setFails = false, string $home = '', bool $replaceFails = false): void
{
    $GLOBALS['wpSyncRan'] = [];

    Process::fake(function ($process) use ($defined, $setFails, $home, $replaceFails) {
        $command = $process->command;
        $GLOBALS['wpSyncRan'][] = $command;

        if (in_array('option', $command, true) && in_array('get', $command, true)) {
            return Process::result(output: $home === '' ? '' : $home."\n", exitCode: $home === '' ? 1 : 0);
        }

        if ($replaceFails && in_array('search-replace', $command, true)) {
            return Process::result(errorOutput: 'Error establishing a database connection', exitCode: 1);
        }

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

    // Order matters: with the constant still on http://, `option update` fails on a row that
    // already holds the new address.
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

    // Reporting success would leave a site the panel calls HTTPS serving itself as http://.
    expect(fn () => app(WordPressInstaller::class)->syncUrl($this->application, 'https://staging.example.com'))
        ->toThrow(ProvisioningFailedException::class);

    Process::assertNotRan(fn ($process) => in_array('option', $process->command, true)
        && in_array('update', $process->command, true));
});

/** The search-replace commands syncUrl() ran, as [search, replace]. */
function syncUrlReplacements(): array
{
    return collect($GLOBALS['wpSyncRan'])
        ->filter(fn (array $c) => in_array('search-replace', $c, true))
        ->map(function (array $c) {
            $i = array_search('search-replace', $c, true);

            return [$c[$i + 1], $c[$i + 2], in_array('--regex', $c, true)];
        })
        ->values()->all();
}

describe('links in the content follow the new scheme', function () {
    it('moves the site\'s own http:// links to https:// when a certificate arrives', function () {
        // A clone is made before its certificate exists, so its self-links are http:// and
        // browsers block them as mixed content once the page is served over https.
        fakeWpConfig([], home: 'http://staging.example.com');

        app(WordPressInstaller::class)->syncUrl($this->application, 'https://staging.example.com');

        $end = '(?![A-Za-z0-9-]|\\.[A-Za-z0-9])';

        expect(syncUrlReplacements())->toBe([
            ['http\\://staging\\.example\\.com'.$end, 'https://staging.example.com', true],
            // The block editor's escaped form.
            ['http\\:\\\\/\\\\/staging\\.example\\.com'.$end, 'https:\\/\\/staging.example.com', true],
        ]);
    });

    it('turns them back when a failed certificate restores the old address', function () {
        fakeWpConfig([], home: 'https://staging.example.com');

        app(WordPressInstaller::class)->syncUrl($this->application, 'http://staging.example.com');

        expect(collect(syncUrlReplacements())->pluck(1)->all())->toBe(['http://staging.example.com', 'http:\\/\\/staging.example.com']);
    });

    it('rewrites no content on a resync that changes nothing', function () {
        // syncUrl() runs on every `sites:resync`; a full-table search-replace would be waste.
        fakeWpConfig([], home: 'https://staging.example.com');

        app(WordPressInstaller::class)->syncUrl($this->application, 'https://staging.example.com');

        expect(syncUrlReplacements())->toBe([]);
    });

    it('rewrites no content when the address could not be read, or the host changed', function () {
        fakeWpConfig([]);
        app(WordPressInstaller::class)->syncUrl($this->application, 'https://staging.example.com');
        expect(syncUrlReplacements())->toBe([]);

        // A different host is a domain change; whatever moved the domain owns its content.
        fakeWpConfig([], home: 'http://old.example.com');
        app(WordPressInstaller::class)->syncUrl($this->application, 'https://staging.example.com');
        expect(syncUrlReplacements())->toBe([]);
    });

    it('does not fail the certificate when the content cannot be rewritten', function () {
        fakeWpConfig([], home: 'http://staging.example.com', replaceFails: true);

        expect(fn () => app(WordPressInstaller::class)->syncUrl($this->application, 'https://staging.example.com'))
            ->not->toThrow(Throwable::class);
    });
});
