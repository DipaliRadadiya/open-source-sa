<?php

return [
    'summary' => ':action :ports from :source',
    'anywhere' => 'Anywhere',
    'actions' => [
        'allow' => 'Allow',
        'deny' => 'Deny',
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
        'custom' => 'Custom port',
    ],

    'risky' => [
        'database' => ':engine is a database — reachable from the internet, it is a way into your data.',
        'service' => ':service should not be reachable from the internet.',
    ],
];
