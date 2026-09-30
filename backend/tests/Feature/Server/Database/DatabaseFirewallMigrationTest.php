<?php

use App\Models\FirewallRule;

function remoteAccessMigration(): object
{
    return require database_path('migrations/2026_09_27_000000_mark_database_remote_access_firewall_rules.php');
}

it('moves remote-access rules written as default to db_user, and back', function () {
    $mariadb = FirewallRule::create(['port_from' => 3306, 'protocol' => 'tcp', 'action' => 'allow', 'source_ip' => '203.0.113.7', 'origin' => 'default', 'description' => 'MARIADB remote access']);
    $pg = FirewallRule::create(['port_from' => 5432, 'protocol' => 'tcp', 'action' => 'allow', 'source_ip' => null, 'origin' => 'default', 'description' => 'POSTGRESQL remote access']);

    remoteAccessMigration()->up();

    expect($mariadb->fresh()->origin)->toBe('db_user')
        ->and($pg->fresh()->origin)->toBe('db_user');

    remoteAccessMigration()->down();

    expect($mariadb->fresh()->origin)->toBe('default')
        ->and($pg->fresh()->origin)->toBe('default');
});

it('leaves the panel\'s own rules and the administrator\'s rules alone', function () {
    $ssh = FirewallRule::create(['port_from' => 22, 'protocol' => 'tcp', 'action' => 'allow', 'origin' => 'default']);
    $seeded3306 = FirewallRule::create(['port_from' => 3306, 'protocol' => 'tcp', 'action' => 'allow', 'origin' => 'default']);
    $mine = FirewallRule::create(['port_from' => 3306, 'protocol' => 'tcp', 'action' => 'allow', 'source_ip' => '198.51.100.1', 'origin' => 'user', 'description' => 'MARIADB remote access']);

    remoteAccessMigration()->up();

    expect($ssh->fresh()->origin)->toBe('default')
        ->and($seeded3306->fresh()->origin)->toBe('default')
        ->and($mine->fresh()->origin)->toBe('user');
});
