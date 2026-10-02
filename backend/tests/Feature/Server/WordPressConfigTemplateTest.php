<?php

use Illuminate\Support\Facades\View;

/**
 * WP-CLI adds a constant that is not in wp-config.php yet just above
 * WordPress's "That's all, stop editing!" marker. The template had none, so
 * on every WordPress site the panel installed, `wp config set` of any new
 * constant failed ("Could not process the 'wp-config.php' transformation") --
 * found on the nginx test server, 2026-10-02.
 */
function renderedWpConfig(): string
{
    return View::make('server.apps.wordpress.wp-config', [
        'database' => 'db', 'username' => 'u', 'password' => 'p', 'host' => 'localhost',
        'salts' => ['AUTH_KEY' => 'x'], 'prefix' => 'wp_',
    ])->render();
}

it('carries the marker WP-CLI adds new constants at, before WordPress is loaded', function () {
    $config = renderedWpConfig();

    $marker = strpos($config, "/* That's all, stop editing!");
    $settings = strpos($config, "require_once ABSPATH . 'wp-settings.php';");

    expect($marker)->not->toBeFalse()
        ->and($settings)->not->toBeFalse()
        // Above the require: a constant defined after wp-settings.php loads does nothing.
        ->and($marker)->toBeLessThan($settings);
});

it('still renders valid PHP', function () {
    $file = tempnam(sys_get_temp_dir(), 'wpconfig');
    file_put_contents($file, renderedWpConfig());

    exec('php -l '.escapeshellarg($file).' 2>&1', $out, $code);
    unlink($file);

    expect($code)->toBe(0, implode("\n", $out));
});
