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

    'risky' => [
        'database' => ':engine es una base de datos: accesible desde internet, es una vía de entrada a sus datos.',
        'service' => ':service no debería ser accesible desde internet.',
    ],
];
