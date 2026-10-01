<?php

return [

    // Where one deploy got to. Shown as a badge on every row.
    'status' => [
        'queued' => 'कतार में',
        'running' => 'चल रहा है',
        'succeeded' => 'सफल',
        'failed' => 'विफल',
    ],

    // What started it. "Push" rather than "Webhook" because the user
    // thinks in terms of what they did, not how it reached us.
    'trigger' => [
        'manual' => 'मैनुअल',
        'webhook' => 'पुश',
        'redeploy' => 'पुनः चलाया',
        'initial' => 'पहला डिप्लॉय',
    ],

    'script_php_missing' => 'आपकी डिप्लॉय स्क्रिप्ट :variables का उपयोग करती है, लेकिन इस सर्वर पर PHP :versions इंस्टॉल नहीं है। इसे PHP स्क्रीन पर इंस्टॉल करें, या साइट के अपने वर्ज़न के लिए {php} का उपयोग करें।',

];
