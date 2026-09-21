<?php

use Illuminate\Support\Facades\File;

/**
 * The panel is white-labelled, so an upstream brand must not appear in anything
 * written onto a customer's server — cron files, fail2ban config, a site's own
 * `.env`, a systemd unit. A reseller's client looking at their own box should see
 * their reseller's product, or nothing.
 *
 * This is a guard rather than a unit test because the failure mode is a single
 * hardcoded string in a template that nobody notices for a year. Nothing else in
 * the suite would catch it: the strings are comments, so every functional test
 * passes with the brand in place.
 */
it('does not stamp a product name into anything written to the server', function () {
    // Configurable branding is exempt: `config/branding.php` exists precisely so
    // a deployment can set its own name, and the default is only a fallback.
    $searched = [
        base_path('app'),
        base_path('resources/views/server'),
        base_path('routes'),
    ];

    $brands = ['ServerAvatar', 'serveravatar'];
    $offenders = [];

    foreach ($searched as $directory) {
        foreach (File::allFiles($directory) as $file) {
            $contents = $file->getContents();

            foreach ($brands as $brand) {
                if (str_contains($contents, $brand)) {
                    $offenders[] = $file->getRelativePathname().' contains "'.$brand.'"';
                }
            }
        }
    }

    expect($offenders)->toBe([], implode("\n", $offenders));
});

it('keeps the installer free of product names too', function () {
    // Every artifact install.sh creates — the account, paths, units, sudoers and
    // cron files — is named from PANEL_SLUG so that rebranding is one value
    // rather than a grep across a 1000-line script.
    $installer = dirname(base_path()).'/install.sh';

    if (! is_file($installer)) {
        // The backend is also installable on its own, without the mono-repo root.
        expect(true)->toBeTrue();

        return;
    }

    $contents = (string) file_get_contents($installer);

    expect($contents)->not->toContain('ServerAvatar');
    expect($contents)->not->toContain('serveravatar');
    expect($contents)->toContain('PANEL_SLUG');
});

/*
| The brand is resolved in one place, and that place is config/branding.php.
|
| `BRANDING_NAME=` with nothing after it is a *set* variable, so env()'s default
| never applied and the name resolved to an empty string. Every consumer then
| invented its own fallback — and one of them put the product name inside
| `app/`, which is what the test above forbids and what reached a folder
| created in a customer's personal Google Drive.
*/

it('resolves a blank branding name to the default, not to nothing', function () {
    $resolved = trim((string) config('branding.name'));

    expect($resolved)->not->toBe('', 'branding.name must never resolve empty');
});

it('never builds the central name from an empty brand', function () {
    // `'' . " Central"` is " Central", with a leading space and no product in
    // it — the same class of bug one field along.
    $central = trim((string) config('branding.central_name'));

    expect($central)->not->toBe('')
        ->and($central)->not->toStartWith('Central');
});
