<?php

return [
    'none' => 'कोई नहीं',
    'yes' => 'हाँ',
    'no' => 'नहीं',
    // The server default PHP, where a cron job switched off plain `php`.
    'php_default' => 'डिफ़ॉल्ट',
    'adopted_left_unexpected_path' => 'इसका दर्ज स्थान ऐसा नहीं है जिसे पैनल हटाए',
    'adopted_left_other_site' => 'यह किसी दूसरी साइट या सर्वर का है',
    'adopted_left_not_this_site' => 'यह अब केवल इस साइट को सर्व नहीं करता',
    'adopted_left_unprovable_config' => 'यह इस साइट को सर्व करने से अधिक करता है, ऐसे तरीके से जिसे पैनल जाँच नहीं सकता — बिना नाम का ब्लॉक, डिफ़ॉल्ट सर्वर, include, या साइट के बाहर का alias',
    'adopted_left_not_regular' => 'यह एक सामान्य फ़ाइल नहीं है',
    'adopted_left_foreign_link' => 'वेब सर्वर इसे ऐसे लिंक या कॉपी से लोड करता है जिसकी पैनल को उम्मीद नहीं थी',
    'adopted_left_unreadable' => 'इसे पढ़ा नहीं जा सका',
    'adopted_left_config_test_failed' => 'इसके बिना वेब सर्वर ने अपना कॉन्फ़िगरेशन अस्वीकार कर दिया, इसलिए इसे वापस रखा गया',
    'adopted_left_remove_failed' => 'इसे हटाया नहीं जा सका, इसलिए सब कुछ वापस रखा गया',
    'adopted_left_restore_failed' => 'इसके बिना वेब सर्वर ने अपना कॉन्फ़िगरेशन अस्वीकार कर दिया और इसे वापस रखना भी विफल रहा — अभी वेब सर्वर कॉन्फ़िगरेशन जाँचें',
    'adopted_left_ols_shared' => 'यह OpenLiteSpeed के साझा कॉन्फ़िगरेशन में दर्ज है, जिसे पैनल संपादित नहीं करता',
    'adopted_left_worker_shared' => 'यह एक साझा प्रोग्राम फ़ाइल का वर्कर है जिसका स्वामित्व पैनल साबित नहीं कर सकता, इसलिए यह चलता रहा',
    'adopted_left_worker_systemd' => 'यह एक systemd सेवा है जिसका स्वामित्व पैनल साबित नहीं कर सकता, इसलिए यह चलती रही',
    // The activity log's "what changed" line (2026-10-28).
    'on' => 'चालू',
    'off' => 'बंद',
    'with_changes' => ':sentence: :changes',
    'unchanged' => ':sentence (कुछ नहीं बदला)',
    'change' => ':field :from → :to',
    'change_secret' => ':field बदला गया',
    'change_secret_removed' => ':field हटाया गया',
];
