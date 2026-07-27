<?php

return [
    'summary' => ':action :ports от :source',
    'anywhere' => 'Откуда угодно',
    'actions' => [
        'allow' => 'Разрешить',
        'deny' => 'Запретить',
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
        'custom' => 'Пользовательский порт',
    ],
];
