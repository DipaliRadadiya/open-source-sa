<?php

/*
 * Copy for the Storage destinations integration — S3-compatible remote
 * targets that backups are uploaded to. The keys in this file render the
 * connect form, the row labels and the outcome of the test-connection probe.
 */

return [
    'drivers' => [
        's3' => 'S3-संगत',
    ],

    'fields' => [
        'name' => 'प्रदर्शित नाम',
        'endpoint' => 'एंडपॉइंट URL',
        'region' => 'क्षेत्र',
        'bucket' => 'बकेट',
        'prefix' => 'की उपसर्ग (वैकल्पिक)',
        'access_key' => 'एक्सेस कुंजी',
        'secret_key' => 'गुप्त कुंजी',
    ],

    'placeholders' => [
        'endpoint' => 'https://s3.amazonaws.com',
        'region' => 'us-east-1',
        'prefix' => 'backups/production/',
    ],

    'help' => [
        'name' => 'एक छोटा लेबल ताकि एकीकरण सूची में गंतव्यों को अलग पहचाना जा सके।',
        'endpoint' => 'AWS के लिए डिफ़ॉल्ट रहने दें। MinIO, R2, Backblaze B2, Wasabi आदि के लिए सेट करें।',
        'region' => 'वह क्षेत्र जहाँ बकेट है (केवल AWS के लिए आवश्यक)।',
        'prefix' => 'बकेट के भीतर वैकल्पिक पथ उपसर्ग (शुरुआती स्लैश के बिना)।',
        'access_key' => 'केवल लिखने योग्य — API इसे कभी नहीं लौटाता।',
    ],

    'status' => [
        'connected' => 'कनेक्टेड',
        'never_tested' => 'अभी तक परीक्षण नहीं किया गया',
        'failed' => 'पिछला परीक्षण विफल रहा',
    ],

    'test' => [
        'success' => 'कनेक्शन सफल रहा।',
        'failure' => 'गंतव्य से कनेक्ट नहीं हो सका।',
        'invalid_credentials' => 'गंतव्य ने क्रेडेंशियल अस्वीकार कर दिए।',
        'unreachable' => 'गंतव्य के एंडपॉइंट तक नहीं पहुँचा जा सका।',
        'mismatch' => 'गंतव्य ने लिखे गए बाइट्स से भिन्न बाइट्स लौटाए।',
        'forbidden_host' => 'यह एंडपॉइंट पता अनुमत नहीं है।',
        'invalid_endpoint' => 'बकेट के लिए एक मान्य https:// एंडपॉइंट URL दर्ज करें।',
    ],

    'delete' => [
        'in_use' => ':name को हटाया नहीं जा सकता — इसका उपयोग अभी भी :applications द्वारा किया जा रहा है। पहले उन बैकअप लक्ष्यों को हटाएँ या पुनर्निर्देशित करें।',
        'and_more' => ':count और',
    ],
];
