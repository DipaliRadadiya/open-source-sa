<?php

use App\Models\Application;
use App\Models\ApplicationPhpSettings;
use App\Models\SystemUser;
use App\Services\Server\Php\PoolManager;

/*
| A new site gets v7's PHP defaults (operator, 2026-10-07), so a site behaves
| the same on either panel: 20 workers, 30s idle, 128M uploads, 60s, 1600
| input vars, open_basedir on, and limits the site may raise itself.
*/

beforeEach(function () {
    $this->su = SystemUser::create(['username' => 'v7demo', 'home_path' => '/home/v7demo', 'shell' => '/bin/bash']);
    $this->site = Application::forceCreate([
        'system_user_id' => $this->su->id, 'name' => 'wpsite', 'slug' => 'wpsite', 'domain' => 'wpsite.example.com',
        'site_type' => 'wordpress', 'serving_profile' => 'php', 'status' => 'active', 'web_root' => '/', 'php_version' => '8.2',
    ]);
});

it('renders v7\'s pool defaults for a site that set nothing', function () {
    $pool = app(PoolManager::class)->render($this->site->load('systemUser'), new ApplicationPhpSettings);

    expect($pool)->toContain('pm = ondemand')
        ->toContain('pm.max_children = 20')
        ->toContain('pm.process_idle_timeout = 30s')
        ->toContain('pm.max_requests = 500')
        ->toContain('php_value[memory_limit] = 256M')
        ->toContain('php_value[upload_max_filesize] = 128M')
        ->toContain('php_value[post_max_size] = 128M')
        ->toContain('php_value[max_execution_time] = 60')
        ->toContain('php_value[max_input_time] = 60')
        ->toContain('php_value[max_input_vars] = 1600')
        ->toContain('php_admin_value[open_basedir] = /home/v7demo/wpsite:')
        // The limits a site may raise are php_value; nothing security-related is.
        ->not->toContain('php_value[open_basedir]')
        ->not->toContain('php_value[disable_functions]');
});

it('leaves posix_getuid and posix_getpwuid usable for Nextcloud, as v7 does', function () {
    expect(ApplicationPhpSettings::strictDisabledFunctionsFor('nextcloud'))
        ->not->toContain('posix_getuid')->not->toContain('posix_getpwuid')
        ->and(ApplicationPhpSettings::strictDisabledFunctionsFor('wordpress'))
        ->toContain('posix_getuid')->toContain('posix_getpwuid');
});
