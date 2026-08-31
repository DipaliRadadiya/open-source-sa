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
        'gitlab' => '"read_repository" और "read_api" स्कोप वाला व्यक्तिगत एक्सेस टोकन।',
        'bitbucket' => 'स्कोप्ड एक्सेस टोकन (वर्कस्पेस, प्रोजेक्ट या रिपॉज़िटरी स्तर)। रिपॉज़िटरी तक सीमित टोकन केवल वही रिपॉज़िटरी दिखाएगा।',
    ],

    /*
    | Re-pointing an existing application at a different account.
    */
    'relink' => [
        'repository_unreachable' => 'वह खाता इस रिपॉज़िटरी तक नहीं पहुँच सकता। जाँचें कि टोकन अब भी मान्य है और उसे पहुँच प्राप्त है।',
        'branch_missing' => 'ब्रांच :branch इस रिपॉज़िटरी में मौजूद नहीं है।',
    ],

    /*
    | Help for one field of one provider. Keyed per provider because `host`
    | means a GitLab URL and nothing else; a shared key would end up
    | describing two different fields at once.
    */
    'field_help' => [
        'gitlab' => ['host' => 'केवल सेल्फ-होस्टेड GitLab के लिए — आपके इंस्टेंस का बेस URL, उदाहरण के लिए https://git.example.com। gitlab.com के लिए खाली छोड़ें।'],
        'bitbucket' => ['workspace' => 'आपके Bitbucket URL से वर्कस्पेस ID: bitbucket.org/<workspace>/<repository>। यह आपका प्रदर्शित नाम नहीं है।'],
    ],
];
