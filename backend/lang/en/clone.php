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
    ],
];
