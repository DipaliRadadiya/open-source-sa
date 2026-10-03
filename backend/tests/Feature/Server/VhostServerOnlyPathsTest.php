<?php

use App\Models\Application;
use App\Models\SystemUser;
use App\Services\Server\ServerAddresses;
use App\Services\Server\WebServers\ApacheDriver;
use App\Services\Server\WebServers\NginxDriver;
use App\Services\Server\WebServers\OlsDriver;

/*
 * Bug #75: WordPress runs its scheduled work by requesting its own
 * wp-cron.php over HTTP, and Password Protection answered that request with
 * the 401 every visitor gets, so nothing scheduled ever ran. Each web server
 * now lets that one path through without a password, and only from this
 * server's own addresses.
 */

beforeEach(function () {
    $user = SystemUser::create(['username' => 'siteowner', 'home_path' => '/home/siteowner']);

    $this->application = Application::forceCreate([
        'system_user_id' => $user->id,
        'name' => 'Blog', 'slug' => 'blog', 'domain' => 'blog.example.com',
        'site_type' => 'wordpress', 'serving_profile' => 'php',
        'status' => 'active', 'web_root' => '/', 'php_version' => '8.4',
        'basic_auth_enabled' => true, 'basic_auth_username' => 'qa',
    ])->load('systemUser');

    $this->root = '/home/siteowner/blog/public_html';

    // A NAT'd server: a private interface address and a public one.
    $addresses = Mockery::mock(ServerAddresses::class);
    $addresses->shouldReceive('local')->andReturn(['127.0.0.1', '::1', '10.50.0.52', '23.172.120.118']);
    app()->instance(ServerAddresses::class, $addresses);
});

it('turns the nginx password off only for wp-cron.php from the server itself', function () {
    $config = app(NginxDriver::class)->renderConfig($this->application, $this->root);

    expect($config)
        ->toContain('if ($uri ~ "^(/wp\\-cron\\.php)$")')
        ->toContain('if ($remote_addr ~ "^(127\\.0\\.0\\.1|\\:\\:1|10\\.50\\.0\\.52|23\\.172\\.120\\.118)$")')
        // Both must hold: the path alone, or the address alone, is not enough.
        ->toContain('if ($sv_auth_open = "ps")')
        ->toContain('auth_basic           $sv_auth_realm;')
        ->not->toContain('auth_basic           "Restricted";');
});

it('lets Apache skip the password for that path from those addresses, under the same firewall', function () {
    $config = app(ApacheDriver::class)->renderConfig($this->application, $this->root);

    expect($config)
        ->toContain('Require expr "%{REQUEST_URI} =~ m#^(/wp\\-cron\\.php)$#"')
        ->toContain('Require ip 127.0.0.1 ::1 10.50.0.52 23.172.120.118')
        ->toContain('Require valid-user');
});

it('gives OpenLiteSpeed a password-free context for that path, open to the server only', function () {
    $config = app(OlsDriver::class)->renderConfig($this->application, $this->root);

    expect($config)
        ->toMatch('#context /wp-cron\.php \{\n  location\s+/home/siteowner/blog/public_html/wp-cron\.php#')
        ->toContain('allow                 127.0.0.1, ::1, 10.50.0.52, 23.172.120.118')
        ->toContain('deny                  ALL');
});

it('opens nothing on a site type that does not call itself', function () {
    $this->application->forceFill(['site_type' => 'php'])->save();
    $app = $this->application->fresh('systemUser');

    foreach ([NginxDriver::class, ApacheDriver::class, OlsDriver::class] as $driver) {
        expect(app($driver)->renderConfig($app, $this->root))
            ->not->toContain('wp-cron')
            ->not->toContain('sv_auth_open');
    }
});
