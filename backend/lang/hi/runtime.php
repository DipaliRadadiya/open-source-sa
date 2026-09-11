<?php

return [

    /*
    | Why an install failed, keyed by the `reason` code stored on the
    | install row. Built at read time in the *viewer's* locale — the
    | raw apt or fnm output is never shown, only referenced.
    */

    'install_failed' => [
        'package_not_found' => 'इस सर्वर के पैकेज स्रोतों में :version के लिए कोई पैकेज नहीं है।',
        'apt_lock' => 'एक अन्य पैकेज कार्य पहले से चल रहा है। थोड़ी देर बाद फिर कोशिश करें।',
        'network' => 'पैकेज रिपॉज़िटरी तक नहीं पहुँचा जा सका। सर्वर की नेटवर्क पहुँच जाँचें।',
        'no_space' => 'सर्वर पर डिस्क स्थान समाप्त हो गया है।',
        'worker' => 'इंस्टॉल अप्रत्याशित रूप से रुक गया। समय समाप्त हो सकता है — फिर कोशिश करें।',
        'unknown' => 'इंस्टॉल विफल रहा। नीचे दिया गया संदर्भ सहायता को बताएं।',
        'dpkg_broken' => 'कुछ और इंस्टॉल करने से पहले इस सर्वर के पैकेज डेटाबेस की मरम्मत करनी होगी।',
        'port_in_use_by_mysql' => 'MySQL पहले से इंस्टॉल है और यह पोर्ट उपयोग कर रहा है। पहले उसे हटाएँ, या उसी का उपयोग जारी रखें।',
        'port_in_use_by_mariadb' => 'MariaDB पहले से इंस्टॉल है और यह पोर्ट उपयोग कर रहा है। पहले उसे हटाएँ, या उसी का उपयोग जारी रखें।',
        'root_unreachable' => 'इंस्टॉल हो गया लेकिन पैनल उसमें साइन इन नहीं कर सका। एडमिन लॉगिन डिफ़ॉल्ट से बदला गया है, इसलिए आगे बढ़ने के लिए पैनल को वह जानकारी चाहिए।',
        'cluster_missing' => 'PostgreSQL इंस्टॉल है लेकिन इस सर्वर पर कोई क्लस्टर मौजूद नहीं है। हो सकता है इसे हटा दिया गया हो, या इसका सेटअप पूरा न हुआ हो।',
        'grant_failed' => 'इंस्टॉल हो गया लेकिन पैनल उसमें अपना खाता नहीं बना सका।',
        'repository_failed' => 'MongoDB पैकेज रिपॉज़िटरी नहीं जोड़ी जा सकी। जाँचें कि सर्वर repo.mongodb.org तक पहुँच सकता है।',
        'unreachable' => 'यह स्थापित हो गया पर उत्तर नहीं दे रहा। नीचे दिया संदर्भ सहायता को बताएँ।',
        'auth_required' => 'यहाँ MongoDB पहले से स्थापित है और ऐसा साइन-इन माँगता है जो पैनल के पास नहीं है। कनेक्शन सेटिंग्स में उसके क्रेडेंशियल जोड़ें और फिर प्रयास करें।',
        'auth_config_present' => 'MongoDB स्थापित है और उसकी कॉन्फ़िगरेशन में पहले से security अनुभाग है। पैनल ने उसे नहीं छुआ — वहाँ स्वयं authorization सक्षम करें, फिर प्रयास करें।',
        'auth_failed' => 'यह स्थापित हो गया पर प्रमाणीकरण चालू नहीं हो सका। नीचे दिया संदर्भ सहायता को बताएँ।',
    ],

    'uninstall_failed' => [
        'failed' => 'PHP :version हटाया नहीं जा सका। नीचे दिया गया संदर्भ सहायता को बताएं।',
        'worker' => 'PHP :version हटाना अप्रत्याशित रूप से रुक गया। समय समाप्त हो सकता है — फिर कोशिश करें।',
        'unknown' => 'PHP :version हटाया नहीं जा सका। नीचे दिया गया संदर्भ सहायता को बताएं।',
    ],

    'extension_install_failed' => [
        'package_not_found' => 'PHP :version पर :extension के लिए कोई पैकेज नहीं है। इस संस्करण के लिए यह उपलब्ध नहीं हो सकता।',
        'apt_lock' => 'एक अन्य पैकेज कार्य पहले से चल रहा है। थोड़ी देर बाद फिर कोशिश करें।',
        'network' => 'पैकेज रिपॉज़िटरी तक नहीं पहुँचा जा सका। सर्वर की नेटवर्क पहुँच जाँचें।',
        'no_space' => 'सर्वर पर डिस्क स्थान समाप्त हो गया है।',
        'worker' => ':extension का इंस्टॉल अप्रत्याशित रूप से रुक गया। समय समाप्त हो सकता है — फिर कोशिश करें।',
        'unknown' => ':extension इंस्टॉल विफल रहा। नीचे दिया गया संदर्भ सहायता को बताएं।',
        'enable_failed' => ':extension इंस्टॉल हो गया लेकिन चालू नहीं किया जा सका। टॉगल फिर से आज़माएँ।',
    ],

    'fail2ban_install_failed' => [
        'package_not_found' => 'fail2ban का कोई पैकेज उपलब्ध नहीं है। जाँचें कि सर्वर के पैकेज स्रोत कॉन्फ़िगर और पहुँच-योग्य हैं।',
        'apt_lock' => 'पैकेज का एक और काम पहले से चल रहा है। थोड़ी देर बाद फिर कोशिश करें।',
        'network' => 'पैकेज रिपॉज़िटरी तक नहीं पहुँचा जा सका। जाँचें कि सर्वर के पास नेटवर्क पहुँच है।',
        'no_space' => 'सर्वर पर डिस्क स्थान समाप्त हो गया है।',
        'worker' => 'इंस्टॉल अप्रत्याशित रूप से रुक गया। शायद समय समाप्त हो गया — फिर कोशिश करें।',
        'unknown' => 'fail2ban इंस्टॉल करना विफल रहा। नीचे दिया संदर्भ सपोर्ट को बताएं।',
    ],


    /*
    | Per-runtime overrides, consulted before the shared groups above.
    |
    | `install_failed` is shared by PHP, Node, database engines and
    | fail2ban. It used to be worded for PHP alone, so a failed MongoDB
    | install told the user to check the PHP repository. Only the reasons
    | that genuinely differ per runtime belong here; everything else
    | still falls through.
    */

    'php_install_failed' => [
        'package_not_found' => ':version के लिए कोई पैकेज नहीं है। जाँचें कि PHP रिपॉज़िटरी कॉन्फ़िगर और उपलब्ध है।',
    ],

    'node_install_failed' => [
        'package_not_found' => 'Node :version नहीं मिला। वर्शन नंबर जाँचें, या सूची में से कोई चुनें।',
    ],

    'database_install_failed' => [
        'package_not_found' => 'इस सर्वर पर :version के लिए कोई पैकेज नहीं है। जाँचें कि उसकी पैकेज रिपॉज़िटरी कॉन्फ़िगर और उपलब्ध है।',
        // The repository was added and its index fetched successfully;
        // the engine simply has no build for this Ubuntu release.
        'os_unsupported' => ':version ने अभी :os के लिए पैकेज प्रकाशित नहीं किए हैं। इस सर्वर में कोई गड़बड़ी नहीं है — पैनल :os को सपोर्ट करता है, पर :version ने इसके लिए बिल्ड जारी नहीं किया है। कोई दूसरा डेटाबेस इंजन चुनें, या बाद में दोबारा कोशिश करें।',
    ],

    // Used for :os when /etc/os-release cannot be read.
    'this_server' => 'इस सर्वर का ऑपरेटिंग सिस्टम',
];
