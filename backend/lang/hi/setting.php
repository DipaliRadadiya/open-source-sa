<?php

/*
 * Settings feature strings.
 */

return [
    'reboot_schedule' => [
        'day_of_month' => 'महीने का दिन',
        'frequency' => [
            'daily' => 'प्रतिदिन',
            'weekly' => 'साप्ताहिक',
            'monthly' => 'मासिक',
        ],
        'day' => [
            0 => 'रविवार',
            1 => 'सोमवार',
            2 => 'मंगलवार',
            3 => 'बुधवार',
            4 => 'गुरुवार',
            5 => 'शुक्रवार',
            6 => 'शनिवार',
        ],
    ],
    'redis' => [
        'password_applying' => 'Redis पासवर्ड लागू किया जा रहा है। पुष्टि के लिए थोड़ी देर बाद पुनः लोड करें।',
        'policy_evicts_panel_queue' => 'पैनल अपनी कतार के कार्य इसी Redis में रखता है। allkeys नीति Redis भर जाने पर किसी लंबित कार्य को चुपचाप हटा सकती है, इसलिए यहाँ इसकी अनुमति नहीं है। noeviction या कोई volatile नीति चुनें।',
    ],
];
