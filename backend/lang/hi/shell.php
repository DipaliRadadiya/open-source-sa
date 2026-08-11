<?php

/*
 * Human labels for the login shells a system user can be given. The stored
 * value is the binary path `usermod -s` needs; these are what the panel shows
 * instead, because "/usr/sbin/nologin" does not tell a non-sysadmin that they
 * are turning login off.
 */

return [
    'bash' => [
        'title' => 'पूर्ण शेल एक्सेस (bash)',
        'description' => 'मानक Linux शेल। उपयोगकर्ता SSH से लॉगिन करके कमांड चला सकता है।',
    ],
    'sh' => [
        'title' => 'बुनियादी शेल (sh)',
        'description' => 'न्यूनतम शेल। लॉगिन और कमांड चलाना संभव है, पर bash जितनी सुविधाएँ नहीं।',
    ],
    'zsh' => [
        'title' => 'पूर्ण शेल एक्सेस (zsh)',
        'description' => 'bash जैसा ही, अलग सुविधाओं के साथ। उपयोगकर्ता लॉगिन करके कमांड चला सकता है।',
    ],
    'nologin' => [
        'title' => 'लॉगिन नहीं',
        'description' => 'उपयोगकर्ता फ़ाइलों का मालिक है और साइट चलाता है, पर लॉगिन नहीं कर सकता। जिन साइटों को शेल एक्सेस की ज़रूरत नहीं, उनके लिए अनुशंसित।',
    ],
    'false' => [
        'title' => 'लॉगिन नहीं (पुराना)',
        'description' => 'लॉगिन तुरंत अस्वीकार कर दिया जाता है। “लॉगिन नहीं” जैसा ही प्रभाव; पहले से उपयोग कर रहे सर्वरों के लिए रखा गया।',
    ],
];
