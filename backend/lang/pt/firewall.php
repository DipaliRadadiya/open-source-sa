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

    'risky' => [
        'database' => ':engine é uma base de dados — acessível a partir da internet, é uma porta de entrada para os seus dados.',
        'service' => ':service não deveria estar acessível a partir da internet.',
    ],
];
