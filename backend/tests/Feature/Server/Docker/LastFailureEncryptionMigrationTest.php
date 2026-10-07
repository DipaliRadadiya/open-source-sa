<?php

use App\Models\Application;
use App\Models\SystemUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
 * `last_failure` encrypted at rest (DS-08). A row written before the change is
 * plaintext JSON; the migration encrypts it in place so the cast can read it,
 * and `down()` puts it back.
 */

it('encrypts a failure written before the change, and puts it back on rollback', function () {
    $migration = require database_path('migrations/2026_10_07_230000_encrypt_application_last_failure.php');
    $user = SystemUser::create(['username' => 'memos', 'home_path' => '/home/memos']);
    $application = Application::forceCreate([
        'system_user_id' => $user->id, 'name' => 'Memos', 'slug' => 'memos', 'domain' => 'memos.test',
        'web_root' => 'public_html', 'site_type' => 'docker', 'serving_profile' => 'docker',
        'php_version' => '8.4', 'app_port' => 20001, 'image' => 'neosmemo/memos:0.31.0', 'container_port' => 5230,
    ]);

    $migration->down();
    $plain = json_encode(['reason' => 'container_exited', 'params' => [], 'log' => 'DATABASE_URL=postgres://u:hunter2@db/x']);
    DB::table('applications')->where('id', $application->id)->update(['last_failure' => $plain]);

    $migration->up();

    $raw = (string) DB::table('applications')->where('id', $application->id)->value('last_failure');
    expect($raw)->not->toContain('hunter2')
        ->and($application->fresh()->last_failure['log'])->toBe('DATABASE_URL=postgres://u:hunter2@db/x');

    $migration->down();
    expect(json_decode((string) DB::table('applications')->where('id', $application->id)->value('last_failure'), true)['log'])
        ->toContain('hunter2');

    $migration->up();
});
