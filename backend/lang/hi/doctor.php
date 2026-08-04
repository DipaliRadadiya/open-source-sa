<?php

return [
    'checks' => [
        'privilege' => 'विशेषाधिकार वाले कमांड',
        'services' => 'सेवाएँ',
        'writable_paths' => 'लिखने योग्य पथ',
        'database' => 'डेटाबेस',
        'health_endpoint' => 'हेल्थ एंडपॉइंट',
    ],
    'fixes' => [
        'privilege' => 'पैनल root के रूप में कमांड नहीं चला सकता। जाँचें कि /etc/sudoers.d/ में पैनल की अनुमति है और फ़ाइल visudo -c पास करती है।',
        'privilege_disabled' => 'विशेषाधिकार वृद्धि बंद है पर पैनल root नहीं है। .env से SERVER_OPS_SUDO=false हटाएँ।',
        'services_missing' => 'अपेक्षित यूनिट मौजूद नहीं है। .env में PANEL_FRONTEND_SERVICE और PANEL_QUEUE_SERVICE को इस सर्वर के वास्तविक नामों पर सेट करें।',
        'services_down' => 'उन्हें systemctl start से शुरू करें, फिर journalctl -u <unit> देखें।',
        'writable_paths' => 'पैनल खाते को स्वामित्व दें: ऊपर दिए पथों पर chown -R <panel user> चलाएँ।',
        'database_unreachable' => '.env में DB_ सेटिंग्स और डेटाबेस सेवा चल रही है या नहीं, जाँचें।',
        'database_pending' => 'php artisan migrate --force चलाएँ। कोड अपडेट हुआ पर स्कीमा परिवर्तन लागू नहीं हुए।',
        'health_unreachable' => 'जाँचें कि .env में APP_URL वही पता है जहाँ पैनल परोसा जाता है, और वेब सर्वर तथा php-fpm चल रहे हैं।',
        'health_version_mismatch' => 'चल रहा कोड और परोसा गया संस्करण भिन्न हैं। php artisan optimize:clear से कैश साफ़ करें और php-fpm पुनः लोड करें।',
    ],
];
