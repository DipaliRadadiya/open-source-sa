<?php

/*
 * Node feature errors.
 */

return [
    'not_installed' => 'Node :version स्थापित नहीं है।',
    'version_in_use' => 'Node :version का उपयोग :apps कर रहे हैं। पहले उन साइटों को बदलें।',
    'version_is_default' => 'यह डिफ़ॉल्ट संस्करण है। पहले कोई अन्य चुनें।',
    'npm_target_unknown' => 'npm रिलीज़ सूची तक नहीं पहुँचा जा सका, इसलिए यह नहीं बताया जा सकता कि यह Node संस्करण कौन-सा npm चला सकता है। सर्वर के पास इंटरनेट पहुँच होने पर पुनः प्रयास करें, या `php artisan runtimes:refresh-npm` चलाएँ।',
];
