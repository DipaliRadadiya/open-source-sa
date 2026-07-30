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

    'risky' => [
        'database' => ':engine est une base de données : accessible depuis internet, c\'est une porte d\'entrée vers vos données.',
        'service' => ':service ne devrait pas être accessible depuis internet.',
    ],
];
