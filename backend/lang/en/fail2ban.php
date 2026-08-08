<?php

return [
    'install_started' => 'Installing fail2ban. This takes a moment.',

    'bantime' => [
        '10m' => '10 minutes',
        '1h' => '1 hour',
        '1d' => '1 day',
        '1w' => '1 week',
        'permanent' => 'Permanent',
    ],

    // Per-application fail2ban (raw INI)
    'created_successfully' => 'Fail2ban configured successfully!',
    'test_failed' => 'Fail2ban configuration test failed.',
    'already_disabled' => 'Fail2ban is already disabled for this application.',
    'disabled_successfully' => 'Fail2ban disabled successfully!',

    'validation' => [
        'jail_content_required' => 'The jail configuration is required.',
        'jail_content_string' => 'The jail configuration must be a text string.',
        'jail_content_max' => 'The jail configuration is too large (max 65535 characters).',
        'filter_content_required' => 'The filter configuration is required.',
        'filter_content_string' => 'The filter configuration must be a text string.',
        'filter_content_max' => 'The filter configuration is too large (max 65535 characters).',
    ],
];