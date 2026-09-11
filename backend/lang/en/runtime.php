<?php

return [

    /*
    | Why an install failed, keyed by the `reason` code stored on the
    | install row. Built at read time in the *viewer's* locale — the
    | raw apt or fnm output is never shown, only referenced.
    */

    'install_failed' => [
        'package_not_found' => 'No package for :version is available from this server\'s package sources.',
        'apt_lock' => 'Another package operation is already running. Try again in a moment.',
        'network' => 'The package repository could not be reached. Check the server has network access.',
        'no_space' => 'The server has run out of disk space.',
        'worker' => 'The install stopped unexpectedly. It may have timed out — try again.',
        'unknown' => 'The install failed. Quote the reference below to support.',
        'dpkg_broken' => 'This server needs its package database repaired before anything else can install.',
        'port_in_use_by_mysql' => 'MySQL is already installed and owns this port. Remove it first, or keep using it.',
        'port_in_use_by_mariadb' => 'MariaDB is already installed and owns this port. Remove it first, or keep using it.',
        'root_unreachable' => 'It is installed but the panel could not sign in to it. Its administrator login has been changed from the default, so the panel needs those details to continue.',
        'cluster_missing' => 'PostgreSQL is installed but no cluster exists on this server. It may have been removed, or its setup did not finish.',
        'grant_failed' => 'It is installed but the panel could not create its own account on it.',
        'repository_failed' => 'The MongoDB package repository could not be added. Check the server has network access to repo.mongodb.org.',
        'unreachable' => 'It installed but did not start answering. Quote the reference below to support.',
        'auth_required' => 'MongoDB is already installed here and requires a sign-in the panel does not have. Add its credentials in the connection settings and try again.',
        'auth_config_present' => 'MongoDB is installed and its configuration already sets a security section. The panel has left it untouched — enable authorization there yourself, then try again.',
        'auth_failed' => 'It installed but authentication could not be switched on. Quote the reference below to support.',
    ],

    'uninstall_failed' => [
        'failed' => 'PHP :version could not be removed. Quote the reference below to support.',
        'worker' => 'Removing PHP :version stopped unexpectedly. It may have timed out — try again.',
        'unknown' => 'PHP :version could not be removed. Quote the reference below to support.',
    ],

    'extension_install_failed' => [
        'package_not_found' => 'No package for :extension on PHP :version. It may not exist for this version.',
        'apt_lock' => 'Another package operation is already running. Try again in a moment.',
        'network' => 'The package repository could not be reached. Check the server has network access.',
        'no_space' => 'The server has run out of disk space.',
        'worker' => 'Installing :extension stopped unexpectedly. It may have timed out — try again.',
        'unknown' => 'Installing :extension failed. Quote the reference below to support.',
        'enable_failed' => ':extension was installed but could not be switched on. Try the toggle again.',
    ],

    'fail2ban_install_failed' => [
        'package_not_found' => 'No fail2ban package is available. Check the server\'s package sources are configured and reachable.',
        'apt_lock' => 'Another package operation is already running. Try again in a moment.',
        'network' => 'The package repository could not be reached. Check the server has network access.',
        'no_space' => 'The server has run out of disk space.',
        'worker' => 'The install stopped unexpectedly. It may have timed out — try again.',
        'unknown' => 'Installing fail2ban failed. Quote the reference below to support.',
    ],


    /*
    | Per-runtime overrides, consulted before the shared groups above.
    |
    | `install_failed` is shared by PHP, Node, database engines and
    | fail2ban. It used to be worded for PHP alone, so a failed MongoDB
    | install told the user to check the PHP repository. Only the reasons
    | that genuinely differ per runtime belong here; everything else
    | still falls through.
    */

    'php_install_failed' => [
        'package_not_found' => 'No package for :version. Check the PHP repository is configured and reachable.',
    ],

    'node_install_failed' => [
        'package_not_found' => 'Node :version could not be found. Check the version number, or pick one from the list.',
    ],

    'database_install_failed' => [
        'package_not_found' => 'No package for :version is available on this server. Check that its package repository is configured and reachable.',
        // The repository was added and its index fetched successfully;
        // the engine simply has no build for this Ubuntu release.
        'os_unsupported' => ':version has not published packages for :os yet. Nothing is wrong with this server — the panel supports :os, but :version has not released a build for it. Use another database engine, or try again once it does.',
    ],

    // Used for :os when /etc/os-release cannot be read.
    'this_server' => 'this server\'s operating system',
];
