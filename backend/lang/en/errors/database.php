<?php

return [
    'operation_failed' => 'The database operation failed on the server.',
    'collation_mismatch' => 'The selected collation does not belong to the chosen character set.',
    'engine_not_installable' => 'The panel cannot install this database engine yet. Install it yourself and the panel will detect it.',

    'engine_install' => [
        'package_not_found' => 'The package for this engine is not available from the package sources on this server.',
        'apt_lock' => 'Another package operation is already running. Wait for it to finish and try again.',
        'no_space' => 'There is not enough free disk space to install this engine.',
        'network' => 'The server could not reach its package sources. Check its network and DNS.',
        'dpkg_broken' => 'This server needs its package database repaired before anything else can install.',
        'port_in_use_by_mysql' => 'MySQL is already installed and owns this port. Remove it first, or keep using it.',
        'port_in_use_by_mariadb' => 'MariaDB is already installed and owns this port. Remove it first, or keep using it.',
        'root_unreachable' => 'The engine is installed but the panel could not sign in to it. Its administrator login has been changed from the default, so the panel needs those details to continue.',
        'grant_failed' => 'The engine is installed but the panel could not create its own account on it.',
        'unknown' => 'The install failed. Quote the reference to support.',
    ],
];
