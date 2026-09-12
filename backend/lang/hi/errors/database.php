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
    'phpmyadmin_not_deployed' => 'इस सर्वर पर कोई phpMyAdmin साइट इंस्टॉल नहीं है।',
    'phpmyadmin_no_users' => 'phpMyAdmin एक्सेस करने से पहले एक डेटाबेस उपयोगकर्ता बनाएं।',
    'remote_users_unsupported' => ':engine के लिए रिमोट एक्सेस उपलब्ध नहीं है — इसके खाते किसी होस्ट से बंधे नहीं होते। localhost का उपयोग करें।',
    // 409, not 422: the request is fine, the cluster is not ready. The
    // client re-sends with restart_cluster as explicit consent.
    'remote_access_restart_required' => 'रिमोट कनेक्शन चालू करने के लिए PostgreSQL को पुनः आरंभ करना होगा, क्योंकि इसका लिसनिंग पता केवल स्टार्टअप पर ही बदला जा सकता है। इस डेटाबेस का उपयोग करने वाले ऐप्लिकेशन कुछ पलों के लिए कनेक्शन खो देंगे। आगे बढ़ने के लिए अनुरोध को restart_cluster के साथ दोबारा भेजें।',
    'phpmyadmin_not_selectable' => 'चयनित साइट एक सक्रिय phpMyAdmin इंस्टॉलेशन नहीं है।',
    'phpmyadmin_user_not_found' => 'निर्दिष्ट डेटाबेस उपयोगकर्ता इस डेटाबेस से संबंधित नहीं है।',
    'phpmyadmin_not_isolated' => 'यह phpMyAdmin साइट सर्वर-व्यापी PHP पूल साझा करती है, इसलिए साइन-इन लिंक हर दूसरी साइट पढ़ सकेगी। इसे अपना PHP पूल दें, या phpMyAdmin खोलकर डेटाबेस क्रेडेंशियल से साइन इन करें।',
    'phpmyadmin_sso_unavailable' => 'phpMyAdmin साइट पर साइन-इन लिंक तैयार नहीं किया जा सका।',

];
