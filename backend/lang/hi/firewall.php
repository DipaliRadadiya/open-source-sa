<?php

return [
    'summary' => ':source से :ports :action',
    'anywhere' => 'कहीं से भी',
    'actions' => [
        'allow' => 'अनुमति दें',
        'deny' => 'अस्वीकार करें',
    ],
    'presets' => [
        'ssh' => 'SSH',
        'http' => 'HTTP',
        'https' => 'HTTPS',
        'mysql' => 'MySQL',
        'postgresql' => 'PostgreSQL',
        'redis' => 'Redis',
        'ftp' => 'FTP',
        'smtp' => 'SMTP',
        'dns' => 'DNS',
        'custom' => 'कस्टम पोर्ट',
    ],

    'risky' => [
        'database' => ':engine एक डेटाबेस है — इंटरनेट से पहुँच योग्य होने पर यह आपके डेटा तक का रास्ता है।',
        'service' => ':service इंटरनेट से पहुँच योग्य नहीं होना चाहिए।',
    ],
];
