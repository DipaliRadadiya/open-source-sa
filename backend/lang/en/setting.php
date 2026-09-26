<?php

/*
 * Settings feature strings.
 */

return [
    'reboot_schedule' => [
        'day_of_month' => 'day of the month',
        'frequency' => [
            'daily' => 'Daily',
            'weekly' => 'Weekly',
            'monthly' => 'Monthly',
        ],
        'day' => [
            0 => 'Sunday',
            1 => 'Monday',
            2 => 'Tuesday',
            3 => 'Wednesday',
            4 => 'Thursday',
            5 => 'Friday',
            6 => 'Saturday',
        ],
    ],
    'redis' => [
        'password_applying' => 'The Redis password is being applied. Reload in a moment to confirm.',
        'policy_evicts_panel_queue' => 'The panel keeps its queued jobs in this Redis. An allkeys policy can evict a pending job silently once Redis is full, so it is not allowed here. Use noeviction or a volatile policy.',
    ],
];
