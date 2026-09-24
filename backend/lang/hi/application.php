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
        'docker' => ['title' => 'Docker कंटेनर', 'tagline' => 'किसी भी रजिस्ट्री से कोई भी इमेज, nginx के माध्यम से।'],
        'wordpress' => ['title' => 'WordPress', 'tagline' => 'ब्लॉग और वेबसाइट बिल्डर'],
        'phpmyadmin' => ['title' => 'phpMyAdmin', 'tagline' => 'ब्राउज़र में अपने डेटाबेस प्रबंधित करें'],
        'uptimekuma' => ['title' => 'Uptime Kuma', 'tagline' => 'अपटाइम निगरानी और स्टेटस पेज'],
        'n8n' => ['title' => 'n8n', 'tagline' => 'वर्कफ़्लो स्वचालन (fair-code लाइसेंस)'],
        'nodered' => ['title' => 'Node-RED', 'tagline' => 'डिवाइस, API और सेवाओं को जोड़ें'],
        'nodebb' => ['title' => 'NodeBB', 'tagline' => 'फ़ोरम सॉफ़्टवेयर — MongoDB या PostgreSQL चाहिए'],
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
        'stack' => 'यह सर्वर केवल कंटेनर चलाता है, इसलिए यह इस प्रकार का एप्लिकेशन होस्ट नहीं करता।',
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
        'compose' => 'Compose फ़ाइल',
        'image' => 'इमेज',
        'container_port' => 'कंटेनर पोर्ट',
        'database_engine' => 'डेटाबेस इंजन',
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

    /*
    | Example values, shown as ghost text in an empty field.
    |
    | A placeholder is NOT a default: it is never submitted. Anything with a
    | correct value the panel can pick lives in the field's `default` instead,
    | which the form pre-fills and the request carries — a table prefix is a
    | default, an email address is a placeholder. Getting that backwards ships
    | a form that looks filled in and posts null.
    |
    | Keyed by field name, not by site type, so one entry serves every type
    | declaring that field — the same arrangement as `fields` and `help`.
    | Localized because these are read by a person: an example is only an
    | example if it is in a language they read.
    */
    'placeholders' => [
        'mailer_host' => 'smtp.example.com',
        'mailer_port' => '587',
        'site_title' => 'मेरी साइट',
        'site_name' => 'मेरी साइट',
        'shop_name' => 'मेरी दुकान',
        'company_name' => 'मेरी कंपनी',
        'short_name' => 'mysite',
        'mailer_name' => 'मेरी साइट',
        'admin_email' => 'you@example.com',
        'company_email' => 'you@example.com',
        'mailer_email' => 'no-reply@example.com',
        'mailer_username' => 'no-reply@example.com',
        'timezone' => 'Asia/Kolkata',
        'repository_url' => 'https://github.com/you/repo.git',
        'build_command' => 'npm ci && npm run build',
        'start_command' => 'node server.js',
    ],

    'help' => [
        'compose' => 'वैकल्पिक। अपनी compose फ़ाइल चिपकाएँ — Compose जो कुछ समर्थित करता है वह सब उपलब्ध है: कई सेवाएँ, नामित वॉल्यूम, healthchecks। खाली छोड़ें तो पैनल ऊपर के फ़ील्ड से एक फ़ाइल लिख देगा। पोर्ट 127.0.0.1 पर प्रकाशित होने चाहिए और बाइंड माउंट इस एप्लिकेशन की डायरेक्टरी के भीतर रहने चाहिए; बाकी सब कारण सहित अस्वीकार कर दिया जाता है।',
        'image' => 'चलाने के लिए इमेज, स्पष्ट टैग के साथ — `nginx:1.27-alpine`. बिना टैग का नाम `latest` खींचता है, जिससे परिनियोजन पुनरुत्पादनीय नहीं रहता और रोलबैक निरर्थक हो जाता है।',
        'container_port' => 'वह पोर्ट जिस पर आपका एप्लिकेशन कंटेनर के भीतर सुनता है। सर्वर पर पोर्ट पैनल स्वयं आवंटित करता है और nginx को उस पर निर्देशित करता है।',
        'table_prefix_random' => 'खाली छोड़ें तो एक यादृच्छिक उपसर्ग बन जाएगा, जिससे डेटाबेस साझा होने पर भी टेबल अलग रहती हैं।',
        'timezone' => 'साइट का टाइम ज़ोन, जैसे America/New_York या Asia/Kolkata. सेटिंग्स → सामान्य → टाइम ज़ोन देखें।',
        'table_prefix_optional' => 'वैकल्पिक। इसे खाली कर दें तो टेबल बिना किसी उपसर्ग के बनेंगी।',
        'start_command' => 'एंट्री फ़ाइल, जैसे \"node server.js\"। \"npm start\" नहीं — पैकेज मैनेजर असली प्रक्रिया को फ़ोर्क करता है, इसलिए शटडाउन सिग्नल उस तक नहीं पहुँचते।',
        'app_port' => 'खाली छोड़ने पर पैनल एक खाली पोर्ट चुन लेता है।',
        'rendering_type' => 'सर्वर-साइड रेंडरिंग आपका ऐप चलाता है और उसे प्रॉक्सी करता है। अन्य दो फ़ाइलों में बिल्ड होते हैं जिन्हें वेब सर्वर सीधे देता है — तेज़, और चलाने के लिए कुछ नहीं।',
        'repository_url' => 'सार्वजनिक रिपॉज़िटरी — खाते की ज़रूरत नहीं। पता https:// होना चाहिए।',
        'build_command' => 'कोड लाने के बाद चलता है, जैसे composer install --no-dev',
        'deploy_script' => 'कोड प्राप्त होने के बाद, आपके साइट उपयोगकर्ता के रूप में और इस साइट के अपने PHP संस्करण पर चलती है। बिल्ड कमांड उपयोग करने के लिए खाली छोड़ें।',
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
        'ensure_account' => 'सिस्टम खाता बनाया जा रहा है',
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
        'script' => 'डिप्लॉय स्क्रिप्ट चल रही है',
        'dependencies' => 'निर्भरताओं की जाँच',
        'verify' => 'साइट के उत्तर की जाँच',
        'verify_serving' => 'साइट के उत्तर की जाँच',
        'worker' => 'बैकग्राउंड प्रोसेस रुक गई',
    ],
    /*
    | Why provisioning failed, keyed by the `failed_reason` code on the
    | application. Only set where the exit status genuinely identifies
    | the cause; most failures carry the step and reference instead.
    */
    'site_type_change' => [
        'git_cannot_change' => 'यह साइट एक git रिपॉज़िटरी से डिप्लॉय होती है, इसलिए इसका प्रकार नहीं बदला जा सकता। इसकी डिप्लॉयमेंट, वर्कर और एनवायरनमेंट फ़ाइल स्क्रीनें इसी प्रकार के कारण मौजूद हैं, और उन्हें हटाने से न बैकग्राउंड वर्कर रुकेंगे और न डिप्लॉय वेबहुक पुश लेना बंद करेगा — सिर्फ़ उन्हें प्रबंधित करने वाली स्क्रीनें चली जाएँगी।',
        'git_not_a_target' => 'किसी साइट को git डिप्लॉयमेंट में नहीं बदला जा सकता। उसके लिए रिपॉज़िटरी, ब्रांच और डिप्लॉय स्क्रिप्ट चाहिए जिन्हें पैनल संभाले, और वे सर्वर पर पहले से मौजूद फ़ाइलों से नहीं बनाई जा सकतीं। इसके बजाय एक git एप्लिकेशन बनाएँ।',
        'unchanged' => 'यह साइट पहले से ही उसी प्रकार पर सेट है।',
        'not_suggestable' => 'इस साइट को उस प्रकार में नहीं बदला जा सकता। केवल वे एप्लिकेशन फिर से लेबल किए जा सकते हैं जिन्हें पैनल डिस्क पर पहचान सकता है — बाकी सब ऐसी सुविधाओं का दावा करेंगे जिनका साइट उपयोग नहीं कर सकती।',
        'only_from_generic' => 'किसी दूसरे एप्लिकेशन प्रकार में केवल कस्टम PHP या स्टैटिक साइट को फिर से लेबल किया जा सकता है। यह साइट पहले से एक निश्चित एप्लिकेशन पर सेट है, और एक एप्लिकेशन को दूसरे में बदलना लेबल से नहीं हो सकता।',
        'no_evidence' => 'इस साइट पर :type जैसा कुछ नहीं दिखता। पहले एप्लिकेशन अपलोड करें, फिर दोबारा Detect चलाएँ — पैनल साइट का प्रकार तभी बदलता है जब वह एप्लिकेशन को साइट की अपनी डायरेक्टरी में देख सके।',
    ],

    'failure_reason' => [
        'attached_database_engine_mismatch' => 'इस एप्लिकेशन के साथ पहले से एक डेटाबेस जुड़ा है, लेकिन वह ऐसे इंजन पर चलता है जिसे यह एप्लिकेशन उपयोग नहीं कर सकता। उसे अलग करें, या किसी समर्थित इंजन वाला डेटाबेस जोड़ें, और पुनः प्रयास करें।',
        'serving_error' => 'एप्लिकेशन शुरू हो गया लेकिन हर अनुरोध का उत्तर त्रुटि से देता है। संभवतः इसकी एसेट्स पूरी तरह बिल्ड नहीं हुईं — विवरण के लिए एप्लिकेशन लॉग देखें।',
        'not_answering' => 'एप्लिकेशन शुरू हो गया लेकिन किसी अनुरोध का उत्तर नहीं दिया। यह क्यों सुन नहीं रहा, यह जानने के लिए एप्लिकेशन लॉग देखें।',
        'out_of_memory' => 'इस चरण के दौरान सर्वर की मेमोरी समाप्त हो गई और सिस्टम ने इसे रोक दिया। कुछ मेमोरी खाली करें, या स्वैप जोड़ें, और पुनः प्रयास करें।',
        'no_build_tools' => 'इस चरण में एक नेटिव मॉड्यूल कंपाइल करना था, और इस सर्वर पर कोई कंपाइलर इंस्टॉल नहीं है। सेटअप स्क्रीन से बिल्ड टूल्स इंस्टॉल करें और फिर से कोशिश करें। कोई दूसरा Node संस्करण चुनना भी मदद कर सकता है, क्योंकि कुछ संस्करणों के लिए पहले से बनी बाइनरी होती हैं — लेकिन कौन से, यह हर पैकेज खुद तय करता है, इसलिए अकेले यह भरोसेमंद हल नहीं है।',
        'composer_platform' => 'इस साइट के लिए चुने गए PHP संस्करण पर Composer इस एप्लिकेशन की निर्भरताएँ इंस्टॉल नहीं कर सका। साइट का PHP संस्करण, या उसे चाहिए कोई एक्सटेंशन, प्रोजेक्ट की आवश्यकताओं को पूरा नहीं करता। साइट का PHP संस्करण किसी समर्थित संस्करण में बदलें, या छूटा हुआ एक्सटेंशन इंस्टॉल करें, और फिर से डिप्लॉय करें।',
        'composer_dependencies_missing' => 'इस प्रोजेक्ट को Composer निर्भरताएँ चाहिए और कोई भी इंस्टॉल नहीं हुई, इसलिए एप्लिकेशन में vendor/autoload.php नहीं है और उसका हर अनुरोध विफल होगा। डिप्लॉयमेंट स्क्रिप्ट में composer install चलाने वाला चरण जोड़ें और फिर से डिप्लॉय करें।',
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

    'supervisor_installing' => 'supervisor इंस्टॉल हो रहा है, जिस पर वर्कर्स चलते हैं। इसमें थोड़ा समय लगता है — पूरा होने पर वर्कर दोबारा बनाएँ।',

    'placeholder_page' => [
        'lede' => 'यह साइट तैयार है और चल रही है। इस पेज को अपने पेज से बदलें — तब तक हर विज़िटर इसे ही देखेगा।',
        'php_running' => 'इस साइट पर PHP चल रहा है',
        'step_files_title' => 'अपनी फ़ाइलें अपलोड करें',
        'step_files_body' => 'पैनल के फ़ाइल मैनेजर का उपयोग करें, या इस साइट के सिस्टम उपयोगकर्ता से SFTP द्वारा जुड़ें।',
        'step_deploy_title' => 'या git से डिप्लॉय करें',
        'step_deploy_body' => 'साइट को किसी रिपॉज़िटरी से जोड़ें और पैनल हर पुश पर उसे खींचकर बनाएगा।',
        'foot' => 'कंट्रोल पैनल द्वारा बनाया गया प्लेसहोल्डर पेज।',
    ],

    'disabled_page' => [
        'title' => 'साइट अनुपलब्ध',
        'heading' => 'यह साइट अस्थायी रूप से अनुपलब्ध है',
        'lede' => 'इसे इसके स्वामी ने ऑफ़लाइन कर दिया है। कृपया बाद में पुनः प्रयास करें।',
        'foot' => 'कंट्रोल पैनल द्वारा सर्व किया गया।',
    ],
];
