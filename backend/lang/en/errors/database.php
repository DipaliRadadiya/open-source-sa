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
    'phpmyadmin_not_deployed' => 'No phpMyAdmin site is installed on this server.',
    'phpmyadmin_no_users' => 'Create a database user before accessing phpMyAdmin.',
    'remote_users_unsupported' => 'Remote access is not available for :engine — its accounts are not tied to a host. Use localhost.',
    // 409, not 422: the request is fine, the cluster is not ready. The
    // client re-sends with restart_cluster as explicit consent.
    'remote_access_restart_required' => 'Allowing remote connections needs PostgreSQL restarted, because the address it listens on can only be changed at start-up. Applications using this database will lose their connections for a moment. Send the request again with restart_cluster to go ahead.',
    'phpmyadmin_not_selectable' => 'The selected site is not an active phpMyAdmin installation.',
    'phpmyadmin_user_not_found' => 'The specified database user does not belong to this database.',
    'phpmyadmin_not_isolated' => 'This phpMyAdmin site shares the server-wide PHP pool, so a sign-in link would be readable by every other site. Give it its own PHP pool, or open phpMyAdmin and sign in with the database credentials.',
    'phpmyadmin_sso_unavailable' => 'The sign-in link could not be prepared on the phpMyAdmin site.',

];
