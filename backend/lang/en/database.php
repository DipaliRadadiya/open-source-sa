<?php

return [

    'install_steps' => [
        'queued' => 'Queued',
        'checking_conflicts' => 'Checking for conflicting database engines',
        'preparing_repository' => 'Preparing the package repository',
        'waiting_for_package_manager' => 'Waiting for another package operation to finish',
        'updating_package_index' => 'Updating the package index',
        'preparing' => 'Preparing packages',
        'downloading' => 'Downloading packages',
        'unpacking' => 'Unpacking packages',
        'configuring' => 'Configuring packages',
        'starting_service' => 'Starting the database service',
        'verifying_cluster' => 'Verifying the database cluster',
        'verifying_connection' => 'Verifying the database connection',
        'creating_panel_account' => 'Creating the panel database account',
    ],

    /*
    | Why an export failed. Stored as a stable code on the row and worded here
    | at read time, so the sentence lands in the *viewer's* locale rather than
    | the locale of whoever pressed the button.
    */

    'export_failed' => [
        'dump_failed' => 'The database dump failed. Quote the reference below to support.',
        'database_missing' => 'The database was deleted before the export could run.',
        'worker' => 'The export stopped unexpectedly. It may have timed out — try again.',
        'unknown' => 'The export failed. Quote the reference below to support.',
    ],

    'import_failed' => [
        'unknown' => 'The import failed. Quote the reference below to support.',
        'database_missing' => 'The database was deleted before the import could run.',
        'no_account' => 'This database has no user the import can run as: one whose password the panel knows and who connects from this server.',
        'wrong_format' => 'This file is not a dump this database can import. MySQL and MariaDB take .sql or .sql.gz; PostgreSQL takes .sql, .sql.gz or a pg_dump custom archive; MongoDB takes a mongodump --archive file.',
        'client_command' => 'Line :line of the file contains a psql command (a backslash command such as \\!). Those can run programs on the server, so the file was refused. Export the database again as plain SQL with pg_dump.',
        'truncated' => 'The file ends in the middle of its data, so it is incomplete. Export it again.',
        'unreadable' => 'The file could not be read as a PostgreSQL archive.',
        'backup_failed' => 'The backup taken before the import failed, so nothing was imported.',
        'load_failed' => 'The file could not be loaded into the database. Quote the reference below to support.',
        'worker' => 'The import stopped unexpectedly. It may have timed out.',
        'abandoned' => 'The upload was not finished within a day and has been removed.',
        'restored' => 'The database was put back from the automatic backup, so nothing was lost.',
        'untouched' => 'The database was not changed.',
        'not_restored' => 'The automatic backup could not be put back by itself. It is in the Exports list: download it and import it to restore the database.',
    ],

];
