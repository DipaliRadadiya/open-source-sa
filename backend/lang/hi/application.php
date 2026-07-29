<?php

return [
    'types' => [
        'wordpress' => ['title' => 'WordPress', 'tagline' => 'ब्लॉग और वेबसाइट बिल्डर'],
        'git' => ['title' => 'Git रिपॉज़िटरी से', 'tagline' => 'GitHub, GitLab या Bitbucket से अपना कोड तैनात करें'],
        'php' => ['title' => 'खाली PHP साइट', 'tagline' => 'खाली साइट — अपनी फ़ाइलें स्वयं अपलोड करें'],
        'static' => ['title' => 'स्टेटिक साइट', 'tagline' => 'केवल HTML, CSS और JavaScript'],
    ],

    'status' => [
        'pending' => 'अभी तैनात नहीं',
        'provisioning' => 'सेटअप हो रहा है…',
        'active' => 'चालू',
        'failed' => 'सेटअप विफल',
    ],

    'unavailable' => [
        'php' => 'इस सर्वर पर PHP इंस्टॉल नहीं है।',
        'node' => 'इस सर्वर पर Node.js इंस्टॉल नहीं है।',
    ],

    'git_source' => [
        'account' => 'जुड़े हुए खाते से',
        'public_url' => 'सार्वजनिक रिपॉज़िटरी URL चिपकाएँ',
    ],

    'fields' => [
        'name' => 'नाम',
        'domain' => 'डोमेन',
        'system_user_id' => 'सिस्टम उपयोगकर्ता',
        'php_version' => 'PHP संस्करण',
        'node_version' => 'Node.js संस्करण',
        'app_port' => 'ऐप पोर्ट',
        'web_root' => 'वेब रूट',
        'build_command' => 'बिल्ड कमांड',
        'start_command' => 'स्टार्ट कमांड',
        'git_source' => 'स्रोत',
        'git_account_id' => 'Git खाता',
        'repository' => 'रिपॉज़िटरी',
        'repository_url' => 'रिपॉज़िटरी URL',
        'branch' => 'ब्रांच',
        'site_title' => 'साइट शीर्षक',
        'admin_user' => 'एडमिन उपयोगकर्ता नाम',
        'admin_email' => 'एडमिन ईमेल',
        'admin_password' => 'एडमिन पासवर्ड',
        'site_language' => 'साइट भाषा',
        'table_prefix' => 'टेबल प्रीफ़िक्स',
    ],

    'help' => [
        'repository_url' => 'सार्वजनिक रिपॉज़िटरी — खाते की ज़रूरत नहीं। पता https:// होना चाहिए।',
        'build_command' => 'कोड लाने के बाद चलता है, जैसे composer install --no-dev',
    ],
];
