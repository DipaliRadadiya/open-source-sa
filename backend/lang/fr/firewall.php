<?php

return [
    'summary' => ':action :ports depuis :source',
    'anywhere' => "N'importe où",
    'actions' => [
        'allow' => 'Autoriser',
        'deny' => 'Refuser',
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
        'custom' => 'Port personnalisé',
    ],
];
