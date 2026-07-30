<?php

return [
    'summary' => ':source から :ports を:action',
    'anywhere' => 'どこからでも',
    'actions' => [
        'allow' => '許可',
        'deny' => '拒否',
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
        'custom' => 'カスタムポート',
    ],

    'risky' => [
        'database' => ':engine はデータベースです。インターネットから到達可能な場合、データへの侵入口になります。',
        'service' => ':service はインターネットから到達できるべきではありません。',
    ],
];
