<?php

return [

    'waf' => [
        'modes' => [
            'detect' => 'बस देखें, ब्लॉक न करें',
            'enforce' => 'वाकई ब्लॉक करें',
        ],
        'categories' => [
            'query_string' => 'खराब खोज शब्द',
            'request_uri' => 'खराब वेब पते',
            'user_agent' => 'खराब विज़िटर',
            'referrer' => 'खराब लिंक',
            'cookie' => 'खराब कुकीज़',
            'method' => 'खराब अनुरोध प्रकार',
        ],
    ],

];
