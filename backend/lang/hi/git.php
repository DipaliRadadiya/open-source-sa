<?php

return [
    'providers' => [
        'github' => 'GitHub',
        'gitlab' => 'GitLab',
        'bitbucket' => 'Bitbucket',
    ],

    'status' => [
        'valid' => 'कनेक्टेड',
        'invalid' => 'टोकन अमान्य',
        'unknown' => 'जाँच नहीं हो सकी',
    ],

    'fields' => [
        'token' => 'एक्सेस टोकन',
        'host' => 'सेल्फ-होस्टेड URL',
        'workspace' => 'वर्कस्पेस',
    ],

    'token_help' => [
        'github' => '"repo" स्कोप वाला व्यक्तिगत एक्सेस टोकन।',
        'gitlab' => '"read_repository" और "read_api" स्कोप वाला व्यक्तिगत एक्सेस टोकन। gitlab.com के लिए URL खाली छोड़ें।',
        'bitbucket' => 'स्कोप्ड एक्सेस टोकन (वर्कस्पेस, प्रोजेक्ट या रिपॉज़िटरी स्तर)। रिपॉज़िटरी तक सीमित टोकन केवल वही रिपॉज़िटरी दिखाएगा।',
    ],
];
