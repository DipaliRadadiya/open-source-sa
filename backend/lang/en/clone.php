<?php

return [
    'status' => [
        'pending' => 'Queued',
        'running' => 'Cloning',
        'completed' => 'Completed',
        'failed' => 'Failed',
    ],

    'current_step' => [
        'provisioning' => 'Creating the site',
        'copying_files' => 'Copying files',
        'cloning_database' => 'Cloning the database',
        'starting_process' => 'Starting the application',
    ],

    'cloning_errors' => [
        'crashed' => 'The clone stopped unexpectedly.',
        'failed' => 'The clone failed. Quote the reference to support.',
        'abandoned' => 'This clone never started and was released. Start it again.',
        'copy_failed' => 'Copying the application failed on the server. Quote the reference to support.',
        'setup_failed' => 'The copy could not be set up on the server. Quote the reference to support.',
    ],

    'errors' => [
        'already_running' => 'This application is already being cloned. Wait for that clone to finish, then start another.',
    ],
];
