<?php

return [
    'types' => [
        'wordpress' => ['title' => 'WordPress', 'tagline' => 'ब्लॉग और वेबसाइट बिल्डर'],
        'phpmyadmin' => ['title' => 'phpMyAdmin', 'tagline' => 'ब्राउज़र में अपने डेटाबेस प्रबंधित करें'],
        'nextcloud' => ['title' => 'Nextcloud', 'tagline' => 'निजी फ़ाइल सिंक और साझाकरण'],
        'joomla' => ['title' => 'Joomla', 'tagline' => 'लचीली सामग्री प्रबंधन प्रणाली'],
        'moodle' => ['title' => 'Moodle', 'tagline' => 'ऑनलाइन पाठ्यक्रम और शिक्षण'],
        'mautic' => ['title' => 'Mautic', 'tagline' => 'मार्केटिंग स्वचालन और अभियान'],
        'craftcms' => ['title' => 'Craft CMS', 'tagline' => 'डेवलपर्स के लिए सामग्री प्रबंधन'],
        'akaunting' => ['title' => 'Akaunting', 'tagline' => 'लेखांकन और चालान'],
        'statamic' => ['title' => 'Statamic', 'tagline' => 'फ़ाइल-आधारित CMS — डेटाबेस की ज़रूरत नहीं'],
        'prestashop' => ['title' => 'PrestaShop', 'tagline' => 'ऑनलाइन स्टोर और ई-कॉमर्स'],
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
        'web_server' => 'यह एप्लिकेशन अभी :web_server सर्वर पर उपलब्ध नहीं है।',
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

    'steps' => [
        'create_database' => 'डेटाबेस बनाया जा रहा है',
        'download' => 'एप्लिकेशन डाउनलोड हो रहा है',
        'extract' => 'फ़ाइलें निकाली जा रही हैं',
        'configure' => 'कॉन्फ़िगरेशन लिखी जा रही है',
        'install_cli' => 'सेटअप टूल इंस्टॉल हो रहा है',
        'install_app' => 'इंस्टॉलर चल रहा है',
        'clone' => 'रिपॉज़िटरी क्लोन की जा रही है',
        'fetch' => 'नवीनतम कोड लाया जा रहा है',
        'checkout' => 'ब्रांच पर स्विच किया जा रहा है',
        'build' => 'बिल्ड कमांड चलाई जा रही है',
        'write_credential' => 'git एक्सेस तैयार किया जा रहा है',
        'create_directory' => 'डायरेक्टरी बनाई जा रही है',
        'set_ownership' => 'स्वामित्व सेट किया जा रहा है',
        'placeholder' => 'प्लेसहोल्डर पेज जोड़ा जा रहा है',
        'write_config' => 'साइट कॉन्फ़िग लिखी जा रही है',
        'test_config' => 'कॉन्फ़िग जाँची जा रही है',
        'reload' => 'वेब सर्वर पुनः लोड किया जा रहा है',
        'worker' => 'बैकग्राउंड प्रोसेस रुक गई',
    ],
];
