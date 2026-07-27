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
];
