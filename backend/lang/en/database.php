<?php

return [

    'install_steps' => [
        'queued' => 'Queued',
        'checking_conflicts' => 'Checking for conflicting database engines',
        'preparing_repository' => 'Preparing the package repository',
        'updating_package_index' => 'Updating the package index',
        'preparing' => 'Preparing packages',
        'downloading' => 'Downloading packages',
        'unpacking' => 'Unpacking packages',
        'configuring' => 'Configuring packages',
        'starting_service' => 'Starting the database service',
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

];
