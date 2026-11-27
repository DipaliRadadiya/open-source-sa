<?php

/*
 * Which half of the panel an activity row is about.
 *
 * `account` is the panel's own people (logins, passwords, roles); `server` is the machine.
 *
 * Every type in lang/activity.php must appear here exactly once (a test asserts it);
 * otherwise a new feature would silently show on neither screen.
 */

return [
    /*
    | Events no code records any more. Their sentences stay in lang/activity.php so old
    | rows still read properly; they are left out of the admin log's filter options.
    */
    'retired' => [
        'database.optimized',
        'database.repaired',
        'application.php_unisolated',
    ],

    /*
    | Security events: who got in or failed to, who was given or lost access, and what
    | opened the server or a credential. Drives `filter[security]=1` and `is_security`.
    | A test asserts every key is a real event.
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
        'fail2ban.ignore_ip_added',
        'fail2ban.ignore_ip_removed',
        'system_user.ssh_key_added',
        'system_user.ssh_key_removed',
        'system_user.password_set',
        'system_user.sudo_enabled',
        'system_user.sudo_disabled',
        'system_user.ssh_enabled',
        'system_user.ssh_disabled',
        'database.phpmyadmin_signed_in',
        'database.adminer_signed_in',
        'database.root_password_changed',
        'database.remote_access_closed',
        'application.magic_login',
        'application.container_secrets_viewed',
        'docker_database.credentials_viewed',
    ],

    'scopes' => [
        'account' => [
            'user',
            'role',
            'permission',
            // Consent for the vendor's central panel: a decision, not a machine operation.
            'central',
        ],

        'server' => [
            // The paid addon binaries Central installs and removes.
            'addon',
            'application',
            'backup',
            // The compiler toolchain: what this machine can build, like the runtimes.
            'build_tools',
            'cronjob',
            'database',
            'disk_cleaner',
            // Containerised database engines: server scope, like `database`.
            'docker_database',
            'fail2ban',
            'firewall',
            'git_account',
            'log',
            'node',
            'panel_update',
            'php',
            // Registry credentials: the machine uses them to pull images (server scope, like
            // `git_account` and `storage_destination`), not one of the panel's people.
            'registry',
            'server',
            'service',
            'setting',
            // Where this machine's backups are sent.
            'storage_destination',
            // Reading a migrated box into the panel: creates users, sites and databases.
            'sync',
            'system_user',
            'wp_cli',
        ],
    ],
];
