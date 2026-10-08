<?php

return [
    'operation_failed' => 'सर्वर पर डेटाबेस ऑपरेशन विफल रहा।',
    'export_already_running' => 'इस डेटाबेस का एक निर्यात पहले से चल रहा है। दूसरा शुरू करने से पहले उसके पूरा होने की प्रतीक्षा करें।',
    'collation_mismatch' => 'चयनित कोलेशन चुने गए वर्ण सेट से संबंधित नहीं है।',
    'application_already_attached' => ':application से पहले से ही :database डेटाबेस जुड़ा है। पहले उसे अलग करें, या इस डेटाबेस को किसी दूसरे एप्लिकेशन से जोड़ें।',
    'engine_not_accepted' => ':application :engine डेटाबेस का उपयोग नहीं कर सकता। यह :accepted स्वीकार करता है।',
    'engine_not_installable' => 'पैनल यह डेटाबेस इंजन अभी इंस्टॉल नहीं कर सकता। इसे स्वयं इंस्टॉल करें और पैनल इसे पहचान लेगा।',
    // The vendor publishes nothing for this Ubuntu release. Refused
    // before the install rather than discovered two minutes into apt.
    'engine_os_unsupported' => ':engine ने अभी :os के लिए पैकेज प्रकाशित नहीं किए हैं, इसलिए पैनल इसे यहाँ इंस्टॉल नहीं कर सकता। इस सर्वर में कोई गड़बड़ी नहीं है — पैनल :os को सपोर्ट करता है, पर :engine ने इसके लिए बिल्ड जारी नहीं किया है। कोई दूसरा डेटाबेस इंजन चुनें, या बाद में दोबारा कोशिश करें।',
    'phpmyadmin_engine_not_supported' => 'phpMyAdmin :engine डेटाबेस का समर्थन नहीं करता।',
    'phpmyadmin_not_deployed' => 'इस सर्वर पर कोई phpMyAdmin एप्लिकेशन इंस्टॉल नहीं है।',
    'phpmyadmin_no_users' => 'phpMyAdmin एक्सेस करने से पहले एक डेटाबेस उपयोगकर्ता बनाएं।',
    'remote_users_unsupported' => ':engine के लिए रिमोट एक्सेस उपलब्ध नहीं है — इसके खाते किसी होस्ट से बंधे नहीं होते। localhost का उपयोग करें।',
    // 409, not 422: the request is fine, the cluster is not ready. The
    // client re-sends with restart_cluster as explicit consent.
    'remote_access_restart_required' => 'रिमोट कनेक्शन चालू करने के लिए :engine को रीस्टार्ट करना होगा, क्योंकि जिस पते पर यह सुनता है वह केवल शुरू होते समय बदला जा सकता है। इस डेटाबेस का उपयोग करने वाले एप्लिकेशन कुछ पल के लिए कनेक्शन खो देंगे। रीस्टार्ट करके आगे बढ़ने के लिए पुष्टि करें।',
    'phpmyadmin_not_selectable' => 'चयनित एप्लिकेशन एक सक्रिय phpMyAdmin इंस्टॉलेशन नहीं है।',
    'phpmyadmin_user_not_found' => 'निर्दिष्ट डेटाबेस उपयोगकर्ता इस डेटाबेस से संबंधित नहीं है।',
    'phpmyadmin_not_isolated' => 'यह phpMyAdmin एप्लिकेशन सर्वर-व्यापी PHP पूल साझा करती है, इसलिए साइन-इन लिंक हर दूसरी एप्लिकेशन पढ़ सकेगी। इसे अपना PHP पूल दें, या phpMyAdmin खोलकर डेटाबेस क्रेडेंशियल से साइन इन करें।',
    'phpmyadmin_requires_https' => 'इस phpMyAdmin एप्लिकेशन पर HTTPS नहीं है, इसलिए साइन-इन लिंक और डेटाबेस सत्र बिना एन्क्रिप्शन के भेजे जाएँगे। पहले इसके लिए SSL प्रमाणपत्र जारी करें।',
    'user_exists' => '":username" नाम का डेटाबेस उपयोगकर्ता पहले से मौजूद है। कोई दूसरा नाम चुनें।',
    'phpmyadmin_sso_unavailable' => 'phpMyAdmin एप्लिकेशन पर साइन-इन लिंक तैयार नहीं किया जा सका।',
    'remote_host_invalid' => 'IPv4 पता या रेंज दर्ज करें, जैसे 203.0.113.5 या 203.0.113.0/24।',
    'remote_host_not_remote' => 'यह पता दूरस्थ नहीं है। इस सर्वर के लिए “लोकल” या सभी पतों के लिए “कहीं से भी” चुनें।',
    'engine_unreachable' => ':engine जवाब नहीं दे रहा है, इसलिए पैनल यह डेटाबेस नहीं पढ़ सकता। सेवाएँ पेज पर :engine शुरू करें और फिर से कोशिश करें।',
    'panel_user_protected' => '“:username” वह खाता है जिससे पैनल खुद डेटाबेस प्रबंधित करता है। इसे यहाँ बदला या हटाया नहीं जा सकता।',
    'export_in_progress' => 'यह एक्सपोर्ट अभी चल रहा है। इसके पूरा होने तक प्रतीक्षा करें, फिर इसे हटाएँ।',
    'engine_install_in_progress' => ':engine पहले से इंस्टॉल हो रहा है। उस इंस्टॉल के पूरा होने तक प्रतीक्षा करें।',
    'panel_process_protected' => 'यह पैनल का अपना कनेक्शन है। इसे रोकने से पैनल जो कर रहा है वह विफल हो जाएगा।',
];
