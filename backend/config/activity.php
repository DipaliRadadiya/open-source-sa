<?php

/*
 * Which half of the panel an activity row is about.
 *
 * `account` describes the panel's own people — who logged in, who changed a
 * password, who was given which role. `server` describes the machine. One
 * question decides every row, and the two answers are two different screens.
 *
 * Every type in lang/activity.php must appear here exactly once; a test
 * asserts it. Without that, a feature added later belongs to neither scope and
 * quietly disappears from both screens — visible to nobody, and nothing fails.
 */

return [
    /*
    | Events no code records any more — the feature is gone. Their sentences
    | stay in lang/activity.php so old rows still read properly; they are left
    | out of the admin log's filter options, where they could only ever match
    | nothing. `database.optimized`/`repaired`: removed 2026-09-08 (see
    | routes/api/server/databases.php). `application.php_unisolated`: the
    | shared pool is no longer a choice.
    */
    'retired' => [
        'database.optimized',
        'database.repaired',
        'application.php_unisolated',
    ],

    /*
    | Security events (OLD-18): who got in or failed to, who was given or lost
    | access, and what opened the server or a credential. `filter[security]=1`
    | on any activity log, and `is_security` on each row. A test asserts every
    | key here is a real event, so a renamed action cannot fall out silently.
    */
    'security' => [
        'user.logged_in',
        'user.login_failed',
        'user.login_failed_unknown',
        'user.password_changed',
        'user.password_reset_by_admin',
        'user.created',
        'user.deleted',
        'user.permissions_updated',
        'user.role_assigned',
        'user.impersonation_started',
        'user.impersonation_stopped',
        'role.created',
        'role.updated',
        'role.deleted',
        'central.connected',
        'central.disconnected',
        'firewall.enabled',
        'firewall.disabled',
        'firewall.rule_added',
        'firewall.rule_updated',
        'firewall.rule_removed',
        'fail2ban.settings_updated',
        'system_user.ssh_key_added',
        'system_user.ssh_key_removed',
        'system_user.password_set',
        'system_user.sudo_enabled',
        'system_user.sudo_disabled',
        'system_user.ssh_enabled',
        'system_user.ssh_disabled',
        'database.phpmyadmin_signed_in',
        'application.magic_login',
        'application.container_secrets_viewed',
        'docker_database.credentials_viewed',
    ],

    'scopes' => [
        'account' => [
            'user',
            'role',
            'permission',
            // Who allowed the vendor's central panel in, and who took that
            // permission back. A consent decision, not a machine operation.
            'central',
        ],

        'server' => [
            'application',
            'backup',
            // The compiler toolchain. A fact about what this machine can
            // build, so it belongs beside the runtimes rather than beside the
            // people administering the panel.
            'build_tools',
            'cronjob',
            'database',
            'disk_cleaner',
            // Containerised database engines. Server, beside `database`, for the
            // same reason: it is an engine running on this machine, and the only
            // difference is that this one is in a container.
            'docker_database',
            'fail2ban',
            'firewall',
            'git_account',
            'log',
            'node',
            'panel_update',
            'php',
            // Stored registry credentials. Server rather than account, and the
            // distinction is worth stating: it is a credential the MACHINE uses to
            // pull images, not one of the panel's people — the same reading that
            // puts `git_account` and `storage_destination` on this side.
            'registry',
            'server',
            'service',
            'setting',
            // Where this machine's backups are sent. A connection made on the
            // server's behalf, and the row sits beside the backups it exists
            // to carry — not beside the people who administer the panel.
            'storage_destination',
            // Reading a migrated box into the panel. Squarely a machine
            // operation: it creates users, sites and databases in one go.
            'sync',
            'system_user',
            'wp_cli',
        ],
    ],
];
