<?php

return [
    'operation_failed' => 'The database operation failed on the server.',
    'export_already_running' => 'An export of this database is already running. Wait for it to finish before starting another.',
    'collation_mismatch' => 'The selected collation does not belong to the chosen character set.',
    'application_already_attached' => ':application already has the database :database attached. Detach that one first, or attach this database to another application.',
    'engine_not_accepted' => ':application cannot use a :engine database. It accepts :accepted.',
    'engine_not_installable' => 'The panel cannot install this database engine yet. Install it yourself and the panel will detect it.',
    // The vendor publishes nothing for this Ubuntu release. Refused
    // before the install rather than discovered two minutes into apt.
    'engine_os_unsupported' => ':engine has not published packages for :os yet, so the panel cannot install it here. Nothing is wrong with this server — the panel supports :os, but :engine has not released a build for it. Use another database engine, or try again once it does.',
    'phpmyadmin_engine_not_supported' => 'phpMyAdmin does not support :engine databases.',
    'phpmyadmin_not_deployed' => 'No phpMyAdmin application is installed on this server.',
    'phpmyadmin_no_users' => 'Create a database user before accessing phpMyAdmin.',
    'remote_users_unsupported' => 'Remote access is not available for :engine — its accounts are not tied to a host. Use localhost.',
    // 409, not 422: the request is fine, the cluster is not ready. The
    // client re-sends with restart_cluster as explicit consent.
    'remote_access_restart_required' => 'Allowing remote connections needs :engine restarted, because the address it listens on can only be changed at start-up. Applications using this database lose their connections for a moment. Confirm to restart it and continue.',
    'phpmyadmin_not_selectable' => 'The selected application is not an active phpMyAdmin installation.',
    'phpmyadmin_user_not_found' => 'The specified database user does not belong to this database.',
    'phpmyadmin_not_isolated' => 'This phpMyAdmin application shares the server-wide PHP pool, so a sign-in link would be readable by every other application. Give it its own PHP pool, or open phpMyAdmin and sign in with the database credentials.',
    'phpmyadmin_requires_https' => 'This phpMyAdmin application has no HTTPS, so the sign-in link and the database session would travel unencrypted. Issue an SSL certificate for it first.',
    'user_exists' => 'A database user named ":username" already exists. Choose another name.',
    'phpmyadmin_sso_unavailable' => 'The sign-in link could not be prepared on the phpMyAdmin application.',
    'remote_host_invalid' => 'Enter an IPv4 address or range, such as 203.0.113.5 or 203.0.113.0/24.',
    'remote_host_not_remote' => 'That address is not a remote one. Use “Local” for this server or “Anywhere” for every address.',
    'engine_unreachable' => ':engine is not answering, so the panel cannot read this database. Start :engine on the Services page and try again.',
    'panel_user_protected' => '“:username” is the account the panel itself uses to manage databases. It cannot be changed or removed here.',
    'export_in_progress' => 'This export is still running. Wait for it to finish, then delete it.',
    'engine_install_in_progress' => ':engine is already being installed. Wait for that install to finish.',
    'panel_process_protected' => 'This is the panel’s own connection. Stopping it would fail whatever the panel is doing.',
];
