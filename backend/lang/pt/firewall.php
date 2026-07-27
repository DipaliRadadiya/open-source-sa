<?php

return [
    'summary' => ':action :ports de :source',
    'anywhere' => 'Qualquer origem',
    'actions' => [
        'allow' => 'Permitir',
        'deny' => 'Negar',
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
        'custom' => 'Porta personalizada',
    ],
];
