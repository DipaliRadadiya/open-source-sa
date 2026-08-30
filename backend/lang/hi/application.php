<?php

return [
    // What a name attached to an application does. Shown as the badge
    // beside each domain, so it has to read as a noun, not a sentence.
    'domain_type' => [
        'primary' => 'प्राथमिक',
        'alias' => 'उपनाम',
        'redirect' => 'पुनर्निर्देशन',
    ],

    'types' => [
        'wordpress' => ['title' => 'WordPress', 'tagline' => 'ब्लॉग और वेबसाइट बिल्डर'],
        'phpmyadmin' => ['title' => 'phpMyAdmin', 'tagline' => 'ब्राउज़र में अपने डेटाबेस प्रबंधित करें'],
        'uptimekuma' => ['title' => 'Uptime Kuma', 'tagline' => 'अपटाइम निगरानी और स्टेटस पेज'],
        'n8n' => ['title' => 'n8n', 'tagline' => 'वर्कफ़्लो स्वचालन (fair-code लाइसेंस)'],
        'nodered' => ['title' => 'Node-RED', 'tagline' => 'डिवाइस, API और सेवाओं को जोड़ें'],
        'nodebb' => ['title' => 'NodeBB', 'tagline' => 'फ़ोरम सॉफ़्टवेयर — MongoDB चाहिए'],
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
        'database' => 'इस एप्लिकेशन को :engines चाहिए, जो इस सर्वर पर नहीं है।',
        'php' => 'इस सर्वर पर PHP इंस्टॉल नहीं है।',
        'node' => 'इस सर्वर पर Node.js इंस्टॉल नहीं है।',
        'web_server' => 'यह एप्लिकेशन अभी :web_server सर्वर पर उपलब्ध नहीं है।',
    ],

    'git_source' => [
        'account' => 'जुड़े हुए खाते से',
        'public_url' => 'सार्वजनिक रिपॉज़िटरी URL चिपकाएँ',
    ],

    'fields' => [
        'company_name' => 'कंपनी का नाम',
        'company_email' => 'कंपनी का ईमेल',
        'locale' => 'लोकेल',
        'site_name' => 'साइट का नाम',
        'language' => 'भाषा',
        'admin_name' => 'व्यवस्थापक का नाम',
        'admin_first_name' => 'व्यवस्थापक का पहला नाम',
        'admin_last_name' => 'व्यवस्थापक का अंतिम नाम',
        'short_name' => 'संक्षिप्त नाम',
        'shop_name' => 'दुकान का नाम',
        'country' => 'देश',
        'timezone' => 'समय क्षेत्र',
        'rendering_type' => 'रेंडरिंग प्रकार',
        'name' => 'नाम',
        'domain' => 'डोमेन',
        'system_user_id' => 'सिस्टम उपयोगकर्ता',
        'php_version' => 'PHP संस्करण',
        'node_version' => 'Node.js संस्करण',
        'app_port' => 'ऐप पोर्ट',
        'web_root' => 'वेब रूट',
        'build_command' => 'बिल्ड कमांड',
        'deploy_script' => 'डिप्लॉय स्क्रिप्ट',
        'start_command' => 'स्टार्ट कमांड',
        'package_manager' => 'पैकेज मैनेजर',
        'git_source' => 'स्रोत',
        'git_account_id' => 'Git खाता',
        'repository' => 'रिपॉज़िटरी',
        'repository_url' => 'रिपॉज़िटरी URL',
        'branch' => 'ब्रांच',
        'site_title' => 'साइट शीर्षक',
        'admin_user' => 'एडमिन उपयोगकर्ता नाम',
        'admin_username' => 'एडमिन उपयोगकर्ता नाम',
        'admin_email' => 'एडमिन ईमेल',
        'admin_password' => 'एडमिन पासवर्ड',
        'site_language' => 'साइट भाषा',
        'table_prefix' => 'टेबल प्रीफ़िक्स',
        'mailer_name' => 'प्रेषक का नाम',
        'mailer_email' => 'प्रेषक का पता',
        'mailer_host' => 'SMTP होस्ट',
        'mailer_port' => 'SMTP पोर्ट',
        'mailer_username' => 'SMTP उपयोगकर्ता नाम',
        'mailer_password' => 'SMTP पासवर्ड',
    ],

    'help' => [
        'start_command' => 'एंट्री फ़ाइल, जैसे \"node server.js\"। \"npm start\" नहीं — पैकेज मैनेजर असली प्रक्रिया को फ़ोर्क करता है, इसलिए शटडाउन सिग्नल उस तक नहीं पहुँचते।',
        'app_port' => 'खाली छोड़ने पर पैनल एक खाली पोर्ट चुन लेता है।',
        'rendering_type' => 'सर्वर-साइड रेंडरिंग आपका ऐप चलाता है और उसे प्रॉक्सी करता है। अन्य दो फ़ाइलों में बिल्ड होते हैं जिन्हें वेब सर्वर सीधे देता है — तेज़, और चलाने के लिए कुछ नहीं।',
        'repository_url' => 'सार्वजनिक रिपॉज़िटरी — खाते की ज़रूरत नहीं। पता https:// होना चाहिए।',
        'build_command' => 'कोड लाने के बाद चलता है, जैसे composer install --no-dev',
        'deploy_script' => 'कोड लाने के बाद, आपके साइट उपयोगकर्ता के रूप में चलती है। बिल्ड कमांड उपयोग करने के लिए खाली छोड़ें।',
        'package_manager' => 'आपकी निर्भरताएँ इंस्टॉल और बिल्ड करने वाला टूल। नीचे बिल्ड कमांड अपने आप भर देता है — बाद में इसे स्वतंत्र रूप से बदल सकते हैं।',
    ],

    'steps' => [
        'create_database' => 'डेटाबेस बनाया जा रहा है',
        'download' => 'एप्लिकेशन डाउनलोड हो रहा है',
        'extract' => 'फ़ाइलें निकाली जा रही हैं',
        'configure' => 'कॉन्फ़िगरेशन लिखी जा रही है',
        'install_cli' => 'सेटअप टूल इंस्टॉल हो रहा है',
        'install_app' => 'इंस्टॉलर चल रहा है',
        'init' => 'रिपॉज़िटरी सेट अप की जा रही है',
        'fetch' => 'नवीनतम कोड लाया जा रहा है',
        'checkout' => 'ब्रांच पर स्विच किया जा रहा है',
        'seed_env' => 'एनवायरनमेंट फ़ाइल तैयार की जा रही है',
        'build' => 'बिल्ड कमांड चलाई जा रही है',
        'write_credential' => 'git एक्सेस तैयार किया जा रहा है',
        'create_directory' => 'डायरेक्टरी बनाई जा रही है',
        'set_ownership' => 'स्वामित्व सेट किया जा रहा है',
        'placeholder' => 'प्लेसहोल्डर पेज जोड़ा जा रहा है',
        'write_config' => 'साइट कॉन्फ़िग लिखी जा रही है',
        'test_config' => 'कॉन्फ़िग जाँची जा रही है',
        'reload' => 'वेब सर्वर पुनः लोड किया जा रहा है',
        'start_app' => 'एप्लिकेशन शुरू की जा रही है',
        'write_unit' => 'सेवा तैयार की जा रही है',
        'restart_app' => 'एप्लिकेशन पुनः शुरू की जा रही है',
        'harden' => 'सुरक्षा सेटिंग्स लागू की जा रही हैं',
        'trust_domain' => 'डोमेन को अनुमति दी जा रही है',
        'set_password' => 'एडमिन पासवर्ड सेट किया जा रहा है',
        'verify_serving' => 'साइट के उत्तर की जाँच',
        'worker' => 'बैकग्राउंड प्रोसेस रुक गई',
    ],
    /*
    | Why provisioning failed, keyed by the `failed_reason` code on the
    | application. Only set where the exit status genuinely identifies
    | the cause; most failures carry the step and reference instead.
    */
    'failure_reason' => [
        'serving_error' => 'एप्लिकेशन शुरू हो गया लेकिन हर अनुरोध का उत्तर त्रुटि से देता है। संभवतः इसकी एसेट्स पूरी तरह बिल्ड नहीं हुईं — विवरण के लिए एप्लिकेशन लॉग देखें।',
        'not_answering' => 'एप्लिकेशन शुरू हो गया लेकिन किसी अनुरोध का उत्तर नहीं दिया। यह क्यों सुन नहीं रहा, यह जानने के लिए एप्लिकेशन लॉग देखें।',
        'out_of_memory' => 'इस चरण के दौरान सर्वर की मेमोरी समाप्त हो गई और सिस्टम ने इसे रोक दिया। कुछ मेमोरी खाली करें, या स्वैप जोड़ें, और पुनः प्रयास करें।',
    ],

    'port_free' => 'पोर्ट :port खाली है।',

    'rendering' => [
        'php' => 'PHP एप्लिकेशन (Laravel, Symfony, सादा PHP)',
        'ssr' => 'सर्वर-साइड रेंडरिंग (एक प्रक्रिया चलाता है)',
        'csr' => 'क्लाइंट-साइड रेंडरिंग (फ़ाइलों में बिल्ड)',
        'static' => 'स्टेटिक साइट (फ़ाइलों में बिल्ड)',
    ],

    'package_manager' => [
        'npm' => 'npm',
        'yarn' => 'Yarn',
        'pnpm' => 'pnpm',
        'bun' => 'Bun',
    ],

];
