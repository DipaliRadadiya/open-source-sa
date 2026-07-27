<?php

return [
    'summary' => ':action :ports desde :source',
    'anywhere' => 'Cualquier origen',
    'actions' => [
        'allow' => 'Permitir',
        'deny' => 'Denegar',
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
        'custom' => 'Puerto personalizado',
    ],
];
