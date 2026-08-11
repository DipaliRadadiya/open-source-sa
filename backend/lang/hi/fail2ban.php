<?php

return [
    'install_started' => 'fail2ban स्थापित किया जा रहा है। इसमें कुछ समय लगेगा।',

    'bantime' => [
        '10m' => '10 मिनट',
        '1h' => '1 घंटा',
        '1d' => '1 दिन',
        '1w' => '1 सप्ताह',
        'permanent' => 'स्थायी',
    ],

    'created_successfully' => 'Fail2ban सफलतापूर्वक कॉन्फ़िगर हो गया!',
    'test_failed' => 'Fail2ban कॉन्फ़िगरेशन परीक्षण विफल रहा।',
    'already_disabled' => 'इस एप्लिकेशन के लिए Fail2ban पहले से ही अक्षम है।',
    'disabled_successfully' => 'Fail2ban सफलतापूर्वक अक्षम कर दिया गया!',

    'validation' => [
        'jail_content_required' => 'jail कॉन्फ़िगरेशन आवश्यक है।',
        'jail_content_string' => 'jail कॉन्फ़िगरेशन टेक्स्ट होना चाहिए।',
        'jail_content_max' => 'jail कॉन्फ़िगरेशन बहुत बड़ा है (अधिकतम 65535 अक्षर)।',
        'filter_content_required' => 'फ़िल्टर कॉन्फ़िगरेशन आवश्यक है।',
        'filter_content_string' => 'फ़िल्टर कॉन्फ़िगरेशन टेक्स्ट होना चाहिए।',
        'filter_content_max' => 'फ़िल्टर कॉन्फ़िगरेशन बहुत बड़ा है (अधिकतम 65535 अक्षर)।',
    ],
];
