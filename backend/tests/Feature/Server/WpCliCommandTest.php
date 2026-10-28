<?php

use App\Models\Application;
use App\Models\ServerCapability;
use App\Models\SystemUser;
use App\Services\Server\WpCli\WpCli;

/*
| wp-cli runs on the site's own PHP, not on whatever its `#!/usr/bin/env php`
| shebang finds (2026-10-28: a site on 7.4 had staging, clone and magic login
| run on the server default 8.4).
*/

function wpCliSite(?string $php): Application
{
    $owner = SystemUser::firstOrCreate(['username' => 'wpcliowner'], ['home_path' => '/home/wpcliowner']);

    return Application::forceCreate([
        'system_user_id' => $owner->id, 'name' => 'Blog '.uniqid(), 'slug' => 'blog-'.uniqid(), 'domain' => uniqid().'.test',
        'site_type' => 'wordpress', 'serving_profile' => 'php', 'status' => 'active', 'php_version' => $php,
    ]);
}

it('runs wp-cli on the site\'s PHP on nginx and Apache', function () {
    config(['server.php_binary_pattern' => '/usr/bin/php{version}', 'server.installers.wordpress.wp_cli' => '/usr/local/bin/wp']);

    expect(app(WpCli::class)->command(wpCliSite('7.4')))->toBe(['/usr/bin/php7.4', '/usr/local/bin/wp']);
});

it('runs wp-cli on the LiteSpeed PHP on OpenLiteSpeed', function () {
    ServerCapability::create(['stack' => 'ols', 'web_server' => 'openlitespeed', 'capabilities' => ['php' => true], 'source' => 'installer', 'verified_at' => now()]);

    expect(app(WpCli::class)->command(wpCliSite('7.4'))[0])->toEndWith('/lsphp74/bin/php');
});

it('leaves a site with no PHP version on the server default', function () {
    config(['server.installers.wordpress.wp_cli' => '/usr/local/bin/wp']);

    expect(app(WpCli::class)->command(wpCliSite(null)))->toBe(['/usr/local/bin/wp']);
});
