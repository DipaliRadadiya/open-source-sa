<?php

return [
    'status' => [
        'pending' => 'Waiting to start',
        'running' => 'Updating',
        'completed' => 'Updated',
        'failed' => 'Failed',
    ],
    'reasons' => [
        'unsupported' => 'This application type cannot be updated from the panel.',
        'version_unchanged' => 'The update ran, but the application still reports its old version.',
        'step_failed' => 'A step of the update failed. The reference points to the server log.',
        'failed' => 'The update failed unexpectedly. The reference points to the server log.',
        'worker' => 'The update stopped before it finished (the background worker ended or timed out).',
        'abandoned' => 'The update never started and was cancelled.',
        'release_not_found' => 'The new release could not be found on the project\'s download page.',
        'download_unreachable' => 'The release could not be downloaded. Check the server\'s internet access and try again.',
        'out_of_memory' => 'The server ran out of memory during the update.',
        'composer_platform' => 'The site\'s PHP version or extensions do not meet the new release\'s requirements.',
        'no_build_tools' => 'Build tools are missing on the server. Install them and try again.',
    ],
    'errors' => [
        'running' => 'An update of this application is already running.',
        'unsupported' => 'This application type cannot be updated from the panel.',
        'not_active' => 'Only an active, enabled application can be updated.',
        'version_unknown' => 'The installed version could not be read from the application\'s files.',
        'lookup_failed' => 'The available versions could not be checked right now. Try again later.',
        'up_to_date' => 'Version :version is already the newest release in this major version.',
    ],
];
