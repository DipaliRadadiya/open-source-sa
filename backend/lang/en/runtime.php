<?php

return [

    /*
    | Why an install failed, keyed by the `reason` code stored on the
    | install row. Built at read time in the *viewer's* locale — the
    | raw apt or fnm output is never shown, only referenced.
    */

    'install_failed' => [
        'package_not_found' => 'No package for :version. Check the PHP repository is configured and reachable.',
        'apt_lock' => 'Another package operation is already running. Try again in a moment.',
        'network' => 'The package repository could not be reached. Check the server has network access.',
        'no_space' => 'The server has run out of disk space.',
        'worker' => 'The install stopped unexpectedly. It may have timed out — try again.',
        'unknown' => 'The install failed. Quote the reference below to support.',
        'dpkg_broken' => 'This server needs its package database repaired before anything else can install.',
        'port_in_use_by_mysql' => 'MySQL is already installed and owns this port. Remove it first, or keep using it.',
        'port_in_use_by_mariadb' => 'MariaDB is already installed and owns this port. Remove it first, or keep using it.',
        'root_unreachable' => 'It is installed but the panel could not sign in to it. Its administrator login has been changed from the default, so the panel needs those details to continue.',
        'grant_failed' => 'It is installed but the panel could not create its own account on it.',
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

];
